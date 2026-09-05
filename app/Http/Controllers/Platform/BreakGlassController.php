<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BreakGlassGrant;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\BreakGlassService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asking for, approving and ending break-glass grants (FR-SEC-6).
 *
 * SEPARATE FROM THE CONTROLLER THAT READS THE DATA, on purpose. This one
 * decides who may look; `TenantDataController` does the looking. Keeping them
 * apart means the authorisation is never a few lines above the query it guards,
 * where a later edit can quietly reorder them.
 *
 * Nothing here reads an association's data. Every action is about a grant.
 */
class BreakGlassController extends Controller
{
    public function __construct(private readonly BreakGlassService $breakGlass) {}

    /**
     * The queue, and the history.
     *
     * Pending first, because an unapproved grant is somebody waiting. Everything
     * else is listed in full and never pruned: a finished grant is the record
     * that an association's records were read, and it is worth more later than
     * it is today.
     */
    public function index(): View
    {
        return view('platform.break-glass', [
            'pending' => BreakGlassGrant::with(['requester', 'tenant'])
                ->where('status', BreakGlassGrant::STATUS_PENDING)
                ->latest('id')
                ->get(),

            'grants' => BreakGlassGrant::with(['requester', 'decider', 'tenant'])
                ->where('status', '!=', BreakGlassGrant::STATUS_PENDING)
                ->latest('id')
                ->paginate(30),

            /*
             * A one-operator deployment cannot break glass at all, and finding
             * that out at the approval step - after writing a reason, during an
             * incident - is the wrong moment. Said on the page instead.
             */
            'operators' => Operator::where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request, string $tenant): RedirectResponse
    {
        $record = Tenant::findOrFail($tenant);

        $validated = $request->validate([
            'scope' => ['required', 'in:'.implode(',', BreakGlassGrant::SCOPES)],
            'minutes' => ['required', 'integer', 'in:'.implode(',', BreakGlassGrant::DURATIONS)],

            /*
             * Longer than the reasons demanded elsewhere in the console. A
             * suspension's reason is read by us; this one is read by the
             * association, in a message telling them their records were opened,
             * and "support" is not an answer to that.
             */
            'reason' => ['required', 'string', 'min:20', 'max:500'],
        ], [
            'reason.min' => 'Say what you are looking for and why. The association reads this.',
        ]);

        return $this->guard(
            fn () => $this->breakGlass->request(
                $record,
                $validated['scope'],
                $validated['reason'],
                (int) $validated['minutes'],
                $request->user('operator'),
            ),
            redirect()->route('platform.break-glass')
                ->with('status', "Requested. A second operator has to approve it before it opens anything."),
        );
    }

    public function decide(Request $request, BreakGlassGrant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,deny'],
            'note' => ['required_if:decision,deny', 'nullable', 'string', 'max:500'],
        ], [
            'note.required_if' => 'Say why it is refused. The person who asked is owed a reason.',
        ]);

        $operator = $request->user('operator');

        return $this->guard(
            fn () => $validated['decision'] === 'approve'
                ? $this->breakGlass->approve($grant, $operator, $validated['note'] ?? null)
                : $this->breakGlass->deny($grant, $operator, $validated['note']),
            redirect()->route('platform.break-glass'),
            function (BreakGlassGrant $result) {
                if ($result->status === BreakGlassGrant::STATUS_DENIED) {
                    return 'Refused.';
                }

                return $result->notified_at
                    ? "Approved. {$result->tenant_id}'s superadmins have been told, and it ends at "
                        .$result->expires_at->toDayDateTimeString().'.'
                    // Approved and shut. Said plainly, because "approved" on its
                    // own would send somebody off to use access they do not have.
                    : 'Approved, but nobody could be notified, so it opens nothing yet: '
                        .$result->notify_error;
            },
        );
    }

    /** Try the notification again, after the reason it failed has been fixed. */
    public function renotify(BreakGlassGrant $grant): RedirectResponse
    {
        return $this->guard(
            fn () => $this->breakGlass->notify($grant),
            redirect()->route('platform.break-glass'),
            fn (BreakGlassGrant $result) => $result->notified_at
                ? 'Notified. The grant is live.'
                : "Still could not notify: {$result->notify_error}",
        );
    }

    public function revoke(Request $request, BreakGlassGrant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->guard(
            fn () => $this->breakGlass->revoke($grant, $request->user('operator'), $validated['note']),
            redirect()->route('platform.break-glass')->with('status', 'Ended.'),
        );
    }

    /**
     * Domain refusals belong in front of the operator.
     *
     * Every message the service throws names what is wrong and what to do about
     * it - that a second operator must approve, that a duplicate request is
     * already open - so it is passed through as it stands.
     *
     * @param  callable(): BreakGlassGrant  $work
     * @param  (callable(BreakGlassGrant): string)|null  $status
     */
    private function guard(callable $work, RedirectResponse $to, ?callable $status = null): RedirectResponse
    {
        try {
            $result = $work();
        } catch (DomainException $e) {
            return back()->withErrors(['break_glass' => $e->getMessage()])->withInput();
        }

        return $status ? $to->with('status', $status($result)) : $to;
    }
}
