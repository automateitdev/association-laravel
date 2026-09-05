<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BreakGlassGrant;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Somebody at the platform is about to read your records" (NFR-SEC-6).
 *
 * SENT TO THE ASSOCIATION, NOT TO US. Everything else in the platform console
 * is us watching us. This is the one message that leaves the building, and it
 * is the whole reason break-glass is a control rather than a form: the residual
 * risk on an operator reading tenant data is *detectable, not preventable*, and
 * this is the detection.
 *
 * SENT SYNCHRONOUSLY - no `ShouldQueue`. A queued notification is one a failed
 * worker turns into silence, and silence here is indistinguishable from nobody
 * having been told. The service treats a send failure as a reason to keep the
 * grant shut, which only works if the failure happens where it can be seen.
 *
 * ONE MESSAGE TO ALL OF THEM, rather than one each. The superadmins of a single
 * association are colleagues who already know each other's addresses, and a
 * single send either works or does not - where a loop can leave three people
 * told, one not, and a grant that has to decide which of those it is.
 *
 * It says WHO, WHY, WHAT AREA and WHEN IT ENDS, in that order, because a
 * recipient who reads only the first line should still learn the thing that
 * matters. It does not apologise and it does not ask for anything: it is a
 * notice, and the association decides what to do about it.
 */
class BreakGlassGranted extends Notification
{
    public function __construct(
        private readonly BreakGlassGrant $grant,
        private readonly Tenant $tenant,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $area = $this->grant->scope === 'members'
            ? 'member records'
            : 'payment records';

        $until = $this->grant->expires_at?->toDayDateTimeString() ?? 'an unspecified time';

        return (new MailMessage)
            ->subject("A platform operator has been granted access to your {$area}")
            ->greeting("Access to {$this->tenant->name}")
            ->line(
                "**{$this->grant->requested_by_email}** has been granted read-only access to "
                    ."your association's {$area}."
            )
            ->line("**Reason given:** {$this->grant->reason}")
            ->line("**Approved by:** {$this->grant->decided_by_email}")
            ->line("**Access ends:** {$until}. It expires on its own; nobody has to remember.")
            ->line(
                'The access is read-only. Nothing can be changed, no money can be moved, and '
                    .'every page opened is recorded.'
            )
            /*
             * Named rather than left implicit. A notice that says "this is
             * normal" invites the reader to skip it; one that says what to do
             * if it is not expected is the reason it is being sent.
             */
            ->line(
                'If this was not expected, reply to this message and say so. You are entitled '
                    .'to an answer about why your records were read.'
            )
            ->salutation('— The platform team');
    }
}
