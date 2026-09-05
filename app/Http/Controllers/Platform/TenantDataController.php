<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\BreakGlassService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The only place in this application where an operator sees an association's
 * rows (FR-SEC-6).
 *
 * EVERY METHOD STARTS WITH `authorise()` AND THAT IS NOT A CONVENTION - it is
 * the first statement, it throws rather than returning a boolean, and the query
 * it guards is inside the closure that follows. There is no path through this
 * file that reaches a row without a live grant, and the shape is deliberately
 * one that cannot be half-applied.
 *
 * WHAT IS DELIBERATELY NOT HERE, even under a grant:
 *
 *   - National ID numbers, and the uploaded images of them. Also signatures and
 *     the proof documents. These are the most sensitive things the system
 *     holds, a support question has never needed one, and NFR-SEC-4 puts them
 *     behind short-lived signed URLs for the association's own staff. An
 *     operator route to them would be the widest hole in the design.
 *   - Password hashes, remember tokens, API tokens. Nothing here is a way in.
 *   - Any write. Not an edit, not a status change, not a note. An operator who
 *     could change an association's data quietly would make every figure in
 *     the system arguable, and there is no reason a support question needs it.
 *
 * The grant names ONE AREA. A grant to read payments does not open members, and
 * the two methods ask separately - so a look at the member list is a look at
 * the member list, in the log and in the association's notice.
 */
class TenantDataController extends Controller
{
    /** Deliberately small. A page is a unit of "what was looked at". */
    private const PER_PAGE = 25;

    public function __construct(private readonly BreakGlassService $breakGlass) {}

    public function members(Request $request, string $tenant): View
    {
        $record = Tenant::findOrFail($tenant);
        $grant = $this->grantFor($record, 'members', $request);

        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        $result = $record->run(function () use ($search, $page) {
            $query = DB::table('members')
                ->leftJoin('associators_infos', 'associators_infos.member_id', '=', 'members.id')
                ->select([
                    'members.id',
                    'members.name',
                    'members.mobile',
                    'members.email',
                    'members.status',
                    'members.joining_date',
                    'associators_infos.membership_no',
                ])
                ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                    $w->where('members.name', 'like', "%{$search}%")
                        ->orWhere('members.mobile', 'like', "%{$search}%")
                        ->orWhere('associators_infos.membership_no', 'like', "%{$search}%");
                }))
                ->orderBy('members.id');

            return [
                'total' => (clone $query)->count(),
                'rows' => $query->forPage($page, self::PER_PAGE)->get(),
            ];
        });

        /*
         * Recorded AFTER the read, with what was actually asked for. The search
         * term is part of it: "read the member list" and "searched the member
         * list for a surname" are different acts, and only one of them looks
         * like somebody answering a support ticket.
         */
        $this->breakGlass->recordRead($grant, 'break_glass.read_members', [
            'page' => $page,
            'search' => $search !== '' ? $search : null,
            'rows' => $result['rows']->count(),
        ]);

        return view('platform.inspect', [
            'tenant' => $record,
            'grant' => $grant,
            'area' => 'members',
            'search' => $search,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $result['total'],
            'rows' => $result['rows'],
        ]);
    }

    public function payments(Request $request, string $tenant): View
    {
        $record = Tenant::findOrFail($tenant);
        $grant = $this->grantFor($record, 'payments', $request);

        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        $result = $record->run(function () use ($search, $page) {
            $query = DB::table('payment_infos')
                ->join('members', 'members.id', '=', 'payment_infos.member_id')
                ->leftJoin('ledgers', 'ledgers.id', '=', 'payment_infos.ledger_id')
                ->select([
                    'payment_infos.id',
                    'payment_infos.invoice_no',
                    'payment_infos.status',
                    'payment_infos.payable_amount',
                    'payment_infos.fine_amount',
                    'payment_infos.total_amount',
                    'payment_infos.gateway_reference',
                    'payment_infos.created_at',
                    'members.name as member',
                    'ledgers.name as ledger',
                ])
                ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                    $w->where('payment_infos.invoice_no', 'like', "%{$search}%")
                        ->orWhere('payment_infos.gateway_reference', 'like', "%{$search}%")
                        ->orWhere('members.name', 'like', "%{$search}%");
                }))
                ->orderByDesc('payment_infos.id');

            return [
                'total' => (clone $query)->count(),
                'rows' => $query->forPage($page, self::PER_PAGE)->get(),
            ];
        });

        $this->breakGlass->recordRead($grant, 'break_glass.read_payments', [
            'page' => $page,
            'search' => $search !== '' ? $search : null,
            'rows' => $result['rows']->count(),
        ]);

        return view('platform.inspect', [
            'tenant' => $record,
            'grant' => $grant,
            'area' => 'payments',
            'search' => $search,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $result['total'],
            'rows' => $result['rows'],
        ]);
    }

    /**
     * The grant, or a 403 that says which half is missing.
     *
     * A 403 and not the console's usual 404: the operator is signed in and the
     * page exists. What they are being told is that this particular door needs
     * a grant, which is information they are entitled to and which reveals
     * nothing - they already knew the association exists.
     */
    private function grantFor(Tenant $tenant, string $scope, Request $request): \App\Models\BreakGlassGrant
    {
        try {
            return $this->breakGlass->authorise($tenant, $scope, $request->user('operator'));
        } catch (DomainException $e) {
            throw new AccessDeniedHttpException(
                $e->getMessage().' Request one, and a second operator must approve it.'
            );
        }
    }
}
