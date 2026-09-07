# Deploying to the test server

CI runs on every push and pull request. A push to `master` that passes deploys
itself. Nothing else deploys — there is no manual "deploy this branch" path on
purpose, because a test server that can hold code nobody tested is not a test of
anything.

The pipeline is [`.github/workflows/ci.yml`](../.github/workflows/ci.yml); the
half that runs on the server is [`release.sh`](release.sh), which you can also
run by hand.

---

## 1. What the server needs, once

Ubuntu with nginx, php-fpm, MySQL 8 and redis. Nothing here is repeated by the
pipeline, so it has to be right before the first deploy.

```bash
sudo apt install -y nginx mysql-server redis-server \
  php-fpm php-cli php-mysql php-bcmath php-mbstring \
  php-intl php-gd php-zip php-curl php-xml php-redis
```

**Unversioned on purpose.** `php8.3-*` exists on 24.04 and not on 26.04, whose
archive has no 8.3 at all; the metapackages pull whatever that release's default
PHP is. Check what you got, because two other things have to agree with it:

```bash
php -v
```

- **CI's `php-version`** in `.github/workflows/ci.yml` should be the same, or the
  vendor tree is resolved and the suite is run against a PHP the server does not
  have. `composer.json` requires `^8.3`, so 8.4 and 8.5 satisfy it — which means
  a mismatch will not fail, it will simply go untested.
- **The nginx socket path** below (`/run/php/phpX.Y-fpm.sock`).

`release.sh` needs no telling: it finds the `php*-fpm` unit itself.

Two of those are not optional, in different ways.

`php-bcmath`: every money figure the server computes goes through bcmath,
because floats do not reconcile (FR-MON-4).

`php-cli` is listed separately from `-fpm` because it is a separate package
and fpm does not pull it in. Without it the deploy uploads perfectly and then
dies on `php: command not found`, since every step of `release.sh` is an artisan
command.

`redis` **is part of the isolation boundary**, not a performance choice. Tenant
cache scoping is implemented with cache *tags*, and the `database` and `file`
drivers do not support them — a non-taggable store would serve one association's
cached figures to another, silently, with no error (threat T-2). The application
refuses to boot on one, so getting this wrong fails the deploy rather than
leaking — but it fails at the last step, after the migrations have already run.

### Directory layout

```
/var/www/bcs
├── current -> releases/<sha>     the symlink nginx serves
├── releases/                     the last 5, for rollback
└── shared/
    ├── .env                      never written by CI
    └── storage/                  members' uploaded documents live here
```

```bash
sudo mkdir -p /var/www/bcs/{releases,shared/storage}
sudo chown -R deploy:www-data /var/www/bcs
```

`shared/storage` needs Laravel's own subdirectories, or the first request fails
on a missing cache path:

```bash
cd /var/www/bcs/shared/storage
mkdir -p app/public framework/{cache/data,sessions,testing,views} logs
sudo chown -R deploy:www-data . && sudo chmod -R 775 .
```

### `.env`, by hand, exactly once

Write `/var/www/bcs/shared/.env` yourself. The release deliberately carries no
`.env.example` — nothing that looks like an environment file is ever uploaded,
so there is nothing to accidentally rename into place. Take the full list of
keys from `.env.example` in the repo; the minimum for this server is below.

Generate the key first, with no application to run it from, and paste the value
into `APP_KEY`:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

> **The one thing that cannot be undone here.** Every association's gateway
> credentials are encrypted with `APP_KEY`. Regenerating it does not lose them —
> it makes them permanently unreadable, leaving rows that look perfectly fine
> until a member tries to pay. `release.sh` refuses to run if this file is
> missing rather than creating one, and CI never writes it. Keep a copy of the
> key somewhere that is not this server.

Set at minimum:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://test.example.org

DB_CONNECTION=mysql
DB_DATABASE=bcs_central

# Not `database`. See above — this is the one the app refuses to boot on.
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

# Nothing is collected until somebody deliberately says otherwise (ADR-0010).
PAYMENT_GATEWAY=fake
```

`APP_DEBUG=false` matters more here than it looks: a stack trace on a test
server still names tables, queries and file paths for a real association's data.

### The database user

The platform creates a **database per association** (ADR-0001), and
`PermissionControlledMySQLDatabaseManager` also creates a MySQL **user per
association**, scoped to that one database. So the central user needs more than
rights on its own schema — it needs to create databases, create users, and grant
those users rights, which is what `WITH GRANT OPTION` is for:

```sql
-- The central registry. `migrate --force` creates the tables in it; it does not
-- create the database, and non-interactively it cannot offer to.
CREATE DATABASE bcs_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'bcs'@'localhost' IDENTIFIED BY '…';
GRANT ALL PRIVILEGES ON *.* TO 'bcs'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

