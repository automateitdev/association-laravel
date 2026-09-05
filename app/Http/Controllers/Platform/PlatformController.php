<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use App\Models\TenantSnapshot;
use App\Services\GatewayConfigurator;
use App\Services\PlatformService;
use App\Services\TenantProvisioner;
use App\Services\TenantReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The platform console (FR-PLT-1 … FR-PLT-5).
 *
 * SERVER-RENDERED, AND SEPARATE FROM THE APP ON PURPOSE. The mobile app is
 * tenant-scoped by construction: every request carries `X-Tenant` and resolves
 * into one association. An operator surface inside it would need that
 * requirement relaxed, which is the one guarantee worth keeping absolute. This
 * console has no `X-Tenant`, no Sanctum token and no route in the API - it is
 * a different door for a different person.
 *
 * WHAT IT DELIBERATELY CANNOT DO
 * ------------------------------
 *   - Read an association's members, payments or ledger. The health figures
 *     here are counts and sizes, never rows. Reading a row goes through
 *     BreakGlassController and TenantDataController instead: requested with a
 *     reason, approved by a SECOND operator, the association told before it
 *     opens anything, and expiring on the clock (FR-SEC-6).
 *   - Drop a tenant database. Archiving closes an association and keeps every
 *     record; destroying one is a deliberate act at the server, with a backup
 *     in hand.
 *   - Set a fine rate, edit a member, or touch money. Those belong to the
 *     association, and an operator who could do them quietly would make every
 *     figure in the system arguable.
 */
class PlatformController extends Controller
{
    public function __construct(
        private readonly PlatformService $platform,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantReadiness $readiness,
        private readonly GatewayConfigurator $gateways,
    ) {}

    /**
     * The overview: platform totals, and what needs attention.
     *
     * Totals come from `tenant_snapshots`, never from a query spanning tenant
     * databases (FR-PLT-5). They are therefore as fresh as the last collection,
     * which the page states rather than leaving to be assumed.
     *
     * SEARCH, FILTER AND SORT ARE NOT DECORATION. This began as every
     * association in registry order, which reads fine with four and not at all
     * with forty - and the row somebody needs is usually the one that is
     * broken, which is the hardest to find by scrolling.
     */
    public function index(Request $request): View
    {
        $snapshots = TenantSnapshot::latestPerTenant()->get()->keyBy('tenant_id');

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $sort = (string) $request->query('sort', 'id');

        $tenants = Tenant::query()
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->when($status !== '' && $status !== 'attention', fn ($q) => $q->where('status', $status))
            ->get();

        /*
         * Sorted here rather than in SQL, because two of the four orderings
         * live in the snapshot rather than on the tenant row - and a UNION
         * across the registry and a per-tenant table to sort a list of forty
         * rows is the wrong trade.
         */
        $tenants = (match ($sort) {
            'name' => $tenants->sortBy(fn ($t) => mb_strtolower((string) $t->name)),
            'members' => $tenants->sortByDesc(fn ($t) => $snapshots->get($t->getKey())?->members ?? 0),
            'size' => $tenants->sortByDesc(
                fn ($t) => (float) ($snapshots->get($t->getKey())?->database_size_mb ?? 0)
            ),
            default => $tenants->sortBy(fn ($t) => $t->getKey()),
        })->values();

        /*
         * "Attention" is a filter over the snapshot, not a status on the tenant
         * row: an association can be perfectly active and still be unable to
         * take a payment. It is the one filter an operator actually reaches for.
         */
        if ($status === 'attention') {
            $tenants = $tenants->filter(function ($tenant) use ($snapshots) {
                $snapshot = $snapshots->get($tenant->getKey());

                return $snapshot === null
                    || $snapshot->error !== null
                    || ($snapshot->blocking_issues ?? 0) > 0;
            })->values();
        }

        return view('platform.index', [
            'tenants' => $tenants,
            'snapshots' => $snapshots,
            'totals' => $this->totals($snapshots),
            'collectedAt' => $snapshots->max('collected_at'),
            'byStatus' => Tenant::query()->get()->countBy('status'),
            'search' => $search,
            'status' => $status,
            'sort' => $sort,

            // Counted over every association, not the filtered view, so the
            // number does not change when somebody searches.
            'needAttention' => $snapshots->filter(
                fn ($snapshot) => $snapshot->error !== null || ($snapshot->blocking_issues ?? 0) > 0
            )->count(),
        ]);
    }

