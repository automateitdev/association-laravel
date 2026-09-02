<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FeeSetup;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\PaymentService;
use App\Services\TenantSeedService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Demo data for working on the app.
 *
 * WHY THIS EXISTS
 * ---------------
 * The member screens were first verified against data typed into `tinker` by
 * hand. That proved the screens worked once, on one machine, and left nothing
 * anyone else could reproduce - including the next person to run
 * `migrate:fresh`. A frontend you can only demo on the laptop that happened to
 * seed it is not really demonstrable.
 *
 * WHAT IT SEEDS, AND WHY THESE CASES
 * ----------------------------------
 * Not "a few members". Specifically the states the UI has to render correctly,
 * including the awkward ones:
 *
 *   Rahim Uddin      overdue, UNDER the suspension threshold - fines of 200,
 *                    100 and ZERO across three periods. The zero matters: a
 *                    fine-free row must not display a "Late fine ৳0.00" line.
 *   Karim Ahmed      fully paid up - exercises the empty-dues state, which is
 *                    otherwise never seen and easy to leave broken.
 *   Fatema Begum     a pending payment awaiting approval - the member must see
 *                    it and NOT be invited to pay twice.
 *   Nasreen Akter    suspended for arrears - login is refused with
 *                    MEMBER_SUSPENDED and an overdue count.
 *   Jamal Hossain    inactive, awaiting approval - refused with
 *                    MEMBER_INACTIVE, a different message entirely.
 *
 * Dates are fixed rather than relative to today, so a screenshot taken next
 * month shows the same figures as one taken now.
 *
 * NEVER RUN THIS ON PRODUCTION DATA. It writes members and payments into a real
 * association database; the guard below refuses unless the environment is local
 * or --force is given deliberately.
 */
class SeedDemoData extends Command
{
    protected $signature = 'tenant:seed-demo
        {slug : The association to seed}
        {--force : Required outside local/testing. Writes real rows into a real association.}';

    protected $description = 'Seed an association with demo members, dues and payments for app development';

    /** Fixed, so figures are identical whenever the seeder runs. */
    private const AS_OF = '2026-06-20';

    private const DEMO_PASSWORD = 'password123';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to seed demo data outside local/testing.');
            $this->line('  This writes members and payments into a real association database.');
            $this->line('  Pass --force only if you are certain.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($slug);

        if (! $tenant) {
            $this->error("No association [{$slug}]. Provision it first: php artisan tenant:provision {$slug}");

            return self::FAILURE;
        }

        $tenant->run(function () {
            app(TenantSeedService::class)->seedAll();

            $this->seedBankDetails();
            $setup = $this->feeHead();

            $this->line('  chart of accounts, settings, bank details .. seeded');

            $this->memberWithMixedFines($setup);
            $this->memberFullyPaidUp($setup);
            $this->memberAwaitingApproval($setup);
            $this->memberSuspended($setup);
            $this->memberInactive();
            $this->staffAccount();
        });

        $this->newLine();
        $this->info("Demo data seeded into [{$slug}].");
        $this->newLine();
        $this->line('  All demo logins use password: '.self::DEMO_PASSWORD);
        $this->line('  01711111111  Rahim Uddin     3 dues, fines 200/100/0');
        $this->line('  01722222222  Karim Ahmed     paid up - empty dues state');
        $this->line('  01733333333  Fatema Begum    payment awaiting approval');
        $this->line('  01744444444  Nasreen Akter   suspended (arrears)');
        $this->line('  01755555555  Jamal Hossain   inactive (awaiting approval)');
        $this->line('  admin@demo.test             staff, superadmin');

        return self::SUCCESS;
    }

    // ---- the association ------------------------------------------------

    private function seedBankDetails(): void
    {
        Setting::put(Setting::BANK_ACCOUNT_NAME, 'Demo Cooperative Society Ltd');
        Setting::put(Setting::BANK_ACCOUNT_NUMBER, '4446102001029');
        Setting::put(Setting::BANK_NAME, 'Sonali Bank PLC');
        Setting::put(Setting::BANK_BRANCH, 'Ramna Corporate Branch');
        Setting::put(Setting::BANK_ROUTING_NUMBER, '200274324');
        Setting::put(Setting::BANK_INSTRUCTIONS, 'Quote your membership number as the reference.');
    }

    private function feeHead(): FeeSetup
    {
        return FeeSetup::firstOrCreate(
            ['fee_head' => 'Monthly Subscription'],
            [
                'monthly' => true,
                'amount' => '1000.00',
                'is_share' => false,
                'ledger_id' => Ledger::where('name', 'Subscription Income')->value('id'),

                // A DIFFERENT ledger, as the platform requires. Seeding both to
                // the same account would quietly undo the separation the whole
                // system exists to keep.
                'fine_ledger_id' => Ledger::where('name', 'Fine Income')->value('id'),
                'is_active' => true,
            ]
        );
    }

    // ---- the members ----------------------------------------------------

    /**
     * Overdue but under the suspension threshold, with a zero-fine row.
     *
     * The zero is the point: `MoneyRow` must not render a "Late fine ৳0.00"
     * line on an on-time instalment, and that only shows up with data like this.
     */
    private function memberWithMixedFines(FeeSetup $setup): void
    {
        $member = $this->member('01711111111', 'Rahim Uddin', 'COC-0412', shares: 12);

        foreach (['2026-05', '2026-06', '2026-07'] as $period) {
            app(FeeAssignService::class)->assign($member->id, $setup, $period);
        }

        $this->accrue($member);
        $this->line('  Rahim Uddin .......... 3 dues, fines 200/100/0');
    }

