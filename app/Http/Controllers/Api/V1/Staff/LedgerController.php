<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->where('is_active', true)

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
            'data' => $ledgers->map(fn (Ledger $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,

                // Group and category travel with the ledger so the picker can
                // show "Subscription Income · Income" rather than a flat list of
                // names, several of which read alike.
                'group' => $l->accountGroup?->name,
                'category' => $l->accountGroup?->category?->name,
                'type' => $l->accountGroup?->category?->type,
            ]),
        ]);
    }
}