    /**
     * One association, and what is still missing.
     *
     * Readiness is computed LIVE here rather than read from the snapshot, unlike
     * the list. This is the page somebody acts on - they have just configured a
     * gateway and want to see it took - and a checklist up to a day stale would
     * be worse than none.
     */
    public function show(string $tenant): View
    {
        $record = Tenant::findOrFail($tenant);

        return view('platform.tenant', [
            'tenant' => $record,
            'health' => $this->platform->health($record),
            'readiness' => $this->readiness->for($record),

            // Never a credential: the last four of the AR account and nothing
            // else. See GatewayConfigurator.
            'gateway' => $this->gateways->summary($record),

            'snapshot' => TenantSnapshot::where('tenant_id', $record->getKey())
                ->latest('collected_at')->first(),
            'runs' => TenantProvisioningRun::where('tenant_id', $record->getKey())
                ->latest('id')->limit(10)->get(),
            'audit' => OperatorAuditLog::where('tenant_id', $record->getKey())
                ->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * Suspend, reinstate or archive (FR-PLT-1).
     *
     * ONE ENDPOINT FOR THE THREE, because they share the thing that matters:
     * a required reason. Every one of them is visible to an association -
     * suspension locks them out mid-day - and "why did this happen" must have
     * an answer that is not somebody's memory.
     */
    public function transition(Request $request, string $tenant): RedirectResponse
    {
        $record = Tenant::findOrFail($tenant);

        $validated = $request->validate([
            'action' => ['required', 'in:suspend,reinstate,archive'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],

            /*
             * Typing the slug to confirm. These actions cut an association off
             * from its own records, and the confirmation exists because the
             * screen lists many associations that look alike in a row of
             * buttons.
             */
            'confirm' => ['required', 'string'],
        ]);

        if ($validated['confirm'] !== $record->getKey()) {
            return back()->withErrors([
                'confirm' => "Type the association's id exactly to confirm: {$record->getKey()}",
            ])->withInput();
        }

        $result = match ($validated['action']) {
            'suspend' => $this->platform->suspend($record, $validated['reason']),
            'reinstate' => $this->platform->reinstate($record, $validated['reason']),
            'archive' => $this->platform->archive($record, $validated['reason']),
        };

        return redirect()
            ->route('platform.tenant', $record->getKey())
            ->with('status', "{$record->getKey()} is now {$result->status}.");
    }

    public function create(): View
    {
        return view('platform.new');
    }

    /**
     * Provision an association (FR-PLT-1).
     *
     * Runs the same TenantProvisioner the CLI runs. That matters more here than
     * anywhere else in this controller: provisioning creates a database and a
     * scoped MySQL user, and a second implementation of its rollback would be
     * a way to leave orphans that block the next attempt on the same slug.
     *
     * NO CONFIRMATION FIELD, unlike the lifecycle actions. Those act on an
     * association that already exists and are chosen from a list of similar
     * rows; this one is typed from scratch, and asking somebody to retype what
     * they just typed adds ceremony without adding a check.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * The slug is IMMUTABLE once set: it becomes the database name and
             * the code members type into the app. Validated to the same rule
             * the provisioner enforces, so the form refuses before a run is
             * even opened rather than after.
             */
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,49}$/'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255'],
            'locale' => ['nullable', 'string', 'max:5'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:3'],
        ], [
            'slug.regex' => 'The id must be lowercase letters, digits and hyphens, 2-50 characters, starting with a letter.',
        ]);

