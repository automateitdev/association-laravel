<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Proof of payment: who must attach one, and who may.
 *
 * THE LEGACY SPLITS THIS ON WHO IS ACTING, and it is the right split:
 *
 *     if (Auth::guard('admin')->check()) {
 *         $rules['document_files'] = ['nullable', 'array'];      // staff
 *     } else if ($paymentType == manual) {
 *         $rules['document_files'] = ['required', 'array'];      // member
 *     }
 *
 * A member filing a manual payment is ASSERTING that money left their account,
 * and the slip is the only thing an approver has to check that against. A clerk
 * recording a collection took the money themselves. In the production data 851
 * of 1,797 completed manual payments carry a document and only 2 of 2,213
 * online ones do - an online payment is its own proof.
 *
 * BCS HAD THE RULE IN THE WRONG PLACE. The member's pay screen disables Submit
 * until a slip is attached, so it existed - in one client. The endpoint said
 * `sometimes`, so anything else reaching it could create a manual payment with
 * no proof at all and nothing would have said so.
 */
class PaymentSlipRequiredTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    /** A member with something to pay, and a token for their own app. */
    private function memberWithDue(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();
            $this->seedSettings();

            static $sequence = 0;
            $sequence++;

            $member = $this->makeMember(['mobile' => '0171900'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT)]);
            $member->forceFill(['password' => 'secret-password', 'status' => Member::STATUS_ACTIVE])->save();

            $assign = app(FeeAssignService::class)->assign($member->id, $this->makeFeeSetup(), '2026-01');

            return [
                'member' => $member,
                'assign' => $assign instanceof FeeAssign ? $assign->id : FeeAssign::where('member_id', $member->id)->value('id'),
                'token' => $member->createToken('app', ['member.payments.create', 'member.payments.view'])
                    ->plainTextToken,
            ];
        });
    }

    private function staffToken(array $permissions = []): string
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant(function () use ($permissions, $sequence) {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => "Clerk {$sequence}",
                'email' => "slip{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);

            if ($permissions === []) {
                $user->assignRole('superadmin');
            } else {
                $role = Role::findOrCreate("slip-narrow-{$sequence}", 'web');
                $role->syncPermissions(
                    array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
                );
                $user->assignRole($role);
            }

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg');
    }

    // ------------------------------------------------------- the member's own

    /**
     * A manual payment with no slip is refused - the legacy rule, now on the
     * server where every client meets it.
     */
    public function test_a_member_cannot_file_a_manual_payment_without_a_slip(): void
    {
        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'no-slip-1'])
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign],
                'payment_type' => 'manual',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.documents.0', 'The documents field is required.');

        // And nothing was created, which is the part that matters: a rejected
        // payment must not leave a row for somebody to find later.
        $this->inTenant(function () {
            self::assertSame(0, PaymentInfo::count());
        });
    }

    /** The same call with a slip goes through. */
    public function test_a_member_can_file_a_manual_payment_with_a_slip(): void
    {
        Storage::fake('local');

        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'with-slip-1'])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign],
                'payment_type' => 'manual',
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201);

        $this->inTenant(function () {
            self::assertSame(1, PaymentInfo::count());
            self::assertNotEmpty(PaymentInfo::first()->documents);
        });
    }

    /**
     * ...and the default is manual, so omitting the type does not sidestep it.
     *
     * `payment_type` is `sometimes` on this endpoint and falls back to manual,
     * which would be an easy way to be accidentally exempt.
     */
    public function test_omitting_the_type_does_not_escape_the_rule(): void
    {
        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'no-type-1'])
            ->postJson('/api/v1/payments', ['fee_assign_ids' => [$assign]])
            ->assertStatus(422);
    }

    /**
     * An ONLINE payment needs none: the gateway's record is the proof, and
     * there is no slip to photograph. Only 2 of 2,213 online payments in the
     * legacy data carry a document.
     */
    public function test_an_online_payment_needs_no_slip(): void
    {
        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'online-1'])
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign],
                'payment_type' => 'online',
            ])
            ->assertStatus(201);
    }

    /**
     * ...including one that says so explicitly.
     *
     * The app's online path sends `documents: []`, meaning "none". That is a
     * correct thing to say and must not be an error - which is why the rule is
     * `required` with no `min:1` beside it: `required` already fails an empty
     * array in the manual case, and `min:1` would have broken this one.
     */
    public function test_an_online_payment_may_send_an_empty_document_list(): void
    {
        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'online-empty-1'])
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign],
                'payment_type' => 'online',
                'documents' => [],
            ])
            ->assertStatus(201);
    }

    /** And a manual one saying "none" explicitly is still refused. */
    public function test_a_manual_payment_cannot_say_it_has_no_slips(): void
    {
        ['assign' => $assign, 'token' => $token] = $this->memberWithDue();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'manual-empty-1'])
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign],
                'payment_type' => 'manual',
                'documents' => [],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------- the counter

    /**
     * A clerk recording a collection needs none - the other half of the legacy
     * split. They took the money themselves; their word is the record, and the
     * audit trail says who they are.
     */
    public function test_staff_can_record_a_collection_without_a_slip(): void
    {
        $token = $this->staffToken();
        ['member' => $member, 'assign' => $assign] = $this->memberWithDue();

        $ledger = $this->inTenant(fn () => \App\Models\Tenant\Ledger::firstOrFail()->id);

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'counter-1'])
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $member->id,
                'fee_assign_ids' => [$assign],
                'ledger_id' => $ledger,
            ])
            ->assertStatus(201);
    }

    /**
     * ...but they CAN attach one, which they could not before.
     *
     * Often there is a slip - somebody paid at the bank and brought it to the
     * counter - and until now there was nowhere to put it at the moment it was
     * in the clerk's hand.
     */
    public function test_staff_can_attach_a_slip_while_recording_a_collection(): void
    {
        Storage::fake('local');

        $token = $this->staffToken();
        ['member' => $member, 'assign' => $assign] = $this->memberWithDue();

        $ledger = $this->inTenant(fn () => \App\Models\Tenant\Ledger::firstOrFail()->id);

        $response = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => 'counter-2'])
            ->post('/api/v1/staff/collections', [
                'member_id' => $member->id,
                'fee_assign_ids' => [$assign],
                'ledger_id' => $ledger,
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201);

        $this->inTenant(function () use ($response) {
            $payment = PaymentInfo::find($response->json('data.id'));

            self::assertNotEmpty($payment->documents, 'The slip should be on the payment.');
        });
    }

    // -------------------------------------------------- who may attach one

    /**
     * ATTACHING IS NOT READING.
     *
     * The controller's docblock has always said staff reach these "if they may
     * approve payments"; the code asked for `payments.view`, so a read-only
     * account could file evidence against a payment somebody else was about to
     * decide on.
     */
    public function test_a_read_only_account_cannot_attach_a_slip(): void
    {
        Storage::fake('local');

        ['member' => $member, 'assign' => $assign] = $this->memberWithDue();

        $payment = $this->inTenant(fn () => PaymentInfo::create([
            'invoice_no' => 'INV-SLIP-1',
            'member_id' => $member->id,
            'payable_amount' => '1000.00',
            'fine_amount' => '0.00',
            'total_amount' => '1000.00',
            'status' => 'pending',
            'payment_type' => 'manual',
        ]));

        $reader = $this->staffToken(['payments.view']);

        $this->withHeaders($this->headers($reader))
            ->post("/api/v1/payments/{$payment->id}/documents", ['documents' => [$this->slip()]])
            ->assertStatus(403);

        // An approver may.
        $approver = $this->staffToken(['payments.view', 'payments.approve']);

        $this->withHeaders($this->headers($approver))
            ->post("/api/v1/payments/{$payment->id}/documents", ['documents' => [$this->slip()]])
            ->assertStatus(201);
    }

    /** Reading one is still `payments.view` - an approver has to see it. */
    public function test_a_read_only_account_can_still_list_the_slips(): void
    {
        ['member' => $member] = $this->memberWithDue();

        $payment = $this->inTenant(fn () => PaymentInfo::create([
            'invoice_no' => 'INV-SLIP-2',
            'member_id' => $member->id,
            'payable_amount' => '1000.00',
            'fine_amount' => '0.00',
            'total_amount' => '1000.00',
            'status' => 'pending',
            'payment_type' => 'manual',
        ]));

        $this->withHeaders($this->headers($this->staffToken(['payments.view'])))
            ->getJson("/api/v1/payments/{$payment->id}/documents")
            ->assertStatus(200);
    }
}