    /** Paid up: exercises the empty state, which is otherwise never seen. */
    private function memberFullyPaidUp(FeeSetup $setup): void
    {
        $member = $this->member('01722222222', 'Karim Ahmed', 'COC-0413', shares: 30);

        $assign = app(FeeAssignService::class)->assign($member->id, $setup, '2026-04');

        // Only pay what is still unpaid.
        //
        // `assign()` returns the EXISTING assignment on a re-run, and paying it
        // twice is refused outright - correctly, since that is the double-payment
        // the platform is built to prevent. The seeder has to respect the same
        // rule as any other caller rather than route around it.
        if ($assign->status === FeeAssign::STATUS_UNPAID) {
            $payment = app(PaymentService::class)->create(
                $member->id,
                [$assign->id],
                ledgerId: Ledger::where('name', 'Cash in Hand')->value('id'),
            );

            // Completed, so the ledger posts and the history screen has a real
            // receipt to show.
            app(PaymentService::class)->complete($payment);
        }

        $this->line('  Karim Ahmed .......... paid up (empty dues state)');
    }

    /**
     * A payment submitted and waiting on staff.
     *
     * The member must see it and must NOT be offered the same instalment to pay
     * again - the pay screen filters to Unpaid for exactly this reason.
     */
    private function memberAwaitingApproval(FeeSetup $setup): void
    {
        $member = $this->member('01733333333', 'Fatema Begum', 'COC-0414', shares: 8);

        $assign = app(FeeAssignService::class)->assign($member->id, $setup, '2026-05');
        $this->accrue($member);

        // Same guard: on a re-run the request is already outstanding, and asking
        // for a second one would either be refused or leave two pending payments
        // against a single instalment.
        $assign->refresh();

        if ($assign->status === FeeAssign::STATUS_UNPAID) {
            app(PaymentService::class)->create(
                $member->id,
                [$assign->id],
                ledgerId: Ledger::where('name', 'Cash in Hand')->value('id'),
            );
        }

        $this->line('  Fatema Begum ......... payment awaiting approval');
    }

    /** Suspended by the fine engine itself, not by setting a column. */
    private function memberSuspended(FeeSetup $setup): void
    {
        $member = $this->member('01744444444', 'Nasreen Akter', 'COC-0415', shares: 4);

        foreach (['2026-01', '2026-02', '2026-03'] as $period) {
            app(FeeAssignService::class)->assign($member->id, $setup, $period);
        }

        // Accruing far enough past the threshold makes FineService suspend the
        // member. Seeding the status directly would prove nothing about it.
        app(FineService::class)->accrueForMember(
            $member->id,
            CarbonImmutable::parse(self::AS_OF),
        );

        $this->line('  Nasreen Akter ........ suspended by the fine engine');
    }

    private function memberInactive(): void
    {
        $member = $this->member('01755555555', 'Jamal Hossain', 'COC-0416', shares: 0);
        $member->update(['status' => Member::STATUS_INACTIVE]);

        $this->line('  Jamal Hossain ........ inactive, awaiting approval');
    }

    private function staffAccount(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Demo Administrator', 'password' => self::DEMO_PASSWORD],
        );

        $user->update(['password' => self::DEMO_PASSWORD]);

        if (! $user->hasRole('superadmin')) {
            $user->assignRole('superadmin');
        }

        $this->line('  admin@demo.test ...... staff (superadmin)');
    }

    // ---- helpers --------------------------------------------------------

    private function member(string $mobile, string $name, string $membershipNo, int $shares): Member
    {
        $member = Member::firstOrCreate(
            ['mobile' => $mobile],
            ['name' => $name, 'status' => Member::STATUS_ACTIVE, 'password' => self::DEMO_PASSWORD],
        );

        // Re-running the seeder must restore a member edited by hand during
        // development, not leave them in whatever state the last test left.
        $member->update([
            'name' => $name,
            'status' => Member::STATUS_ACTIVE,
            'password' => self::DEMO_PASSWORD,
        ]);

        AssociatorInfo::updateOrCreate(
            ['member_id' => $member->id],
            ['membership_no' => $membershipNo, 'num_or_shares' => $shares],
        );

        return $member;
    }

    /**
     * Accrue to the fixed date, from a clean fine clock.
     *
     * Resetting first keeps the command idempotent: accrual recomputes from the
     * fine-date series, so a re-run without this would extend the series past
     * AS_OF and quietly change the figures a screenshot was taken against.
     */
    private function accrue(Member $member): void
    {
        $assignIds = FeeAssign::where('member_id', $member->id)->pluck('id');

        FineDate::whereIn('fee_assign_id', $assignIds)->delete();

        foreach (FeeAssign::whereIn('id', $assignIds)->get() as $assign) {
            FineDate::create([
                'fee_assign_id' => $assign->id,
                'fine_date' => $assign->fine_date,
                'status' => FineDate::STATUS_INCOMPLETE,
            ]);
            $assign->update(['fine_amount' => '0.00']);
        }

        app(FineService::class)->accrueForMember($member->id, CarbonImmutable::parse(self::AS_OF));

        // Accrual may suspend a member as a side effect; these demo members are
        // meant to be active, and the suspended one is seeded deliberately.
        $member->refresh();
    }
}