Only the central database is made by hand. Every association's is created by
`tenant:provision`, which is why the user above needs rights across the server
rather than on one schema.

Without `WITH GRANT OPTION`, provisioning creates the database and then fails
handing the new user its rights — half a tenant, which is the messiest way for
this to go wrong.

### One sudoers line

The release script reloads php-fpm, because opcache keeps serving the previous
release out of memory otherwise:

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
```

Use whatever unit `systemctl list-units 'php*-fpm.service'` reports — the version
follows the PHP you installed above.

That is the only privilege the deploy user needs. It is deliberately one exact
command rather than `NOPASSWD: ALL`. If the deploy user is root, skip this
entirely: `release.sh` reloads directly when it is already root.

### nginx

Two vhosts, on ports rather than hostnames: `nginx/bcs-api.conf` serves the API
and console on **:9000**, `nginx/bcs-app.conf` serves the app on **:9001**.
Ports because telling two vhosts apart by `server_name` needs DNS pointed here,
and ports need nothing — this works on a bare IP today and moves to names and
TLS later without the deploy changing at all.

9000 is php-fpm's own default TCP port. Debian and Ubuntu ship a unix socket
instead, so it is normally free — but check before reloading, because a clash
takes both vhosts down rather than one:

```bash
ss -lntp | grep -E ':900[01]'
```

```bash
cp /var/www/bcs/current/deploy/nginx/bcs-api.conf /etc/nginx/sites-available/
cp /var/www/bcs/current/deploy/nginx/bcs-app.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/bcs-api.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/bcs-app.conf /etc/nginx/sites-enabled/

# Set the socket to match your PHP before this passes.
ls /run/php/
nginx -t && systemctl reload nginx
```

If a firewall is on: `ufw allow 9000/tcp && ufw allow 9001/tcp`.

Everything else has to agree with the ports:

| Where | Setting | Value |
|---|---|---|
| `shared/.env` | `APP_URL` | `http://<host>:9000` |
| bcs-app-rn variables | `BCS_API_URL` | `http://<host>:9000/api/v1` |
| bcs-app-rn variables | `WEB_ROOT` | `/var/www/bcs-app` |

`BCS_API_URL` is compiled into the bundle, so changing it means re-running the
app workflow — there is no runtime override.

### Upload sizes, in three places

A member may attach five 8 MB documents to one payment, so a legitimate request
reaches about 40 MB. Three limits sit in front of that and the smallest wins;
the two defaults both reject it, and neither says anything useful to the member.

- nginx: `client_max_body_size 48m` — already in `bcs-api.conf`. Its default of
  1 MB rejects the request with a 413 before PHP ever sees it.
- PHP: defaults are `upload_max_filesize = 2M` and `post_max_size = 8M`. Put
  this in `/etc/php/<version>/fpm/conf.d/99-bcs.ini` and reload fpm:

  ```ini
  upload_max_filesize = 8M
  post_max_size = 48M
  ```

- The application: `PaymentDocumentService::MAX_BYTES` (8 MB) and
  `MAX_PER_PAYMENT` (5). This is the one that produces a sentence a member can
  act on, so it should stay the *smallest* of the three — raise the other two
  above it, never to exactly it.

### The scheduler and the queue

Both are load-bearing, and both are silent when missing — the symptom is a
member who paid and stays "pending" forever.

```cron
* * * * * cd /var/www/bcs/current && php artisan schedule:run >> /dev/null 2>&1
```

That one line runs `payments:reconcile` every ten minutes, which is the second
completion path for an online payment whose callback never arrived (ADR-0007),
and `payments:expire-intents` hourly, which releases abandoned intents.

A queue worker under systemd (`/etc/systemd/system/bcs-queue.service`):

```ini
[Unit]
Description=BCS queue worker
After=network.target mysql.service

[Service]
User=deploy
Restart=always
WorkingDirectory=/var/www/bcs/current
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
# WorkingDirectory is the `current` SYMLINK, so a restarted worker picks up the
# release that is live at that moment rather than the one live when it started.

[Install]
WantedBy=multi-user.target
```

`release.sh` calls `queue:restart`, which asks the worker to finish its current
job and exit; systemd starts it again on the new code.

---

## 2. What GitHub needs

