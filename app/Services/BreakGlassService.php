<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BreakGlassGrant;
use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Notifications\BreakGlassGranted;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Break-glass: the whole lifecycle of one operator's look inside one
 * association (FR-SEC-6, NFR-SEC-6).
 *
 * THE CONTROL IS NOT THE APPROVAL. It is the notification. An operator with
 * database credentials can read any association's rows at a MySQL prompt
 * whenever they like, and no amount of console design prevents that - the
 * threat model says so in as many words: *detectable, not preventable*. What
 * this makes possible is that the association LEARNS, every time, without
 * asking and without trusting us to volunteer it. Access somebody else knows
 * about is access that gets questioned.
 *
 * So the ordering here is deliberate and is the reason the class exists rather
 * than a few controller methods: a grant is approved, then the association is
 * told, and only then does it open anything. A grant whose notification failed
 * is `approved` and useless, which is the right way round. The opposite
 * ordering - open the door, notify best-effort - is the same feature with the
 * control removed, and it would look identical in a screenshot.
 */
class BreakGlassService
{
    /**
     * Ask for access.
     *
     * The reason is required and is not a formality: it is what the approver
     * weighs and what the association reads in the notification. A minimum
     * length is a blunt instrument against "support", but a blunt instrument
     * beats nothing.
     */
    public function request(
        Tenant $tenant,
        string $scope,
        string $reason,
        int $minutes,
        Operator $operator,
    ): BreakGlassGrant {
        if (! in_array($scope, BreakGlassGrant::SCOPES, true)) {
            throw new DomainException("There is no such area as '{$scope}'.");
        }

        if (! in_array($minutes, BreakGlassGrant::DURATIONS, true)) {
            throw new DomainException('Choose one of the offered durations.');
        }

        $open = BreakGlassGrant::where('tenant_id', $tenant->getKey())
            ->where('scope', $scope)
            ->where('requested_by', $operator->id)
            ->open()
            ->exists();

        if ($open) {
            throw new DomainException(
                "You already have an open request for {$scope} on {$tenant->getKey()}. "
                    .'Use it, let it expire, or withdraw it before asking again.'
            );
        }

        $grant = BreakGlassGrant::create([
            'tenant_id' => $tenant->getKey(),
            'scope' => $scope,
            'reason' => $reason,
            'requested_by' => $operator->id,
            'requested_by_email' => $operator->email,
            'duration_minutes' => $minutes,
            'status' => BreakGlassGrant::STATUS_PENDING,
        ]);

        OperatorAuditLog::record(
            action: 'break_glass.requested',
            tenantId: $tenant->getKey(),
            after: ['grant_id' => $grant->id, 'scope' => $scope, 'minutes' => $minutes],
            reason: $reason,
            operator: $operator,
        );

        return $grant;
    }

    /**
     * A DIFFERENT operator approves.
     *
     * Not negotiable, and not softened the way voucher self-approval was. There
     * the person approving their own work still belonged to the association
     * whose money it was, and forbidding it would have stopped a two-person
     * association posting anything at all. Here the operator is not the data
     * owner, the association is not in the room, and a single person who can
     * both ask and grant is not a control at all - it is a form somebody fills
     * in on the way to the data.
     *
     * A deployment with one operator therefore cannot break glass. That is the
     * intended answer, and the console says so: make a second operator.
     */
    public function approve(BreakGlassGrant $grant, Operator $approver, ?string $note = null): BreakGlassGrant
    {
        $this->assertPending($grant, 'approved');

        if ($grant->requested_by === $approver->id) {
            throw new DomainException(
                'A grant cannot be approved by the operator who asked for it. '
                    .'A second operator must approve, which is the only reason this step exists.'
            );
        }

        $grant->update([
            'status' => BreakGlassGrant::STATUS_APPROVED,
            'decided_by' => $approver->id,
            'decided_by_email' => $approver->email,
            'decided_at' => now(),
            'decision_note' => $note,

            // The clock starts here. See the migration.
            'expires_at' => now()->addMinutes($grant->duration_minutes),
        ]);

        OperatorAuditLog::record(
            action: 'break_glass.approved',
            tenantId: $grant->tenant_id,
            after: [
                'grant_id' => $grant->id,
                'scope' => $grant->scope,
                'requested_by' => $grant->requested_by_email,
                'expires_at' => $grant->expires_at?->toDateTimeString(),
            ],
            reason: $note,
            operator: $approver,
        );

        // Approval alone opens nothing; this is what does.
        return $this->notify($grant->fresh());
    }

