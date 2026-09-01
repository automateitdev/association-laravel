<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\FeeSetup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;

/**
 * Minimal, explicit fixtures for tenant tests.
 *
 * Deliberately literal: a subscription of 1000.00 and a fine rate of 100.00, so
 * expected totals in the tests can be written as literals rather than computed.
 * A test that computes its own expectation the same way the code does proves
 * nothing.
 */
trait TenantFixtures
{
    protected function seedSettings(array $overrides = []): void
    {
        foreach (Setting::defaults() as $key => $spec) {
            Setting::create([
                'key' => $key,
                'value' => $overrides[$key] ?? $spec['value'],
                'group' => $spec['group'],
            ]);
        }
    }

    protected function makeLedgers(): array
    {
        $category = AccountCategory::create(['name' => 'Income', 'type' => 'income']);
        $group = AccountGroup::create([
            'account_category_id' => $category->id,
            'name' => 'Subscriptions',
        ]);

        $assetCategory = AccountCategory::create(['name' => 'Assets', 'type' => 'asset']);
        $assetGroup = AccountGroup::create([
            'account_category_id' => $assetCategory->id,
            'name' => 'Cash and Bank',
        ]);

        return [
            'income' => Ledger::create(['account_group_id' => $group->id, 'name' => 'Subscription Income']),
            'fine' => Ledger::create(['account_group_id' => $group->id, 'name' => 'Fine Income']),
            'cash' => Ledger::create(['account_group_id' => $assetGroup->id, 'name' => 'Cash']),
        ];
    }

    protected function makeFeeSetup(array $attributes = []): FeeSetup
    {
        $ledgers = $this->makeLedgers();

        return FeeSetup::create(array_merge([
            'fee_head' => 'Monthly Subscription',
            'monthly' => true,
            'amount' => '1000.00',
            'is_share' => false,
            'ledger_id' => $ledgers['income']->id,
            'fine_ledger_id' => $ledgers['fine']->id,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeMember(array $attributes = []): Member
    {
        static $sequence = 0;
        $sequence++;

        return Member::create(array_merge([
            'name' => "Test Member {$sequence}",
            'mobile' => '0171'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
            'status' => Member::STATUS_ACTIVE,
        ], $attributes));
    }
}
