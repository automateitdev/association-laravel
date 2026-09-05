<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Common\ErrorCorrectionLevel;

/**
 * A QR code drawn in a terminal.
 *
 * WHY THIS EXISTS. Enrolling a second factor meant reading a 32-character
 * base32 key off a screen and typing it into a phone. That went wrong in every
 * way it could: the key was mistyped, an earlier run's key was entered against
 * a later run's secret, and at one point an example number from the
 * instructions was entered instead of a code. None of it was the operator's
 * fault - it was a transcription task nobody should be given.
 *
 * WHY NOT A LINK TO A QR SERVICE. The `otpauth://` URI contains the secret. A
 * QR generated on somebody else's website is a second factor handed to a
 * stranger, and it is exactly the shortcut a person under time pressure takes.
 * Drawing it locally removes the temptation along with the need.
 *
 * WHY A DEPENDENCY HERE AND NOT FOR TOTP. `Totp` is hand-written on the
 * argument that the algorithm is forty lines and has not changed since 2011.
 * That argument cuts the other way for QR: Reed-Solomon error correction, eight
 * mask patterns and forty version sizes are not forty lines, and a hand-rolled
 * encoder would produce codes that scan on one phone and not another.
 *
 * HALF-BLOCKS, TWO ROWS PER CHARACTER. A terminal cell is about twice as tall
 * as it is wide, so one module per cell renders a stretched code that many
 * scanners refuse. Packing two module rows into one character with the upper
 * half-block gives roughly square modules and halves the height, which matters
 * when the alternative does not fit on screen.
 */
class TerminalQr
{
    private const UPPER = "\u{2580}";   // ▀ upper half set, lower clear
    private const LOWER = "\u{2584}";   // ▄ lower half set, upper clear
    private const FULL = "\u{2588}";    // █ both set
    private const EMPTY = ' ';          // neither

    /**
     * @return list<string> one string per printed line
     */
    public static function render(string $text, int $quietZone = 2): array
    {
        /*
         * Error correction L, not H. The payload is short, the code is being
         * read off a screen a foot away rather than a crumpled poster, and a
         * higher level buys robustness nobody needs at the cost of a physically
         * larger code that may not fit the window.
         */
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::L())->getMatrix();

        $width = $matrix->getWidth();
        $height = $matrix->getHeight();

        /*
         * INVERTED, and this is not a detail. A QR scanner expects dark modules
         * on a light background. Most terminals are dark, so drawing set
         * modules as blocks produces a photographic negative that many phones
         * will not read at all. Printing the CLEAR modules as blocks renders
         * light-on-dark, which scans.
         */
        $isDark = function (int $x, int $y) use ($matrix, $width, $height): bool {
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                return false;   // quiet zone: always light
            }

            return $matrix->get($x, $y) === 1;
        };

        $lines = [];

        // Two module rows per text row, hence the step of two.
        for ($y = -$quietZone; $y < $height + $quietZone; $y += 2) {
            $line = '';

            for ($x = -$quietZone; $x < $width + $quietZone; $x++) {
                $top = ! $isDark($x, $y);
                $bottom = ! $isDark($x, $y + 1);

                $line .= match (true) {
                    $top && $bottom => self::FULL,
                    $top => self::UPPER,
                    $bottom => self::LOWER,
                    default => self::EMPTY,
                };
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
