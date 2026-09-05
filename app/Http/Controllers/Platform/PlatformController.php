<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use App\Models\TenantSnapshot;
use App\Services\PlatformService;
use App\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 *   - Read an association's members, payments or ledger. FR-SEC-6 requires a
 *     time-boxed, logged break-glass grant for that, and it is NOT BUILT. The
 *     health figures here are counts and sizes, never rows.
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
    ) {}

    /**
     * The overview: platform totals, and what needs attention.
     *
     * Totals come from `tenant_snapshots`, never from a query spanning tenant
     * databases (FR-PLT-5). They are therefore as fresh as the last collection,
     * which the page states rather than leaving to be assumed.
     */
    public function index(): View
    {
        $snapshots = TenantSnapshot::latestPerTenant()->get()->keyBy('tenant_id');

        $tenants = Tenant::orderBy('id')->get();

        return view('platform.index', [
            'tenants' => $tenants,
            'snapshots' => $snapshots,
            'totals' => $this->totals($snapshots),
            'collectedAt' => $snapshots->max('collected_at'),
            'byStatus' => $tenants->countBy('status'),
        ]);
    }

    public function show(string $tenant): View
    {
        $record = Tenant::findOrFail($tenant);

        return view('platform.tenant', [
            'tenant' => $record,
            'health' => $this->platform->health($record),
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

        return redirect()
            ->route('platform.tenant', $record->getKey())
            ->with('status', "Migration {$run->status}. See the run log below.");
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
