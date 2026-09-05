<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * WHY THIS IS NOT A PACKAGE. TOTP is HMAC-SHA1 over a counter plus dynamic
 * truncation - roughly forty lines, all of it specified. Adding a dependency to
 * this application means adding it to the supply chain of a system that holds
 * members' savings, and the trade is not worth it for an algorithm that has not
 * changed since 2011 and is verified against the RFC's own published vectors
 * (see TotpTest).
 *
 * The parts that are easy to get wrong, and how they are handled:
 *
 *   - COMPARISON is `hash_equals`, not `===`. A byte-by-byte comparison that
 *     returns early leaks, over enough attempts, which digits were right.
 *   - The WINDOW is one step either side. Zero rejects a user whose clock is a
 *     second out; a wide window multiplies the codes a guesser may hit.
 *   - REPLAY is the caller's job, and Operator does it: this class reports
 *     WHICH step matched so the caller can refuse to accept that step twice.
 *     Without that, a code is reusable for its whole 30 seconds.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** One step either side, so a slightly wrong clock still works. */
    public const WINDOW = 1;

    /** 160 bits, the RFC 4226 recommendation for HMAC-SHA1. */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Verify a code, returning the time step it matched, or null.
     *
     * The step matters: the caller must record it and refuse it next time, or
     * the same code works for the rest of its window.
     */
    public function verify(string $secret, string $code, ?int $at = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $current = intdiv($at ?? time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;

            // hash_equals, always: a short-circuiting comparison leaks which
            // digits were right to anybody willing to make enough attempts.
            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAt(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);

        // The counter is 8 bytes, big-endian. pack('J') is 64-bit big-endian.
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        // Dynamic truncation, RFC 4226 §5.3: the low nibble of the last byte
        // picks the offset to read four bytes from.
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * The `otpauth://` URI an authenticator app reads.
     *
     * Issuer appears twice by convention - in the label and as a parameter -
     * because apps differ on which they display.
     */
    public function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 5) as $chunk) {
            $output .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $output;
    }

    public function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        // Padding and spacing are stripped: apps display secrets in groups of
        // four and people paste them back in that form.
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos($alphabet, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
