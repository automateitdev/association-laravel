<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\GatewayConfigurator;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The gateway form (FR-PAY-11).
 *
 * ONLY AN OPERATOR REACHES THIS. `ar_account` decides where an association's
 * money lands, and the association's own `settings.edit` holders - treasurers,
 * office staff, people given the permission to edit fine rates - used to be able
 * to change it. See `GatewayConfigurator` for why that moved.
 *
 * NO `show` METHOD AND NO EDIT FORM PRE-FILLED WITH VALUES. The credentials are
 * write-only: nothing in this application reads a merchant password back out,
 * and a console that rendered one into an HTML input would have undone the
 * reason the surface moved in the first place. Reconfiguring means typing all
 * eight fields again, which is the correct cost for an action this rare.
 */
class GatewayController extends Controller
{
    public function __construct(private readonly GatewayConfigurator $gateways) {}

    public function store(Request $request, string $tenant): RedirectResponse
    {
        $record = Tenant::findOrFail($tenant);

        /*
         * The provider is validated FIRST and on its own, because every other
         * rule below depends on which one it is - the two routes to Sonali take
         * different fields, and validating against the wrong set would reject
         * correct input and accept absent input.
         */
        $provider = (string) $request->input('provider');

        $request->validate([
            'provider' => ['required', 'in:'.implode(',', array_keys(GatewayConfigurator::PROVIDERS))],
        ]);

        $rules = ['ar_account_confirm' => ['required', 'string']];

        foreach (GatewayConfigurator::fieldsFor($provider) as $field => $meta) {
            $required = ($meta['optional'] ?? false) ? 'nullable' : 'required';

            // URLs validated as URLs: a base that is not one fails at the worst
            // possible moment, which is a member mid-payment.
            $rules[$field] = str_ends_with($field, '_url')
                ? [$required, 'url', 'max:255']
                : [$required, 'string', 'max:255'];
        }

        $validated = $request->validate($rules, [
            'ar_account_confirm.required' => 'Type the AR account a second time. It is where the money lands.',
        ]);

        try {
            $this->gateways->store(
                $record,
                $provider,
                $validated,
                'web',
                $validated['ar_account_confirm'],
            );
        } catch (DomainException $e) {
            /*
             * `withInput()` deliberately omitted. Re-populating this form means
             * echoing a merchant password back into an HTML response and into
             * the session, and the cost of retyping is small next to that.
             */
            return back()->withErrors(['gateway' => $e->getMessage()]);
        }

        return redirect()
            ->route('platform.tenant', $record->getKey())
            ->with('status', "Gateway configured for {$record->getKey()}. The association can see it is set and can turn online payment off, but cannot change it.");
    }

    /**
     * Stop or resume taking online payment.
     *
     * The credentials stay either way. Disabling is the thing to reach for when
     * a merchant account is in dispute - it stops money moving without
     * destroying the configuration somebody will have to type back in.
     */
    public function toggle(Request $request, string $tenant): RedirectResponse
    {
        $record = Tenant::findOrFail($tenant);

        $validated = $request->validate([
            'action' => ['required', 'in:enable,disable'],
        ]);

        try {
            $validated['action'] === 'enable'
                ? $this->gateways->enable($record, 'web')
                : $this->gateways->disable($record, 'web');
        } catch (DomainException $e) {
            return back()->withErrors(['gateway' => $e->getMessage()]);
        }

        return redirect()
            ->route('platform.tenant', $record->getKey())
            ->with('status', $validated['action'] === 'enable'
                ? 'Online payment resumed.'
                : 'Online payment stopped. The credentials are kept.');
    }
}
