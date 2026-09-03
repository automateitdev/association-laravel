<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Audit\Finding;
use App\Audit\PaymentAudit;
use App\Http\Controllers\Controller;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-REP-6. What the payment data says that it must never say.
 *
 * The report exists to be **boring**. Zero findings is the only acceptable
 * steady state, and the number is meaningful only because the checks behind it
 * are the ones that have actually caught something - see PaymentAudit for why
 * this is not a port of the legacy audit, which returned zero while two
 * defects were corrupting member-visible figures.
 *
 * The response says what was *not* checked as well as what was. A reader who
 * takes "0 findings" as "the data is correct" has been misled unless they can
 * also see which questions were asked.
 */
class AuditController extends Controller
{
    use ExportsListings;

    public function index(Request $request): JsonResponse
    {
        $findings = (new PaymentAudit())->run($this->only($request));

        return response()->json([
            'data' => array_map(fn (Finding $f) => $f->toArray(), $findings),
            'meta' => [
                'total' => count($findings),
                'by_severity' => $this->countBy($findings, fn (Finding $f) => $f->severity),
                'by_check' => $this->countBy($findings, fn (Finding $f) => $f->check),
                /*
                 * Every check that ran, including the ones that found nothing.
                 * A report listing only its hits cannot be read as an
                 * assurance, because the reader cannot tell a clean bill of
                 * health from a check that was never written.
                 */
                'checks' => array_map(
                    fn (array $meta, string $check) => [
                        'check' => $check,
                        'label' => $meta[0],
                        'severity' => $meta[1],
                    ],
                    PaymentAudit::CHECKS,
                    array_keys(PaymentAudit::CHECKS),
                ),
                /*
                 * And the ones this schema makes impossible, with what
                 * prevents them. "Cannot happen" is a stronger statement than
                 * "we looked and found nothing", and worth distinguishing.
                 */
                'structurally_prevented' => PaymentAudit::preventedChecks(),
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $findings = (new PaymentAudit())->run($this->only($request));
        $format = $this->exportFormat($request);

        $rows = array_map(fn (Finding $f) => [
            'severity' => $f->severity,
            'check' => $f->check,
            'subject' => $f->subject,
            'detail' => $f->detail,
        ], $findings);

        if (($tooLarge = $this->rejectIfTooLarge($rows)) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Payment inconsistencies',
                association: $this->associationName(),
                columns: [
                    new Column('severity', 'Severity'),
                    new Column('check', 'Check'),
                    new Column('subject', 'Subject'),
                    new Column('detail', 'What is wrong'),
                ],
                rows: $rows,
                filters: array_filter([
                    'Checks' => $this->only($request) === null
                        ? 'All ' . count(PaymentAudit::CHECKS)
                        : implode(', ', $this->only($request)),
                    'Findings' => (string) count($rows),
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    /**
     * Which checks to run, if the caller asked for a subset.
     *
     * Unknown names are rejected rather than ignored: a typo that silently
     * runs everything would let somebody believe they had checked one thing
     * when they had checked all of them, or the reverse.
     *
     * @return list<string>|null
     */
    private function only(Request $request): ?array
    {
        $validated = $request->validate([
            'checks' => ['nullable', 'string'],
        ]);

        if (empty($validated['checks'])) {
            return null;
        }

        $requested = array_map('trim', explode(',', $validated['checks']));
        $known = array_keys(PaymentAudit::CHECKS);

        foreach ($requested as $check) {
            if (! in_array($check, $known, true)) {
                abort(422, "Unknown check '{$check}'. Known checks: " . implode(', ', $known) . '.');
            }
        }

        return $requested;
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, int>
     */
    private function countBy(array $findings, callable $key): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $k = $key($finding);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
