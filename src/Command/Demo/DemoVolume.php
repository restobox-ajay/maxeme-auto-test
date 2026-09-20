<?php

declare(strict_types=1);

namespace App\Command\Demo;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The shared toolkit of `app:seed-demo-volume` and its bundle steps: one seeded random source, the
 * run token, dates spread over the past year, and the word lists realistic names are made from.
 *
 * ## Why the steps share a run token rather than each inventing their own
 *
 * The movement layer is idempotent by `client_operation_id`, which is UNIQUE. A volume run posts
 * hundreds of movements, and a second run must post hundreds more rather than being told "already
 * applied" and silently doing nothing. So every operation id a volume step hands a service is
 * `vol-<run token>-<counter>`, the token is minted once per `app:seed-demo-volume` invocation and
 * handed to each step, and two runs can never collide.
 *
 * The same token seeds the random source, so a run can be replayed exactly with `--run=<token>`
 * against a copy of the same database — useful when a step refuses something and the question is
 * why.
 *
 * ## Why a year
 *
 * List screens default to recent-first and most of them filter by date. Rows that all carry today's
 * date make every date filter look broken (everything or nothing), so document dates are spread
 * across the past twelve months and skewed towards the recent end, the way a real ledger fills.
 */
final class DemoVolume
{
    /** Operation ids are VARCHAR(64); the token stays short so a suffix always fits. */
    private const TOKEN_LENGTH = 10;

    public readonly string $run;

    private Randomizer $random;

    private int $sequence = 0;

    /**
     * @param string $step which step is drawing from this source; each step gets its own random
     *                     sequence and its own operation-id namespace under the shared run token
     */
    public function __construct(?string $run = null, private readonly string $step = 'sell')
    {
        $run = $run !== null ? (string) preg_replace('/[^a-z0-9]/', '', strtolower($run)) : '';
        $this->run = $run !== ''
            ? substr($run, 0, self::TOKEN_LENGTH)
            : substr(date('ymdHis') . bin2hex(random_bytes(2)), 0, self::TOKEN_LENGTH);
        $this->random = new Randomizer(new Mt19937(crc32($this->run . '/' . $this->step)));
    }