        $result = $this->provisioner->provision($validated);

        if (! $result->ok) {
            return back()
                ->withErrors([
                    'slug' => $result->attempted
                        // Say that it was undone. "Failed" alone leaves somebody
                        // wondering whether a half-made database is now in the way.
                        ? "Provisioning failed and was rolled back: {$result->error} ({$result->rollbackNotes})"
                        : $result->error,
                ])
                ->withInput();
        }

        OperatorAuditLog::record(
            action: 'tenant.provisioned',
            tenantId: $result->tenant->getKey(),
            after: ['domain' => $result->domain, 'name' => $result->tenant->name],
            source: 'web',
        );

        return redirect()
            ->route('platform.tenant', $result->tenant->getKey())
            ->with('status', "{$result->tenant->name} is active at {$result->domain}.")
            /*
             * Flashed, so it appears exactly once and never lands in a URL or
             * the audit log. It is not a password - it lets the association's
             * first administrator set one nobody else has seen, including us.
             */
            ->with('setup_token', $result->setupToken)
            ->with('setup_email', $validated['admin_email'] ?? null);
    }

    /** FR-PLT-2. */
    public function migrate(string $tenant): RedirectResponse
    {
        $record = Tenant::findOrFail($tenant);

        $run = $this->platform->migrate($record);

        if ($run->status === 'failed') {
            /*
             * The reason, on the screen, not "failed — see the log below". A
             * status with a pointer to a log is a second click to learn the one
             * fact the first click was asking for.
             */
            return back()->withErrors([
                'migrate' => 'Migration failed and nothing was applied: '
                    .Str::limit(trim((string) $run->error), 300),
            ]);
        }

        return redirect()
            ->route('platform.tenant', $record->getKey())
            ->with('status', "Migrations ran. The full output is in the run log below.");
    }

    /**
     * Who can reach this console, and whether they can actually get in.
     *
     * READ-ONLY, AND THAT IS THE POINT. There is no create, no disable, no
     * reset. A console that could mint its own users would make one stolen
     * session permanent, and one that could reset a colleague's second factor
     * would make it reassignable - both are exactly what an attacker who got
     * this far would reach for next.
     *
     * What it is for: knowing, before an incident rather than during one, that
     * break-glass needs two operators and this deployment has one - and that
     * the second has never finished enrolling.
     */
    public function operators(): View
    {
        $operators = Operator::orderBy('email')->get();

        return view('platform.operators', [
            'operators' => $operators,

            /*
             * Counted here rather than left to the reader, because it is the
             * number that decides whether FR-SEC-6 works at all: a grant cannot
             * be approved by the operator who asked for it.
             */
            'usable' => $operators->filter(
                fn (Operator $o) => $o->is_active && $o->mfa_confirmed_at !== null
            )->count(),
        ]);
    }

    /** FR-PLT-4: the whole trail, not only one association's. */
    public function audit(Request $request): View
    {
        return view('platform.audit', [
            'entries' => OperatorAuditLog::with('operator')
                ->when($request->query('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'actions' => OperatorAuditLog::distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, TenantSnapshot>  $snapshots
     * @return array<string, string|int>
     */
    private function totals($snapshots): array
    {
        // bcadd, not array_sum: these are decimal money strings, and the
        // platform total is the one place a float would be summed most often.
        $instalments = '0.00';
        $fines = '0.00';

        foreach ($snapshots as $snapshot) {
            $instalments = bcadd($instalments, (string) $snapshot->collected_instalments, 2);
            $fines = bcadd($fines, (string) $snapshot->collected_fines, 2);
        }

        return [
            'members' => $snapshots->sum('members'),
            'active_members' => $snapshots->sum('active_members'),
            'completed_payments' => $snapshots->sum('completed_payments'),
            'collected_instalments' => $instalments,
            'collected_fines' => $fines,
            'database_size_mb' => round((float) $snapshots->sum('database_size_mb'), 2),
        ];
    }
}
