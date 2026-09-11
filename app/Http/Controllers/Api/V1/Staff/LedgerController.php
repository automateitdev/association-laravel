<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\FeeSetup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\PaymentInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts, for choosing where money posts.
 *
 * WHY THIS EXISTS
 * ---------------
 * Creating a fee head requires naming TWO ledgers - one for the instalment
 * income and a different one for the fine income (FR-FEE-2) - and there was no
 * way to list them. Without this the fee setup screen cannot be built at all:
 * it would have to ask staff to type ledger ids.
 *
 * The separation is the accounting half of ADR-0005. The legacy system stamps
 * the fine ledger silently from a config value, which is why its fines and
 * subscriptions are indistinguishable in the income statement, and why it could
 * never serve a second association with a different chart.
 */
class LedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ledgers = Ledger::query()
            ->with(['accountGroup:id,name,account_category_id', 'accountGroup.category:id,name,type'])

            /*
             * Inactive ledgers are hidden by default but askable for. A ledger
             * is retired rather than deleted, because its history has to stay
             * readable - so the screen that manages the chart needs to see the
             * retired ones, while a picker choosing where a fee posts must not
             * offer them.
             */
            ->when(
                ! filter_var($request->query('include_inactive', 'false'), FILTER_VALIDATE_BOOL),
                fn ($q) => $q->where('is_active', true)
            )

            // Fee heads post income, so income accounts are what this screen is
            // almost always reaching for. The filter is optional rather than
            // imposed: an association's chart is its own, and the API has no
            // business deciding that a fee can only ever credit income.
            ->when($request->query('type'), fn ($q, $type) => $q->whereHas(
                'accountGroup.category',
                fn ($c) => $c->where('type', $type)
            ))

            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $ledgers->map(fn (Ledger $l) => $this->shape($l)),
        ]);
    }

    /**
     * The groups a ledger can belong to, for the create form.
     *
     * Returned with their category and its accounting type, because "Cash and
     * Bank" alone does not tell somebody choosing a group whether they are
     * about to file a ledger under assets or expenses.
     */
    public function groups(): JsonResponse
    {
        return response()->json([
            'data' => AccountGroup::query()
                ->with('category:id,name,type')
                ->orderBy('name')
                ->get()
                ->map(fn (AccountGroup $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'category' => $g->category?->name,
                    'type' => $g->category?->type,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_group_id' => ['required', 'integer', 'exists:account_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:ledgers,code'],
            'opening_balance' => ['sometimes', 'numeric'],

            // Whether money physically sits here, for the cash summary. The
            // association's own answer rather than a guess from a group name
            // anybody may rename - see the migration that added it.
            'is_cash' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ledger = Ledger::create($validated);

        $this->audit($request, $ledger, 'ledger.created', [], $validated);

        return response()->json(
            ['data' => $this->shape($ledger->fresh(['accountGroup.category']))],
            201
        );
    }

    /**
     * Change a ledger.
     *
     * TWO THINGS IT REFUSES, both for the same reason: a ledger with postings
     * against it is no longer just a name, it is the heading a set of numbers
     * already lives under.
     *
     *   - Regrouping it moves every one of those postings to a different line
     *     of the income statement, silently and retroactively. Last year's
     *     report stops matching last year's printout.
     *   - Deactivating one that a fee head or a payment still names breaks the
     *     NEXT posting rather than this one, so the failure lands later and on
     *     somebody who did not make the change.
     *
     * Neither is forbidden in principle. Both need a decision nobody can make
     * from this screen, so the API says what is in the way and lets a human
     * settle it.
     */
    public function update(Request $request, int $ledger): JsonResponse
    {
        $record = Ledger::with('accountGroup.category')->findOrFail($ledger);

        $validated = $request->validate([
            'account_group_id' => ['sometimes', 'integer', 'exists:account_groups,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('ledgers', 'code')->ignore($record->id),
            ],
            'opening_balance' => ['sometimes', 'numeric'],

            // Whether money physically sits here, for the cash summary. The
            // association's own answer rather than a guess from a group name
            // anybody may rename - see the migration that added it.
            'is_cash' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $posted = LedgerTrace::where('ledger_id', $record->id)->exists();

        if (
            $posted
            && array_key_exists('account_group_id', $validated)
            && (int) $validated['account_group_id'] !== (int) $record->account_group_id
        ) {
            throw new ApiException(
                'LEDGER_HAS_POSTINGS',
                'This ledger already has entries posted against it, so it cannot be moved to '
                    .'another group. Doing so would reclassify every past entry and change '
                    .'reports that have already been issued.',
                422,
            );
        }

        if (array_key_exists('is_active', $validated) && ! $validated['is_active'] && $record->is_active) {
            $usedBy = $this->inUseBy($record);

            if ($usedBy !== null) {
                throw new ApiException(
                    'LEDGER_IN_USE',
                    "This ledger cannot be deactivated: {$usedBy}.",
                    422,
                );
            }
        }

        $before = $record->only(array_keys($validated));

        $record->update($validated);

        $this->audit($request, $record, 'ledger.updated', $before, $validated);

        return response()->json(['data' => $this->shape($record->fresh(['accountGroup.category']))]);
    }

    /**
     * What still points at this ledger, phrased for the person reading it.
     *
     * Returns null when nothing does.
     */
    private function inUseBy(Ledger $ledger): ?string
    {
        $asIncome = FeeSetup::where('is_active', true)->where('ledger_id', $ledger->id)->pluck('fee_head');
        $asFine = FeeSetup::where('is_active', true)->where('fine_ledger_id', $ledger->id)->pluck('fee_head');

        if ($asIncome->isNotEmpty() || $asFine->isNotEmpty()) {
            $heads = $asIncome->merge($asFine)->unique()->implode(', ');

            return "it is the account for {$heads}";
        }

        if (
            PaymentInfo::where('ledger_id', $ledger->id)
                ->whereIn('status', ['pending', 'completed'])
                ->exists()
        ) {
            return 'payments still name it as where their money was received';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, Ledger $ledger, string $action, array $before, array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Ledger::class,
            'subject_id' => $ledger->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(Ledger $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'code' => $l->code,

            // Group and category travel with the ledger so the picker can
            // show "Subscription Income · Income" rather than a flat list of
            // names, several of which read alike.
            'account_group_id' => $l->account_group_id,
            'group' => $l->accountGroup?->name,
            'category' => $l->accountGroup?->category?->name,
            'type' => $l->accountGroup?->category?->type,

            'opening_balance' => (string) $l->opening_balance,
            'is_cash' => (bool) $l->is_cash,
            'is_active' => (bool) $l->is_active,
        ];
    }
}
