<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\Voucher;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The voucher-wise report: the ledger read document by document.
 *
 * A PORT, unlike the three statements - the legacy has this one, at
 * `voucherwise.report` and `ledger.traces.view` - so these tests are about the
 * ways the legacy version is wrong, and pin each one shut.
 *
 *   1. IT LISTS ENTRIES AND CALLS THEM DOCUMENTS. The legacy query selects the
 *      trace id and then calls `distinct()`, which can never collapse anything
 *      once a unique column is in the select list. So it returns one row per
 *      LINE, each showing the whole document's total. Against COCSOL's
 *      production data that is 15,720 rows standing for 3,378 documents, and
 *      the largest document is listed 84 times over.
 *
 *   2. IT NEVER SHOWS THE AMOUNT. The legacy computes `total_debit` and
 *      `total_credit` in two correlated subqueries per row, and then the table
 *      has no column for either of them. The work is done and thrown away.
 *
 *   3. THE DOCUMENT PAGE HAS NO HEADING. The legacy's single-voucher view lists
 *      ledger, debit and credit, and says nothing about the document itself -
 *      not its date, its number, its kind, nor whether it was later reversed.
 */
class VoucherwiseReportTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Treasurer {$sequence}",
                'email' => "treasurer{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function cashLedger(): Ledger
    {
        $category = AccountCategory::query()->firstOrCreate(['name' => 'Assets'], ['type' => 'asset']);

        return Ledger::create([
            'account_group_id' => AccountGroup::create([
                'account_category_id' => $category->id,
                'name' => 'Cash and Bank',
            ])->id,
            'name' => 'Cash in Hand',
        ]);
    }

    /** An approved voucher with `$pairs` balanced pairs against one ledger. */
    private function voucher(string $no, string $on, string $narration, int $pairs = 1): Voucher
    {
        $voucher = Voucher::create([
            'voucher_no' => $no,
            'type' => 'journal',
            'voucher_date' => $on,
            'narration' => $narration,
            'status' => Voucher::STATUS_APPROVED,
        ]);

        $ledger = $this->cashLedger();

        for ($i = 0; $i < $pairs; $i++) {
            $this->trace($voucher, $ledger, '500.00', '0.00', $on);
            $this->trace($voucher, $ledger, '0.00', '500.00', $on);
        }

        return $voucher;
    }

    private function trace(
        ?object $source,
        Ledger $ledger,
        string $debit,
        string $credit,
        string $on,
        string $narration = 'Test entry',
    ): LedgerTrace {
        return LedgerTrace::create([
            'ledger_id' => $ledger->id,
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => $source === null ? null : $source::class,
            'source_id' => $source?->id,
            'reference' => $source?->voucher_no ?? $source?->invoice_no,
            'posted_on' => $on,
            'narration' => $narration,
        ]);
    }

    private function payment(Member $member, string $invoice, string $on): PaymentInfo
    {
        return PaymentInfo::create([
            'invoice_no' => $invoice,
            'member_id' => $member->id,
            'payable_amount' => '1000.00',
            'fine_amount' => '0.00',
            'total_amount' => '1000.00',
            'status' => 'completed',
            'payment_type' => 'manual',
            'payment_date' => $on,
        ]);
    }

    private function list(string $token, string $query = 'from=2026-01-01&to=2026-12-31')
    {
        return $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/reports/voucherwise?{$query}");
    }

    // ------------------------------------------------------------- the listing

    /**
     * The legacy's central defect, shut.
     *
     * Two documents, one of them four entries and the other two. The report
     * lists TWO rows. The legacy lists six, each carrying its own document's
     * full total, so the column - if it had one - would add up to three times
     * the money that actually moved.
     */
    public function test_it_lists_one_row_per_document_not_one_per_entry(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-001', '2026-03-01', 'Bank charges', pairs: 2);
            $this->voucher('JV-002', '2026-03-02', 'Interest received', pairs: 1);
        });

        $response = $this->list($token)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $rows = collect($response->json('data'))->keyBy('number');

        $this->assertSame(4, $rows['JV-001']['entries']);
        $this->assertSame(2, $rows['JV-002']['entries']);
    }

    /**
     * The amount the legacy computes twice per row and then never prints.
     *
     * Two pairs of 500 is a document worth 1,000 - the sum of its debits, which
     * for a balanced document is also the sum of its credits.
     */
    public function test_it_shows_each_document_amount(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-001', '2026-03-01', 'Bank charges', pairs: 2);
        });

        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.amount', '1000.00')
            ->assertJsonPath('meta.total_amount', '1000.00');
    }

    /**
     * A payment is named by the member who made it.
     *
     * Which is the whole reason to open this report: a figure looks wrong, and
     * the question is who paid it. The trace itself knows only that a payment
     * produced it, so the name is fetched per KIND rather than per row.
     */
    public function test_a_payment_is_named_by_the_member_who_made_it(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $member = $this->makeMember(['name' => 'Md. Rahim Uddin']);
            $payment = $this->payment($member, 'INV-900', '2026-04-01');
            $ledger = $this->cashLedger();

            $this->trace($payment, $ledger, '1000.00', '0.00', '2026-04-01');
            $this->trace($payment, $ledger, '0.00', '1000.00', '2026-04-01');
        });

        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.kind', 'payment')
            ->assertJsonPath('data.0.kind_label', 'Payment')
            ->assertJsonPath('data.0.number', 'INV-900')
            ->assertJsonPath('data.0.description', 'Md. Rahim Uddin');
    }

    /** A voucher is named by its narration - what the person who wrote it said. */
    public function test_a_voucher_is_named_by_its_narration(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-010', '2026-05-01', 'Office rent for May');
        });

        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.kind', 'voucher')
            ->assertJsonPath('data.0.description', 'Office rent for May');
    }

    /**
     * The class name never leaves the server.
     *
     * `source_type` is a fully-qualified class name. It names the namespace
     * layout, it changes when a model moves, and it must not become the value a
     * client sends back to filter on - so the wire carries `payment`.
     */
    public function test_the_source_class_name_is_not_exposed(): void
    {
        $token = $this->staffToken();

        $this->inTenant(fn () => $this->voucher('JV-020', '2026-05-01', 'Anything'));

        $body = $this->list($token)->assertStatus(200)->content();

        /*
         * Asked of the RAW body, and with a needle that survives JSON escaping.
         * `App\Models\Tenant\Voucher` is written into JSON with its backslashes
         * doubled, so a needle spelled with single ones would find nothing
         * whether or not the class name leaked - a test that passes because it
         * is looking for the wrong string.
         */
        $this->assertStringNotContainsString('Models', $body);
        $this->assertStringNotContainsString('Tenant', $body);
    }

    /**
     * The total is the RANGE's, not the page's.
     *
     * A footer that changes as the reader pages through is worse than no footer
     * at all: it looks like a total and answers a question nobody asked.
     */
    public function test_the_total_covers_the_range_rather_than_the_page(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-101', '2026-03-01', 'One');
            $this->voucher('JV-102', '2026-03-02', 'Two');
            $this->voucher('JV-103', '2026-03-03', 'Three');
        });

        $this->list($token, 'from=2026-01-01&to=2026-12-31&per_page=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3)

            // Three documents of 500 each, though only one is on this page.
            ->assertJsonPath('meta.total_amount', '1500.00');
    }

    /** Newest first: a listing of documents is read backwards from today. */
    public function test_it_lists_the_most_recent_document_first(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-201', '2026-03-01', 'Older');
            $this->voucher('JV-202', '2026-06-01', 'Newer');
        });

        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.number', 'JV-202')
            ->assertJsonPath('data.1.number', 'JV-201');
    }

    public function test_it_lists_only_documents_inside_the_range(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-301', '2025-12-31', 'The day before');
            $this->voucher('JV-302', '2026-01-01', 'The first day');
            $this->voucher('JV-303', '2026-01-02', 'Inside');
        });

        // Inclusive at both ends, as every dated filter in this API is.
        $this->list($token, 'from=2026-01-01&to=2026-01-02')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_it_can_be_narrowed_to_one_kind_and_to_a_reference(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-401', '2026-03-01', 'A voucher');

            $member = $this->makeMember();
            $payment = $this->payment($member, 'INV-401', '2026-03-02');
            $this->trace($payment, $this->cashLedger(), '1000.00', '0.00', '2026-03-02');
        });

        $this->list($token, 'from=2026-01-01&to=2026-12-31&kind=voucher')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'JV-401');

        $this->list($token, 'from=2026-01-01&to=2026-12-31&q=INV-')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'INV-401');
    }

    /** An unknown kind is refused rather than silently ignored. */
    public function test_an_unknown_kind_is_refused(): void
    {
        $token = $this->staffToken();

        $this->list($token, 'from=2026-01-01&to=2026-12-31&kind=receipt')
            ->assertStatus(422);
    }

    /**
     * Both bounds are required, as the statements require them.
     *
     * Every document an association has ever posted is not a report anybody
     * asked for. The legacy form requires them too - it falls back to yesterday
     * and today rather than to all of history.
     */
    public function test_it_refuses_a_request_without_a_period(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/voucherwise')
            ->assertStatus(422);
    }

    /**
     * A document whose sides disagree is marked, on the row and in the note.
     *
     * It cannot happen in data this system wrote - a voucher is refused unless
     * it balances and a payment posts in pairs - but this report is exactly
     * where imported legacy traces will be read, and there it is the first
     * thing worth knowing about a row. The note is computed on the SERVER so
     * the screen and the download cannot come to differ about it.
     */
    public function test_a_document_whose_sides_disagree_is_marked(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $voucher = Voucher::create([
                'voucher_no' => 'JV-500',
                'type' => 'journal',
                'voucher_date' => '2026-03-01',
                'narration' => 'Half posted',
                'status' => Voucher::STATUS_APPROVED,
            ]);

            $ledger = $this->cashLedger();

            $this->trace($voucher, $ledger, '500.00', '0.00', '2026-03-01');
            $this->trace($voucher, $ledger, '0.00', '300.00', '2026-03-01');
        });

        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.balanced', false)
            ->assertJsonPath('data.0.note', 'Does not balance');
    }

    // ------------------------------------------------------------ one document

    /**
     * Opening a row shows every line of its document, and says what it is.
     *
     * The heading is the part the legacy page has none of. Ledger, debit and
     * credit with no date, number or kind above them is a page of figures that
     * cannot be filed, checked or disputed afterwards.
     */
    public function test_opening_a_row_shows_the_whole_document_with_its_heading(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->voucher('JV-600', '2026-03-01', 'Bank charges', pairs: 2);
        });

        $traceId = $this->list($token)->json('data.0.trace_id');

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/reports/voucherwise/{$traceId}")
            ->assertStatus(200)
            ->assertJsonPath('data.kind', 'voucher')
            ->assertJsonPath('data.number', 'JV-600')
            ->assertJsonPath('data.posted_on', '2026-03-01')
            ->assertJsonPath('data.description', 'Bank charges')
            ->assertJsonCount(4, 'data.lines')
            ->assertJsonPath('data.lines.0.ledger', 'Cash in Hand')
            ->assertJsonPath('data.lines.0.account_group', 'Cash and Bank')
            ->assertJsonPath('data.total_debit', '1000.00')
            ->assertJsonPath('data.total_credit', '1000.00')
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.reversed', false);
    }

    /**
     * A document that was later undone says so.
     *
     * Shown because a document presented without it reads as current, and a
     * reader who acts on a reversed receipt has been misled by a report that
     * was accurate about every figure printed on it.
     */
    public function test_a_document_says_when_it_was_later_reversed(): void
    {
        $token = $this->staffToken();

        $original = $this->inTenant(function () {
            $voucher = $this->voucher('JV-700', '2026-03-01', 'Posted in error');
            $originals = $voucher->traces()->orderBy('id')->get();

            $reversal = Voucher::create([
                'voucher_no' => 'JV-700-REV',
                'type' => 'journal',
                'voucher_date' => '2026-03-05',
                'narration' => 'Reversal of JV-700',
                'status' => Voucher::STATUS_APPROVED,
                'reverses_id' => $voucher->id,
            ]);

            // BOTH sides reversed, because that is what a reversal is. Undoing
            // one leg would leave the reversal itself unbalanced, and the test
            // would then be asserting against a document nobody would write.
            foreach ($originals as $original) {
                LedgerTrace::create([
                    'ledger_id' => $original->ledger_id,
                    'debit' => $original->credit,
                    'credit' => $original->debit,
                    'source_type' => Voucher::class,
                    'source_id' => $reversal->id,
                    'reference' => $reversal->voucher_no,
                    'posted_on' => '2026-03-05',
                    'reverses_id' => $original->id,
                ]);
            }

            return $originals->first()->id;
        });

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/reports/voucherwise/{$original}")
            ->assertStatus(200)
            ->assertJsonPath('data.reversed', true)
            ->assertJsonPath('data.is_reversal', false);

        // And the reversal itself is listed as one, not as an unexplained
        // second document that happens to cancel the first out.
        $this->list($token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.number', 'JV-700-REV')
            ->assertJsonPath('data.0.is_reversal', true)
            ->assertJsonPath('data.0.note', 'Reversal');
    }

    /**
     * A trace nobody can attribute is its own document, and says so.
     *
     * Nothing in this system writes one - both posting paths set a source - so
     * this is about imported data. `= NULL` matches nothing and `whereNull` on
     * its own would sweep in every other orphan in the books; claiming a
     * grouping we cannot prove is worse than showing a single line.
     */
    public function test_an_unattributed_trace_stands_on_its_own(): void
    {
        $token = $this->staffToken();

        $orphan = $this->inTenant(function () {
            $ledger = $this->cashLedger();

            $first = $this->trace(null, $ledger, '250.00', '0.00', '2026-03-01');
            $this->trace(null, $ledger, '0.00', '250.00', '2026-03-02');

            return $first->id;
        });

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/reports/voucherwise/{$orphan}")
            ->assertStatus(200)
            ->assertJsonPath('data.kind', 'other')
            ->assertJsonPath('data.kind_label', 'Unattributed')
            ->assertJsonPath('data.description', 'Not attributed to a document')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.balanced', false);
    }

    public function test_a_missing_trace_is_a_404(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/voucherwise/999999')
            ->assertStatus(404);
    }

    // ----------------------------------------------------------- the download

    /**
     * `/export` is not read as a trace id.
     *
     * `{trace}` is constrained to digits for exactly this reason. Declaration
     * order happens to save us today and would stop doing so the first time
     * somebody reorders the routes file.
     */
    public function test_the_export_route_is_not_swallowed_by_the_document_route(): void
    {
        $token = $this->staffToken();

        $this->inTenant(fn () => $this->voucher('JV-800', '2026-03-01', 'Exported'));

        $response = $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/voucherwise/export?format=csv&from=2026-01-01&to=2026-12-31')
            ->assertStatus(200);

        $this->assertStringContainsString('JV-800', $response->streamedContent());
    }

    /** No token at all is refused - its own test, for the reason recorded in AccountStatementsTest. */
    public function test_it_refuses_a_request_with_no_token(): void
    {
        $this->getJson(
            '/api/v1/staff/reports/voucherwise?from=2026-01-01&to=2026-12-31',
            $this->headers(),
        )->assertStatus(401);
    }
}
