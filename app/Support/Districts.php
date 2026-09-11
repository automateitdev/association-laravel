<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bangladesh's 64 districts.
 *
 * A LIST IN CODE, NOT A TABLE, and the legacy's table is the argument for it.
 * `disticts` (sic) holds 64 rows and 63 distinct names: **Panchagarh appears
 * twice and Rangpur is absent**. Rangpur is a divisional capital of some three
 * million people, so for the life of that system a member from Rangpur could
 * not choose their own district from the list offered to them - and nobody
 * noticed, because reference data seeded once is never read again by anybody
 * whose job is to check it.
 *
 * A table would put that same risk in every association's database, sixty-four
 * rows at a time, with no way to correct them all at once. The districts of
 * Bangladesh are not association data: they do not differ per tenant, they are
 * not edited, and when one does change it changes for everybody in the same
 * release. So they live here, where a diff can be reviewed and a test can count
 * them.
 *
 * ORDERED BY DIVISION, then alphabetically within it - which is how the country
 * is organised and how somebody looking for their own district scans a list.
 * Alphabetical across all 64 puts Bandarban next to Barguna, five hundred
 * kilometres apart.
 */
final class Districts
{
    /**
     * @var array<string, list<string>>
     */
    public const BY_DIVISION = [
        'Barishal' => [
            'Barguna', 'Barishal', 'Bhola', 'Jhalakathi', 'Patuakhali', 'Pirojpur',
        ],
        'Chattogram' => [
            'Bandarban', 'Brahmanbaria', 'Chandpur', 'Chattogram', "Cox's Bazar",
            'Cumilla', 'Feni', 'Khagrachhari', 'Lakshmipur', 'Noakhali', 'Rangamati',
        ],
        'Dhaka' => [
            'Dhaka', 'Faridpur', 'Gazipur', 'Gopalganj', 'Kishoreganj', 'Madaripur',
            'Manikganj', 'Munshiganj', 'Narayanganj', 'Narsingdi', 'Rajbari',
            'Shariatpur', 'Tangail',
        ],
        'Khulna' => [
            'Bagerhat', 'Chuadanga', 'Jashore', 'Jhenaidah', 'Khulna', 'Kushtia',
            'Magura', 'Meherpur', 'Narail', 'Satkhira',
        ],
        'Mymensingh' => [
            'Jamalpur', 'Mymensingh', 'Netrokona', 'Sherpur',
        ],
        'Rajshahi' => [
            'Bogura', 'Chapainawabganj', 'Joypurhat', 'Naogaon', 'Natore', 'Pabna',
            'Rajshahi', 'Sirajganj',
        ],
        'Rangpur' => [
            'Dinajpur', 'Gaibandha', 'Kurigram', 'Lalmonirhat', 'Nilphamari',
            'Panchagarh', 'Rangpur', 'Thakurgaon',
        ],
        'Sylhet' => [
            'Habiganj', 'Moulvibazar', 'Sunamganj', 'Sylhet',
        ],
    ];

    /**
     * Every district, flat.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::BY_DIVISION));
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * The legacy spelling of a district, mapped to ours.
     *
     * ONLY WHAT THE PRODUCTION DATA ACTUALLY CONTAINS. An importer needs this
     * for the handful of names the legacy table spells differently; inventing a
     * general fuzzy matcher for a 64-item list would be a way to silently file
     * somebody in the wrong district.
     *
     * @var array<string, string>
     */
    public const LEGACY_SPELLINGS = [
        'Coxsbazar' => "Cox's Bazar",
    ];

    public static function fromLegacy(string $name): ?string
    {
        $name = trim($name);
        $name = self::LEGACY_SPELLINGS[$name] ?? $name;

        return self::exists($name) ? $name : null;
    }
}
