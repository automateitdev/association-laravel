<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\PaymentInfo;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\PaymentDocumentService;
use App\Services\PaymentService;
use App\Services\TenantSeedService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Manual payment with proof of payment (SRS OD-2, decided 2026-09-01).
 *
 * With no gateway integrated, this IS how money reaches an association through
 * the app: the member pays at a bank, photographs the slip, and staff approve
 * against it. The uploaded file is evidence an approval decision rests on - a
 * member may be asked about one months later - so the security properties here
 * matter as much as the happy path.
 */
class PaymentDocumentTest extends TenantTestCase
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

    /**
     * @return array{0: int, 1: string, 2: FeeAssign}
     */
    private function memberWithDues(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            $member = $this->makeMember(['password' => 'secret']);
            app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

            $token = $member->createToken('test', [
                'member.payments.view', 'member.payments.create',
            ])->plainTextToken;

            return [$member->id, $token, $assign];
        });
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $n = 0;
            $n++;

            $user = User::create([
                'name' => "Approver {$n}",
                'email' => "approver{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('admin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function slip(string $name = 'slip.jpg'): UploadedFile
    {
        // A real JPEG, so the content sniff in the service is genuinely
        // exercised rather than bypassed by a fake().
        return UploadedFile::fake()->image($name, 400, 600);
    }

    // ---- the member's side ------------------------------------------------

    public function test_a_member_creates_a_manual_payment_with_a_bank_slip(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        $response = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.status', PaymentInfo::STATUS_PENDING);
        $response->assertJsonPath('data.documents.0.original_name', 'slip.jpg');

        // Instalment and fine still apart, documents or not.
        $response->assertJsonPath('data.payable_amount', '1000.00');
        $response->assertJsonPath('data.fine_amount', '200.00');
    }

    /**
     * A member who paid on Tuesday and photographed the slip on Wednesday
     * should not have to cancel and recreate the payment.
     */
    /**
     * A SECOND slip, added afterwards.
     *
     * The payment is created with one now - a manual payment without proof is
     * refused, as it is in the legacy system - so this endpoint is for what
     * comes after: the member photographs the back of the slip, or the bank
     * gives them a stamped copy the next day.
     */
    public function test_a_member_can_attach_a_further_slip_to_a_pending_payment(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip('first.jpg')],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->post("/api/v1/payments/{$paymentId}/documents", ['documents' => [$this->slip('late.jpg')]])
            ->assertStatus(201)
            ->assertJsonPath('data.documents.1.original_name', 'late.jpg');
    }

    public function test_documents_cannot_be_added_once_a_payment_is_decided(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->inTenant(function () use ($paymentId) {
            $ledgerId = \App\Models\Tenant\Ledger::where('name', 'Cash')->value('id');
            app(PaymentService::class)->complete(PaymentInfo::find($paymentId), ledgerId: $ledgerId);
        });

        $this->withHeaders($this->headers($token))
            ->post("/api/v1/payments/{$paymentId}/documents", ['documents' => [$this->slip()]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_NOT_PENDING');
    }

    // ---- what gets refused ------------------------------------------------

    /**
     * The content is sniffed, not trusted. A PHP file renamed to .jpg claims
     * image/jpeg in the request; getMimeType() reads the actual bytes.
     */
    public function test_a_disguised_file_is_refused(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        // A REAL file on disk, not UploadedFile::fake().
        //
        // Laravel's fake derives getMimeType() from the FILENAME, so a fake
        // cannot exercise a content sniff at all - the first version of this
        // test passed the upload and proved nothing. Anything that claims to
        // test content inspection has to hand the inspector real bytes.
        $path = tempnam(sys_get_temp_dir(), 'slip').'.jpg';
        file_put_contents($path, "<?php echo 'not a bank slip';");

        $disguised = new UploadedFile($path, 'slip.jpg', 'image/jpeg', null, test: true);

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$disguised],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENT_REJECTED');
    }

    public function test_an_oversized_document_is_refused(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        $huge = UploadedFile::fake()->image('huge.jpg')->size(
            (int) (PaymentDocumentService::MAX_BYTES / 1024) + 1024
        );

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$huge],
            ])
            ->assertStatus(422);
    }

    // ---- who can see the evidence ----------------------------------------

    /**
     * A bank slip carries an account number and a name. Another member must
     * never reach it.
     */
    public function test_a_member_cannot_read_another_members_document(): void
    {
        [$ownerId, $ownerToken, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($ownerToken) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip()],
            ])
            ->json('data.id');

        $strangerToken = $this->inTenant(fn () => $this->makeMember(['password' => 'x'])
            ->createToken('test', ['member.payments.view'])->plainTextToken);

        $this->withHeaders($this->headers($strangerToken))
            ->getJson("/api/v1/payments/{$paymentId}/documents/0")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_OWNER');
    }

    public function test_the_member_who_uploaded_it_can_read_it_back(): void
    {
        [$ownerId, $ownerToken, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($ownerToken) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip()],
            ])
            ->json('data.id');

        $this->withHeaders($this->headers($ownerToken))
            ->get("/api/v1/payments/{$paymentId}/documents/0")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    /**
     * Staff must be able to see what they are approving against - that is the
     * entire point of the upload.
     */
    public function test_staff_can_read_a_members_document_and_see_it_in_the_queue(): void
    {
        [$memberId, $memberToken, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($memberToken) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip('deposit.jpg')],
            ])
            ->json('data.id');

        $staffToken = $this->staffToken();

        $this->withHeaders($this->headers($staffToken))
            ->getJson('/api/v1/staff/payments/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonPath('data.0.document_count', 1);

        $this->withHeaders($this->headers($staffToken))
            ->getJson("/api/v1/payments/{$paymentId}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.original_name', 'deposit.jpg');

        $this->withHeaders($this->headers($staffToken))
            ->get("/api/v1/payments/{$paymentId}/documents/0")
            ->assertOk();
    }

    // ---- storage ----------------------------------------------------------

    /**
     * NFR-SEC-4: never a public path, and never the member's own filename as a
     * path component - that is untrusted input.
     */
    public function test_documents_are_stored_privately_under_a_generated_name(): void
    {
        [$memberId, $token, $assign] = $this->memberWithDues();

        $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip('../../etc/passwd.jpg')],
            ])
            ->assertStatus(201);

        $this->inTenant(function () {
            $payment = PaymentInfo::firstOrFail();
            $path = $payment->documents[0]['path'];

            $this->assertStringStartsWith('payment-documents/', $path);
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringNotContainsString('passwd', $path);

            $this->assertTrue(Storage::disk(PaymentDocumentService::DISK)->exists($path));
        });
    }

    /**
     * The full journey the decision enables: member uploads a slip, staff
     * approve against it, the ledger posts.
     */
    public function test_a_manual_payment_with_a_slip_completes_end_to_end(): void
    {
        [$memberId, $memberToken, $assign] = $this->memberWithDues();

        $paymentId = $this->withHeaders($this->headers($memberToken) + ['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
                'documents' => [$this->slip()],
            ])
            ->json('data.id');

        $staffToken = $this->staffToken();
        $ledgerId = $this->inTenant(fn () => \App\Models\Tenant\Ledger::where('name', 'Cash')->value('id'));

        $this->withHeaders($this->headers($staffToken))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$paymentId],
                'decision' => 'completed',
                'ledger_id' => $ledgerId,
            ])
            ->assertOk()
            ->assertJsonPath('data.decided', 1);

        $this->inTenant(function () use ($paymentId, $assign) {
            $this->assertSame(PaymentInfo::STATUS_COMPLETED, PaymentInfo::find($paymentId)->status);
            $this->assertSame(FeeAssign::STATUS_PAID, FeeAssign::find($assign->id)->status);

            // The evidence survives the approval - it is the record of why.
            $this->assertCount(1, PaymentInfo::find($paymentId)->documents);
        });
    }
}
