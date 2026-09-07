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

Ubuntu with nginx, php-fpm 8.3 and MySQL 8. Nothing here is repeated by the
pipeline, so it has to be right before the first deploy.

```bash
sudo apt install -y nginx mysql-server redis-server \
  php8.3-fpm php8.3-mysql php8.3-bcmath php8.3-mbstring \
  php8.3-intl php8.3-gd php8.3-zip php8.3-curl php8.3-xml php8.3-redis
```

Two of those are not optional, in different ways.

`php8.3-bcmath`: every money figure the server computes goes through bcmath,
because floats do not reconcile (FR-MON-4).

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

Copy `.env.example` to `/var/www/bcs/shared/.env` and fill it in. Generate the
key without an application to run it from, and paste it into `APP_KEY` yourself:

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
CREATE USER 'bcs'@'localhost' IDENTIFIED BY '…';
GRANT ALL PRIVILEGES ON *.* TO 'bcs'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

Without `WITH GRANT OPTION`, provisioning creates the database and then fails
handing the new user its rights — half a tenant, which is the messiest way for
this to go wrong.

### One sudoers line

The release script reloads php-fpm, because opcache keeps serving the previous
release out of memory otherwise:

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
```

That is the only privilege the deploy user needs. It is deliberately one exact
command rather than `NOPASSWD: ALL`.

### nginx

```nginx
server {
    listen 80;
    server_name test.example.org;
    root /var/www/bcs/current/public;

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

`$realpath_root`, not `$document_root`: with a symlinked release the two differ,
and the wrong one leaves php-fpm serving whatever opcache remembers.

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
3. rsync into `releases/<sha>` — `.env` and `storage` are excluded, then linked.
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
sudo systemctl reload php8.3-fpm
```

If one did, roll the schema back first. There is no automatic path for that, and
there should not be: `down()` on a migration that dropped a column cannot invent
the data back.

---

## 4. The app in a browser

`bcs-app-rn` has its own workflow (`.github/workflows/deploy-web.yml`) that
exports the Expo app as static files and rsyncs them to a second vhost on this
same server. It uses the **same four SSH secrets** — put them in that
repository's `test` environment too — plus two variables:

| Variable | Example | What |
|---|---|---|
| `BCS_API_URL` | `https://test.example.org/api/v1` | Required. Without it the build points at localhost and every request fails in a way that looks like the API is down |
| `WEB_ROOT` | `/var/www/bcs-app` | Where the static files land |
| `BCS_TENANT_HOST_SUFFIX` | `bcs.example.org` | Optional. Set it and `demo-one.bcs.example.org` resolves the association from the hostname, so testers never type a slug |

```nginx
server {
    listen 80;
    server_name app.example.org *.bcs.example.org;
    root /var/www/bcs-app;

    # expo-router exports `output: "static"`, so every route is a real file.
    # The fallback is for deep links that arrive before the file exists.
    location / { try_files $uri $uri.html $uri/ /index.html; }
}
```

The wildcard `server_name` is what makes `BCS_TENANT_HOST_SUFFIX` worth setting:
one vhost serves every association, and the hostname names which.

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
