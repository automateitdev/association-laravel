<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Tenant\GatewayCredential;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

/**
 * Which gateway an association actually uses.
 *
 * THE CHOICE IS PER ASSOCIATION, NOT PER DEPLOYMENT. Each association holds its
 * own merchant account (A-1), and there are now two ways to reach the same bank
 * - SPG directly, or SPG through PayFlex. Binding one implementation in the
 * container would make that a deploy decision for everybody at once, which is
 * the opposite of what is wanted: one association should be able to run on
 * PayFlex, and be proved, while every other stays on the path that already
 * works.
 *
 * SO THE STORED CREDENTIAL DECIDES. `gateway_credentials.provider` on the row
 * that is active names the adapter, and there is at most one active row - see
 * `GatewayConfigurator`, which switches the others off when it activates one.
 * An association with no active row has no online payment, which is a supported
 * state rather than an error: counter collection and bank transfer still work.
 *
 * `PAYMENT_GATEWAY` STILL WINS. Defaulting it to `fake` means a deployment
 * takes no real money until somebody deliberately says otherwise, and it keeps
 * every existing test - which configures no credentials - on the fake exactly as
 * before. Going live is an explicit act, not the absence of one.
 */
class GatewayRegistry
{
    /**
     * Provider key to implementation.
     *
     * @var array<string, class-string<PaymentGateway>>
     */
    public const ADAPTERS = [
        SonaliPaymentGateway::PROVIDER => SonaliPaymentGateway::class,
        PayflexSpgGateway::PROVIDER => PayflexSpgGateway::class,
        FakePaymentGateway::PROVIDER => FakePaymentGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * The gateway for whichever association is currently initialised.
     *
     * Never throws for a missing configuration. A member reaching a "Pay now"
     * button that the association has not set up should meet the fake's refusal
     * or a clean domain error, not a 500 assembled three layers down.
     */
    public function active(): PaymentGateway
    {
        if (config('services.gateway.driver') === 'fake') {
            return $this->container->make(FakePaymentGateway::class);
        }

        return $this->for($this->activeProvider());
    }

    public function for(?string $provider): PaymentGateway
    {
        $adapter = self::ADAPTERS[$provider] ?? null;

        if (! $adapter) {
            /*
             * Falls back rather than throwing, and the fake refuses payment
             * rather than pretending to take it. An association configured for
             * a provider this build does not know about - a downgrade, a typo
             * in a manual database edit - must not be able to collect money
             * through some other association's adapter.
             */
            return $this->container->make(FakePaymentGateway::class);
        }

        return $this->container->make($adapter);
    }

    /**
     * The active provider key for the current association, or null.
     *
     * Guarded because this runs inside request handling for a tenant whose
     * database may be mid-restore or unreachable, and "which gateway" is never
     * a question worth taking a page down over.
     */
    public function activeProvider(): ?string
    {
        try {
            return GatewayCredential::query()
                ->where('is_active', true)
                ->value('provider');
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> the providers an operator may configure */
    public static function configurable(): array
    {
        return [SonaliPaymentGateway::PROVIDER, PayflexSpgGateway::PROVIDER];
    }

    /** @throws RuntimeException when the key names nothing this build knows */
    public static function assertKnown(string $provider): void
    {
        if (! in_array($provider, self::configurable(), true)) {
            throw new RuntimeException("There is no gateway called '{$provider}'.");
        }
    }
}