    /** A fresh idempotency key for one service call, unique across runs and steps. */
    public function operationId(string $what): string
    {
        return substr(sprintf('vol-%s-%s-%s-%d', $this->run, $this->step, $what, ++$this->sequence), 0, 64);
    }

    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : $this->random->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->random->getFloat(0, 1) < $probability;
    }

    public function float(float $min, float $max): float
    {
        return $max <= $min ? $min : $this->random->getFloat($min, $max);
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \LogicException('Cannot pick from an empty list.');
        }

        $values = array_values($items);

        return $values[$this->random->getInt(0, \count($values) - 1)];
    }

    /**
     * Up to $count distinct items, in random order.
     *
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return list<T>
     */
    public function sample(array $items, int $count): array
    {
        $values = array_values($items);
        if ($values === [] || $count <= 0) {
            return [];
        }

        $keys = $this->random->pickArrayKeys($values, min($count, \count($values)));
        $picked = array_map(static fn (int $k): mixed => $values[$k], $keys);

        return $this->random->shuffleArray($picked);
    }

    /**
     * One key of $weights, chosen in proportion to its weight.
     *
     * @param array<string, int> $weights
     */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = $this->random->getInt(1, max(1, $total));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * A number, chosen in proportion to its weight: [number => weight].
     *
     * @param array<int, int> $weights
     */
    public function weightedInt(array $weights): int
    {
        return (int) $this->weighted(array_combine(array_map('strval', array_keys($weights)), $weights));
    }

    /**
     * Days before today, spread over the past year and skewed recent: half of all documents land in
     * the last four months, which is roughly how a live ledger looks.
     */
    public function daysAgo(int $max = 365): int
    {
        $u = $this->random->getFloat(0, 1);

        return (int) floor($u * $u * $max);
    }

    /** A 'Y-m-d' date $daysAgo before today (never in the future). */
    public function date(int $daysAgo): string
    {
        return (new \DateTimeImmutable(sprintf('-%d days', max(0, $daysAgo))))->format('Y-m-d');
    }

    /** A timestamp on the given day, during business hours. */
    public function at(int $daysAgo): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(sprintf('-%d days', max(0, $daysAgo))))
            ->setTime($this->int(7, 17), $this->int(0, 59));
    }

    public static function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    public function firstName(): string
    {
        return $this->pick(self::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return $this->pick(self::LAST_NAMES);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} street line, city, province, postal code */
    public function canadianAddress(): array
    {
        [$city, $province, $postalPrefix] = $this->pick(self::CITIES);

        return [
            sprintf('%d %s %s', $this->int(10, 9800), $this->pick(self::STREETS), $this->pick(['Street', 'Avenue', 'Road', 'Way', 'Drive', 'Boulevard', 'Crescent'])),
            $city,
            $province,
            sprintf('%s%d%s %d%s%d', $postalPrefix, $this->int(1, 9), $this->letter(), $this->int(1, 9), $this->letter(), $this->int(1, 9)),
        ];
    }

    public function phone(): string
    {
        return sprintf('%s-555-%04d', $this->pick(['604', '778', '250', '236', '403', '587', '780', '825']), $this->int(100, 9999));
    }

    public function letter(): string
    {
        return $this->pick(['A', 'B', 'C', 'E', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'S', 'T', 'V', 'W', 'X', 'Y', 'Z']);
    }

    /** A lower-case domain made of a name, e.g. "Harbourline Tire & Auto Ltd." -> "harbourlinetire.example". */
    public static function domainFor(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, \PREG_SPLIT_NO_EMPTY) ?: ['demo'];

        return implode('', \array_slice($words, 0, 2)) . '.example';
    }

    public const NAME_PREFIXES = [
        'Harbourline', 'Copperfield', 'Northgate', 'Ridgeway', 'Cormorant', 'Summit', 'Granite', 'Cedarview',
        'Maplewood', 'Pinecrest', 'Riverbend', 'Stonebridge', 'Westfield', 'Eastlake', 'Silverline', 'Ironwood',
        'Blackrock', 'Redcliff', 'Bluewater', 'Goldstream', 'Highland', 'Lakeshore', 'Fraser', 'Kootenay',
        'Okanagan', 'Prairie', 'Chinook', 'Aurora', 'Tamarack', 'Fireweed', 'Coastline', 'Evergreen',
        'Rockridge', 'Trailhead', 'Milestone', 'Crossroads', 'Keystone', 'Beacon', 'Anchor', 'Frontier',
        'Heritage', 'Pioneer', 'Sterling', 'Pacific', 'Atlantic', 'Laurentian', 'Muskoka', 'Algonquin',
    ];

    public const CUSTOMER_TRADES = [
        'Tire & Auto', 'Auto Service', 'Fleet Services', 'Wheel Works', 'Motors', 'Garage', 'Truck Centre',
        'Automotive', 'Tire Depot', 'Tire & Alignment', 'Auto Repair', 'Transport', 'Towing', 'Car Care',
    ];

    public const VENDOR_TRADES = [
        'Tire Distributors', 'Wheel Supply', 'Auto Parts Wholesale', 'Rubber Co.', 'Industrial Supply',
        'Tire Import Co.', 'Shop Supply', 'Fastener Supply', 'Automotive Distribution', 'Tread Co.',
    ];

    public const LEGAL_SUFFIXES = ['Ltd.', 'Inc.', 'Co.', 'Corp.', '', '', 'LLP', 'Group'];

    public const FIRST_NAMES = [
        'Priya', 'Dan', 'Sofia', 'Tom', 'Ana', 'Marc', 'Dana', 'Liam', 'Noah', 'Emma', 'Olivia', 'Chloe',
        'Ethan', 'Mia', 'Lucas', 'Aisha', 'Ravi', 'Mei', 'Jun', 'Kenji', 'Fatima', 'Omar', 'Hannah', 'Owen',
        'Isabelle', 'Mathieu', 'Gabriel', 'Zoe', 'Arjun', 'Simran', 'Tariq', 'Leah', 'Connor', 'Nora', 'Wei',
        'Carlos', 'Lucia', 'Andre', 'Julie', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Avery',
    ];

    public const LAST_NAMES = [
        'Raman', 'Okafor', 'Bergeron', 'Whitfield', 'Silva', 'Belanger', 'Tran', 'Nguyen', 'Singh', 'Patel',
        'Chen', 'Wong', 'Li', 'Kim', 'Park', 'Martin', 'Roy', 'Gagnon', 'Tremblay', 'Cote', 'Bouchard',
        'Smith', 'Brown', 'Wilson', 'MacDonald', 'Campbell', 'Anderson', 'Taylor', 'Thompson', 'Mensah',
        'Haddad', 'Kowalski', 'Novak', 'Rossi', 'Moreau', 'Fraser', 'Stewart', 'Reid', 'Clarke', 'Dubois',
    ];

    public const STREETS = [
        'Industrial', 'Commerce', 'Harbour', 'Kingsway', 'Fraser', 'Main', 'Queen', 'King', 'Victoria',
        'Dundas', 'Bloor', 'Portage', 'Jasper', 'Macleod', 'Granville', 'Cambie', 'Marine', 'Dock', 'Airport',
        'Mill', 'Lakeshore', 'Highfield', 'Meadowvale', 'Annacis', 'Riverside', 'Station', 'Railway',
    ];

    /**
     * city, province, first letter of its postal FSA.
     *
     * British Columbia and Alberta only, deliberately. The tax calculators create a province's
     * `sales_tax` rows the first time they meet an address there (CanadaSimpleTaxCalculator::
     * ensureProvinceRows), and a demo box configured for BC already has exactly the rows these two
     * need — BC PST and federal GST. Addresses further east would make this seeder the reason a
     * Saskatchewan PST row appeared in Settings, which is reference data it has no business adding.
     */
    public const CITIES = [
        ['Vancouver', 'BC', 'V'], ['Burnaby', 'BC', 'V'], ['Surrey', 'BC', 'V'], ['Richmond', 'BC', 'V'],
        ['Kelowna', 'BC', 'V'], ['Kamloops', 'BC', 'V'], ['Nanaimo', 'BC', 'V'], ['Prince George', 'BC', 'V'],
        ['Abbotsford', 'BC', 'V'], ['Langley', 'BC', 'V'], ['Victoria', 'BC', 'V'], ['Chilliwack', 'BC', 'V'],
        ['Calgary', 'AB', 'T'], ['Edmonton', 'AB', 'T'], ['Red Deer', 'AB', 'T'], ['Lethbridge', 'AB', 'T'],
        ['Grande Prairie', 'AB', 'T'], ['Medicine Hat', 'AB', 'T'],
    ];

    public const PAYMENT_METHODS = ['EFT', 'Cheque', 'Credit Card', 'E-Transfer', 'Wire', 'Cash'];

    public const REFUND_METHODS = ['Bank Transfer', 'Check', 'Credit Card', 'Cash', 'E-Transfer'];

    public const RECEIVERS = ['R. Mensah', 'L. Tran', 'A. Bakshi', 'M. Cortez', 'J. Novak', 'K. Haddad', 'S. Roy'];
}
