<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP, checked against the RFC's own numbers.
 *
 * Writing an algorithm rather than pulling a package is only defensible if it
 * is verified against the specification's published vectors, not against
 * itself. RFC 6238 Appendix B gives codes for a known secret at known times;
 * these are those, for SHA1.
 */
class TotpTest extends TestCase
{
    private Totp $totp;

    /** The RFC's test secret: the ASCII "12345678901234567890". */
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new Totp();
        $this->secret = $this->totp->base32Encode('12345678901234567890');
    }

    /**
     * RFC 6238 Appendix B, the SHA1 rows.
     *
     * @return list<array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_vectors(int $time, string $expected): void
    {
        $step = intdiv($time, Totp::PERIOD);

        $this->assertSame($expected, $this->totp->codeAt($this->secret, $step));
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, $this->totp->base32Decode($this->totp->base32Encode($bytes)));
    }

    /** Apps display secrets in groups of four; people paste them back that way. */
    public function test_a_secret_with_spaces_still_decodes(): void
    {
        $secret = $this->totp->base32Encode('12345678901234567890');
        $spaced = trim(chunk_split($secret, 4, ' '));

        $this->assertSame(
            $this->totp->codeAt($secret, 1),
            $this->totp->codeAt($spaced, 1)
        );
    }

    public function test_it_accepts_a_code_from_the_current_step(): void
    {
        $at = 1_700_000_000;
        $code = $this->totp->codeAt($this->secret, intdiv($at, Totp::PERIOD));

        $this->assertNotNull($this->totp->verify($this->secret, $code, $at));
    }

    /** A clock a few seconds out must not lock somebody out. */
    public function test_it_accepts_one_step_either_side(): void
    {
        $at = 1_700_000_000;
        $step = intdiv($at, Totp::PERIOD);

        foreach ([-1, 1] as $offset) {
            $code = $this->totp->codeAt($this->secret, $step + $offset);

            $this->assertSame(
                $step + $offset,
                $this->totp->verify($this->secret, $code, $at),
                "A code {$offset} step away should be accepted, and report its own step."
            );
        }
    }

    /** But not a wider window - every extra step is another code a guesser may hit. */
    public function test_it_rejects_a_code_two_steps_away(): void
    {
        $at = 1_700_000_000;
        $code = $this->totp->codeAt($this->secret, intdiv($at, Totp::PERIOD) + 2);

        $this->assertNull($this->totp->verify($this->secret, $code, $at));
    }

    public function test_it_rejects_rubbish(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '000000'] as $code) {
            $this->assertNull(
                $this->totp->verify($this->secret, $code, 1_700_000_000),
                "[{$code}] should not verify."
            );
        }
    }

    /**
     * The step is returned so the caller can refuse it a second time. Without
     * that, a code stays usable for the rest of its 30 seconds.
     */
    public function test_it_reports_which_step_matched(): void
    {
        $at = 1_700_000_000;
        $step = intdiv($at, Totp::PERIOD);

        $this->assertSame($step, $this->totp->verify($this->secret, $this->totp->codeAt($this->secret, $step), $at));
    }

    public function test_the_uri_carries_what_an_authenticator_needs(): void
    {
        $uri = $this->totp->uri('ABCDEF', 'someone@example.org', 'BCS Platform');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABCDEF', $uri);
        $this->assertStringContainsString('issuer=BCS%20Platform', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