Under **Settings → Environments → `test`** (create it; the deploy job names it):

| Secret | What |
|---|---|
| `SSH_HOST` | the server's hostname or IP |
| `SSH_USER` | the deploy user, e.g. `deploy` |
| `SSH_KEY` | the **private** key, whole file including the BEGIN/END lines |
| `SSH_KNOWN_HOSTS` | output of `ssh-keyscan -H your.server` |

`SSH_KNOWN_HOSTS` is pinned rather than scanned at deploy time on purpose:
trusting whatever answers on the way to a server you are about to hand code to
is not a check.

Generate a key for this and nothing else:

```bash
ssh-keygen -t ed25519 -f bcs-deploy -C 'github actions -> bcs test'
ssh-copy-id -i bcs-deploy.pub deploy@your.server
```

Repository **variables** (Settings → Variables), both optional:

| Variable | Default | What |
|---|---|---|
| `DEPLOY_PATH` | `/var/www/bcs` | the deploy root |
| `HEALTH_URL` | — | e.g. `https://test.example.org/api/v1/health`. Set it and the deploy fails when the site does not answer afterwards |

---

## 3. What a deploy does

1. **Tests first.** The deploy job `needs: tests`, so nothing reaches the server
   that has not just passed against that exact commit.
2. Composer runs **on the runner**, so the server needs no composer and no
   network to packagist, and what ships is exactly what `composer.lock`
   resolved. There is no npm step: the only view using `@vite` falls back to
   inline CSS when there is no manifest, so the build produced nothing anyone
   would see.
3. The tree is streamed as a tar into `releases/<sha>` — `.env` and `storage`
   are excluded from it, then linked in from `shared/`. tar rather than rsync so
   the server needs nothing installed for a deploy to work.
4. Maintenance mode on.
5. `migrate --force`, then `tenants:migrate --force`. Central first: it holds the
   `tenants` table the second command reads.
6. `optimize`, then the symlink swaps, php-fpm reloads, workers restart.
7. Maintenance mode off, and the health check runs if you set `HEALTH_URL`.

**A failed migration leaves the site down and `current` where it was.** That is
deliberate: a half-migrated database served by old code is worse than a
maintenance page, and it is a decision for a person. Read the error, fix or roll
back the migration, then `cd /var/www/bcs/current && php artisan up`.

### Rolling back

If no migration ran, a rollback is two commands:

```bash
ln -sfn /var/www/bcs/releases/<previous-sha> /var/www/bcs/current
systemctl reload "$(systemctl list-units --type=service --no-legend 'php*-fpm.service' | awk '{print $1}')"
```

If one did, roll the schema back first. There is no automatic path for that, and
there should not be: `down()` on a migration that dropped a column cannot invent
the data back.

---

## 4. The app in a browser

`bcs-app-rn` has its own workflow (`.github/workflows/deploy-web.yml`) that
exports the Expo app as static files and uploads them to the **:9001** vhost
above. It uses the **same four SSH secrets** — put them in that repository's
`test` environment too — plus:

| Variable | Example | What |
|---|---|---|
| `BCS_API_URL` | `http://203.0.113.10:9000/api/v1` | **Required.** Baked into the bundle at build time; without it the build points at localhost and every request fails in a way that looks like the API is down |
| `WEB_ROOT` | `/var/www/bcs-app` | Where the static files land |
| `BCS_TENANT_HOST_SUFFIX` | `bcs.example.org` | Optional, and only meaningful once associations have their own subdomains. Set it and `demo-one.bcs.example.org` resolves the association from the hostname, so testers never type a slug |

Two things about this pipeline that differ from the API's:

- **There is no test gate.** The app repo has no JS test runner, so the only
  check before deploy is `tsc --noEmit`. Green means it compiles.
- **It deploys independently.** A push touching only the app does not redeploy
  the API and vice versa, so the two can drift. Change an API contract and both
  need pushing.

No CORS setup is needed: `config/cors.php` allows every origin, which is safe
here only because the API is token-authenticated and carries no cookies
(`supports_credentials` is false). If cookie-based auth is ever added, that has
to be narrowed to a list of known origins **first**.

---

## 5. Before anybody real uses it

- `PAYMENT_GATEWAY=fake` until you mean otherwise. On `auto`, a test server with
  production credentials takes **real money** from whoever presses Pay.
- Point associations at the **SPG UAT** host, not `spg.com.bd`.
- Tenant databases are created by `php artisan tenant:provision <slug>`; the
  test server starts with an empty registry, not a copy of production.