    public function deny(BreakGlassGrant $grant, Operator $decider, string $note): BreakGlassGrant
    {
        $this->assertPending($grant, 'denied');

        $grant->update([
            'status' => BreakGlassGrant::STATUS_DENIED,
            'decided_by' => $decider->id,
            'decided_by_email' => $decider->email,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        OperatorAuditLog::record(
            action: 'break_glass.denied',
            tenantId: $grant->tenant_id,
            after: ['grant_id' => $grant->id, 'scope' => $grant->scope],
            reason: $note,
            operator: $decider,
        );

        return $grant->fresh();
    }

    /**
     * End a live grant early.
     *
     * Anybody may revoke, including the operator who asked - finishing sooner
     * than the clock is behaviour to make easy, not to gate. The association is
     * not re-notified: they were told the window; closing it early takes nothing
     * away from them.
     */
    public function revoke(BreakGlassGrant $grant, Operator $operator, string $note): BreakGlassGrant
    {
        if (! in_array($grant->status, [BreakGlassGrant::STATUS_PENDING, BreakGlassGrant::STATUS_APPROVED], true)) {
            throw new DomainException("This grant is {$grant->status} and there is nothing to revoke.");
        }

        // Read before the update: once `update()` has synced, `getOriginal()`
        // returns the new value and the log would record the time it was ended
        // as the time it was going to end anyway.
        $wouldHaveEnded = $grant->expires_at?->toDateTimeString();

        $grant->update([
            'status' => BreakGlassGrant::STATUS_REVOKED,
            'decision_note' => $note,
        ]);

        OperatorAuditLog::record(
            action: 'break_glass.revoked',
            tenantId: $grant->tenant_id,
            before: ['expires_at' => $wouldHaveEnded],
            after: ['grant_id' => $grant->id, 'reads' => $grant->reads],
            reason: $note,
            operator: $operator,
        );

        return $grant->fresh();
    }

    /**
     * Tell the association, and only then let the grant open anything.
     *
     * TWO CHANNELS, FOR DIFFERENT REASONS. The mail is what a person actually
     * reads. The row written into the association's OWN `audit_logs` is the
     * durable one: it survives a bounced address, a changed inbox and a
     * forwarded mailbox nobody watches, and it sits in the association's own
     * records rather than ours - which matters, because a notification held only
     * by the party being watched is not evidence of anything.
     *
     * The tenant row is written FIRST. If mail fails after it, the association
     * still holds the record; if the order were reversed, a mail failure would
     * leave them with nothing at all.
     *
     * Retryable on purpose: a grant whose notification failed is approved and
     * shut, and an operator can try again once the address is fixed rather than
     * starting the request over.
     */
    public function notify(BreakGlassGrant $grant): BreakGlassGrant
    {
        if ($grant->status !== BreakGlassGrant::STATUS_APPROVED) {
            throw new DomainException('Only an approved grant is notified.');
        }

        $tenant = Tenant::find($grant->tenant_id);

        if (! $tenant) {
            return $this->notifyFailed($grant, "Association {$grant->tenant_id} is no longer in the registry.");
        }

        try {
            $recipients = $tenant->run(function () use ($grant, $tenant) {
                $emails = $this->superadminEmails();

                if ($emails === []) {
                    return [];
                }

                /*
                 * Into the ASSOCIATION's audit log, in the association's own
                 * database. `actor` is an operator, who is not one of their
                 * users - which is precisely the fact being recorded.
                 */
                DB::table('audit_logs')->insert([
                    'actor_type' => Operator::class,
                    'actor_id' => $grant->decided_by,
                    'subject_type' => BreakGlassGrant::class,
                    'subject_id' => $grant->id,
                    'action' => 'break_glass.granted',
                    'after' => json_encode([
                        'scope' => $grant->scope,
                        'requested_by' => $grant->requested_by_email,
                        'approved_by' => $grant->decided_by_email,
                        'expires_at' => $grant->expires_at?->toDateTimeString(),
                        'association' => $tenant->getKey(),
                    ]),
                    'reason' => $grant->reason,
                    'created_at' => now(),
                ]);

                return $emails;
            });
        } catch (Throwable $e) {
            /*
             * As in `PlatformService::health()`: if `run()` threw part way
             * through initialising, the connection can be left pointing at a
             * database that will not answer, and the next query anywhere fails
             * for a reason that has nothing to do with it.
             */
            tenancy()->end();

            return $this->notifyFailed($grant, "Could not reach the association's database: {$e->getMessage()}");
        }

        if ($recipients === []) {
            return $this->notifyFailed(
                $grant,
                'This association has no superadmin with an email address, so nobody can be told. '
                    .'Access stays shut until there is somebody to notify.'
            );
        }

        try {
            Notification::route('mail', $recipients)->notify(new BreakGlassGranted($grant, $tenant));
        } catch (Throwable $e) {
            return $this->notifyFailed($grant, "The association's record was written, but the mail failed: {$e->getMessage()}");
        }

        $grant->update([
            'notified_at' => now(),
            'notified_to' => $recipients,
            'notify_error' => null,
        ]);

        OperatorAuditLog::record(
            action: 'break_glass.notified',
            tenantId: $grant->tenant_id,
            after: ['grant_id' => $grant->id, 'notified_to' => $recipients],
        );

        return $grant->fresh();
    }

    /**
     * The one question every read asks.
     *
     * Returns the live grant or throws. There is no boolean variant and no
     * "check then read" pair, because the two-call shape is how a check gets
     * written in one place and forgotten in the next.
     */
    public function authorise(Tenant $tenant, string $scope, Operator $operator): BreakGlassGrant
    {
        $grant = BreakGlassGrant::where('tenant_id', $tenant->getKey())
            ->where('scope', $scope)
            ->where('requested_by', $operator->id)
            ->live()
            ->latest('expires_at')
            ->first();

        if (! $grant) {
            throw new DomainException(
                "You have no live grant to read {$scope} for {$tenant->getKey()}."
            );
        }

        return $grant;
    }

    /**
     * Record that a page of data was actually looked at.
     *
     * The grant says access was possible. This says it happened, and to what -
     * the difference between "an operator could have read the member list" and
     * "an operator read page 3 of the member list at 14:07", which is the whole
     * of an incident review.
     *
     * @param  array<string, mixed>  $what
     */
    public function recordRead(BreakGlassGrant $grant, string $action, array $what = []): void
    {
        $grant->increment('reads');
        $grant->update(['last_read_at' => now()]);

        OperatorAuditLog::record(
            action: $action,
            tenantId: $grant->tenant_id,
            after: $what + ['grant_id' => $grant->id],
            reason: $grant->reason,
        );
    }

    /**
     * Every superadmin's email, from inside the tenant.
     *
     * Raw query rather than spatie's `role()` scope: this runs while the
     * connection is swapped, and a query built from tables is one fewer thing
     * that has to be pointing at the right database to be correct.
     *
     * @return list<string>
     */
    private function superadminEmails(): array
    {
        return DB::table('users')
            ->join('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', \App\Models\User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'superadmin')
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email')
            ->all();
    }

    private function notifyFailed(BreakGlassGrant $grant, string $error): BreakGlassGrant
    {
        $grant->update(['notify_error' => $error, 'notified_at' => null]);

        OperatorAuditLog::record(
            action: 'break_glass.notify_failed',
            tenantId: $grant->tenant_id,
            after: ['grant_id' => $grant->id, 'error' => $error],
        );

        return $grant->fresh();
    }

    private function assertPending(BreakGlassGrant $grant, string $attempted): void
    {
        if ($grant->status !== BreakGlassGrant::STATUS_PENDING) {
            throw new DomainException(
                "This grant is {$grant->status} and cannot be {$attempted}."
            );
        }
    }
}
