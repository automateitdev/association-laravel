<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-association configuration.
 *
 * This table is why a second association can be onboarded without a code change.
 * The legacy system holds the fine rate in a class constant and the fine ledger
 * in a config file - both single-tenant assumptions (FR-SET-1, FR-SET-2).
 */
class Setting extends Model
{
    /** Overdue periods x this = the fine. Legacy behaviour: 100/-. */
    public const FINE_RATE = 'fine.rate';

    /** Days after the period starts before the fine clock begins. */
    public const FINE_GRACE_DAYS = 'fine.grace_days';

    /** Overdue periods before a member is suspended. Legacy behaviour: 3. */
    public const SUSPENSION_THRESHOLD = 'fine.suspension_threshold';

    public const INVOICE_FORMAT = 'invoice.format';

    /*
     * Where a member should transfer money for a manual payment.
     *
     * Not decoration: with online payment optional, a member reading "pay at
     * the bank and upload your slip" needs to be told WHICH account. Without
     * these the manual flow is incomplete - it asks for money and does not say
     * where to send it.
     *
     * Per association, because each holds its own account (A-1).
     */
    public const BANK_ACCOUNT_NAME = 'bank.account_name';

    public const BANK_ACCOUNT_NUMBER = 'bank.account_number';

    public const BANK_NAME = 'bank.bank_name';

    public const BANK_BRANCH = 'bank.branch';

    public const BANK_ROUTING_NUMBER = 'bank.routing_number';

    /** Free text shown under the account details, e.g. a reference to quote. */
    public const BANK_INSTRUCTIONS = 'bank.instructions';

    /*
     * WHO THE ASSOCIATION IS, on anything it prints.
     *
     * The legacy certificate and ID card blades hardcode all of this -
     * "Cadre Officers' Co-operative society limited (COCSOL)", registration
     * 01/2023 dated 23/02/23, cocsol2022@gmail.com, an authorised capital of
     * one crore in ten thousand shares, and a return address in Ibrahimpur.
     * A second association printing from that template would hand its members
     * a card belonging to somebody else.
     *
     * So they are settings, and they are seeded EMPTY. A blank line on a
     * certificate is a question the association can answer; a wrong
     * registration number is one nobody will think to ask.
     */
    public const SOCIETY_NAME = 'society.name';

    public const SOCIETY_REGISTRATION_NO = 'society.registration_no';

    public const SOCIETY_REGISTERED_ON = 'society.registered_on';

    public const SOCIETY_ADDRESS = 'society.address';

    public const SOCIETY_EMAIL = 'society.email';

    public const SOCIETY_WEBSITE = 'society.website';

    /**
     * The authorised capital and how it is divided, as they appear on a share
     * certificate - which is a legal statement about the society, not a figure
     * derived from what members happen to have paid.
     */
    public const SOCIETY_AUTHORISED_CAPITAL = 'society.authorised_capital';

    public const SOCIETY_TOTAL_SHARES = 'society.total_shares';

    /** What one share is worth. The legacy prints `1000/-`, hardcoded. */
    public const SOCIETY_SHARE_VALUE = 'society.share_value';

    /** Whether members may start an online payment at all. */
    public const ONLINE_PAYMENT_ENABLED = 'payment.online_enabled';

    /** How long an online payment intent may sit unconfirmed (FR-PAY-8). */
    public const PAYMENT_INTENT_TTL_MINUTES = 'payment.intent_ttl_minutes';

    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * Values seeded at provisioning. Chosen to preserve legacy behaviour exactly
     * (migration transformation M-9) - an association that migrates must not see
     * its fines change on day one.
     */
    public static function defaults(): array
    {
        return [
            self::FINE_RATE => ['value' => '100.00', 'group' => 'fine'],
            self::FINE_GRACE_DAYS => ['value' => 0, 'group' => 'fine'],
            self::SUSPENSION_THRESHOLD => ['value' => 3, 'group' => 'fine'],
            self::INVOICE_FORMAT => ['value' => 'INV-{YYYY}-{SEQ:6}', 'group' => 'invoice'],
            self::PAYMENT_INTENT_TTL_MINUTES => ['value' => 60, 'group' => 'payment'],

            // Blank until the association fills them in. Deliberately seeded
            // empty rather than omitted, so the settings screen shows the
            // fields and their absence is visible rather than implicit.
            self::BANK_ACCOUNT_NAME => ['value' => '', 'group' => 'bank'],
            self::BANK_ACCOUNT_NUMBER => ['value' => '', 'group' => 'bank'],
            self::BANK_NAME => ['value' => '', 'group' => 'bank'],
            self::BANK_BRANCH => ['value' => '', 'group' => 'bank'],
            self::BANK_ROUTING_NUMBER => ['value' => '', 'group' => 'bank'],
            self::BANK_INSTRUCTIONS => ['value' => '', 'group' => 'bank'],

            /*
             * Empty until the association fills them in, like the bank details
             * above and for a sharper reason: these are printed on documents a
             * member keeps. A blank is a question somebody can answer; a
             * plausible-looking default belonging to another association is a
             * wrong answer nobody thinks to check.
             */
            self::SOCIETY_NAME => ['value' => '', 'group' => 'society'],
            self::SOCIETY_REGISTRATION_NO => ['value' => '', 'group' => 'society'],
            self::SOCIETY_REGISTERED_ON => ['value' => '', 'group' => 'society'],
            self::SOCIETY_ADDRESS => ['value' => '', 'group' => 'society'],
            self::SOCIETY_EMAIL => ['value' => '', 'group' => 'society'],
            self::SOCIETY_WEBSITE => ['value' => '', 'group' => 'society'],
            self::SOCIETY_AUTHORISED_CAPITAL => ['value' => '', 'group' => 'society'],
            self::SOCIETY_TOTAL_SHARES => ['value' => '', 'group' => 'society'],
            self::SOCIETY_SHARE_VALUE => ['value' => '', 'group' => 'society'],

            // Off until an association configures a gateway and turns it on.
            self::ONLINE_PAYMENT_ENABLED => ['value' => false, 'group' => 'payment'],
        ];
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        if ($row) {
            return $row->value;
        }

        return $fallback ?? (static::defaults()[$key]['value'] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => static::defaults()[$key]['group'] ?? 'general']
        );
    }
}
