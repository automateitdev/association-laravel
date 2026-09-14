<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The two words a review endpoint is answered with.
 *
 * ONE SPELLING, BECAUSE THERE WERE TWO. Document reviews took `approved` and
 * `rejected`; the profile-update review beside them - reached from the same
 * staff screen, in the same sitting - took `approve` and `reject`. The same
 * decision, spelled two ways, and nothing failed loudly: a client that guessed
 * wrong got a 422 complaining about a field it had already sent.
 *
 * THE IMPERATIVE WON. `decision=approve` is an instruction given to the
 * endpoint, and it is what the buttons in the app are called. The past tense
 * belongs to what the record becomes afterwards, and is already spoken for:
 * `rejected` is a Document *status* (Document::STATUS_REJECTED), so sending it
 * as a decision made the request and its outcome indistinguishable in a log.
 *
 * BREAK-GLASS IS DELIBERATELY NOT HERE. It answers `approve` or **`deny`**,
 * because refusing somebody access is not the act of rejecting something they
 * submitted - nothing of theirs is sent back to be corrected - and the service
 * method it calls is named for that.
 */
final class Decision
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    /**
     * Validation rules for a decision, and for the reason a refusal owes.
     *
     * A REASON IS REQUIRED TO REJECT, and never to approve. Refusing without
     * saying why leaves the member to guess and ask again; an approval needs
     * no explanation because the result speaks for itself.
     *
     * @param  int|null  $reasonMax  Characters allowed in `reason`, or null for
     *                               an endpoint that takes no reason at all.
     * @return array<string, array<int, string>>
     */
    public static function rules(?int $reasonMax = null): array
    {
        $rules = [
            'decision' => ['required', 'in:'.self::APPROVE.','.self::REJECT],
        ];

        if ($reasonMax !== null) {
            $rules['reason'] = [
                'required_if:decision,'.self::REJECT,
                'nullable',
                'string',
                'max:'.$reasonMax,
            ];
        }

        return $rules;
    }
}
