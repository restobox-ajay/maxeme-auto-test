<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UnitOfMeasureRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The measurement system — global, one row per unit, shared by every product (#601 #643, #659).
 *
 * ## The only place a conversion is defined
 *
 * Every ratio in the application is here, for every family INCLUDING quantity. `BOX-12` holds
 * twelve and `BOX-24` holds twenty-four, and they are two ROWS — NetSuite's model, which bakes the
 * count into the term ("assign Box a conversion rate of 8 and Pallet a conversion rate of 192")
 * rather than asking for a twelve to be typed onto every product that ships in twelves.
 *
 * #659 retired `product_packaging_unit`, which performed the same arithmetic with a second
 * vocabulary, a second screen and a second set of rules. Two tables answering "how many of the base
 * does this hold" is two answers that can disagree, and nobody could tell from a document line which
 * of them they were looking at. There is one now, and there must be no second.
 *
 * The cost this model carries is a vocabulary with no ceiling: every distinct pack size is a term.
 * {@see ProductAvailableUnit} is what stops it reaching the user — long in the catalogue, short
 * everywhere somebody works.
 *
 * ## What a row says
 *
 * - **family** — which of the four measurable things it measures. Conversion is only ever defined
 *   inside a family: there is no factor from `kg` to `each`, and inventing one is how a system ends
 *   up adding litres to kilograms.
 * - **factorToFamilyBase** — how many of the family's base unit this one holds. `kg` is 1000 `g`;
 *   `each` is 1. The family base is simply the unit whose factor is 1; nothing marks it, because
 *   nothing needs to — every conversion inside a family is a ratio of two factors.
 * - **roundingPrecision** — the smallest step a quantity in this unit may take. `each` ships 1 and
 *   therefore rejects 2.5; `kg` ships 0.001 and accepts it. Business Central's per-unit
 *   Unit-Amount Rounding Precision, and the reason the quantity COLUMNS do not have to enforce
 *   whole numbers: half a box of twelve is six eaches, so fractions come from measurement rather
 *   than from the size of the term.
 *
 * ## A row freezes once anything points at it
 *
 * `product_core.unit_id`, `product_available_unit.unit_id`, `product_core.default_unit_id` and
 * `unit_id` on fourteen document and pick tables all point here. Restating the factor on a row any
 * of them names would restate every figure derived through it, at once, with nothing recorded and
 * nothing to reconcile — so {@see \App\Service\Uom\UnitOfMeasureService} refuses it and a term
 * that needs a different ratio is a NEW term. That refusal is what makes frozen history free: no
 * factor ever has to be copied onto a document line "for safety".
 */
#[ORM\Entity(repositoryClass: UnitOfMeasureRepository::class)]
#[ORM\Table(name: 'unit_of_measure')]
#[ORM\UniqueConstraint(name: 'uniq_unit_of_measure_code', fields: ['code'])]
class UnitOfMeasure
{
    /** Countable things — each, box, pair. The family every seeded demo unit belongs to. */
    public const FAMILY_QUANTITY = 'quantity';

    public const FAMILY_WEIGHT = 'weight';

    public const FAMILY_VOLUME = 'volume';

    public const FAMILY_LENGTH = 'length';

    /** @return list<string> */
    public static function families(): array
    {
        return [self::FAMILY_QUANTITY, self::FAMILY_WEIGHT, self::FAMILY_VOLUME, self::FAMILY_LENGTH];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The short symbol an admin types and a document prints — `EA`, `KG`, `L`.
     *
     * Unique, and upper-cased on the way in: `kg` and `KG` are one unit, and two rows for it would
     * make "which one does this product mean" a real question with no answer.
     */
    #[ORM\Column(length: 16)]
    private string $code = '';

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 16, options: ['default' => self::FAMILY_QUANTITY])]
    private string $family = self::FAMILY_QUANTITY;

    /**
     * How many of the family's base unit one of these holds.
     *
     * NUMERIC(18,6), the precision #601 fixes for conversion factors, because factors are where
     * precision is really lost: `lb -> kg` is 0.45359237 and a two-decimal column would silently
     * make it 0.45.
     */
    #[ORM\Column(name: 'factor_to_family_base', type: 'decimal', precision: 18, scale: 6, options: ['default' => '1.000000'])]
    private string $factorToFamilyBase = '1.000000';

    /**
     * The smallest step a quantity in this unit may take. 1 means whole numbers only.
     *
     * Enforced per unit rather than by the column type, which is what lets one product reject 2.5
     * (`each`) while another stores it happily (`kg`) in the same NUMERIC column.
     */
    #[ORM\Column(name: 'rounding_precision', type: 'decimal', precision: 18, scale: 6, options: ['default' => '1.000000'])]
    private string $roundingPrecision = '1.000000';

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getName(): string { return $this->name; }

    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getFamily(): string { return $this->family; }

    /** Anything unrecognised falls back to `quantity` — the family a countable thing belongs to. */
    public function setFamily(string $family): self
    {
        $this->family = \in_array($family, self::families(), true) ? $family : self::FAMILY_QUANTITY;

        return $this;
    }

    /**
     * Normalised on the way out as well as in: SQLite's NUMERIC affinity stores `0.453592` and hands
     * back `0.453592`, but `1.000000` comes back as `1` — so a fresh object and a hydrated one would
     * otherwise disagree about the same number. Formatting only.
     */
    public function getFactorToFamilyBase(): string { return self::normalizeFactor($this->factorToFamilyBase); }

    public function setFactorToFamilyBase(string $factor): self
    {
        $this->factorToFamilyBase = self::normalizeFactor($factor);

        return $this;
    }

    public function getRoundingPrecision(): string { return self::normalizeFactor($this->roundingPrecision); }

    public function setRoundingPrecision(string $precision): self
    {
        $this->roundingPrecision = self::normalizeFactor($precision);

        return $this;
    }

    /**
     * Whether a quantity is expressible in this unit.
     *
     * `each` (precision 1) rejects 2.5; `kg` (precision 0.001) accepts it. Compared as scaled
     * integers rather than with fmod(), because fmod(0.3, 0.1) is 0.0999999… in binary floating
     * point and a unit that rejected 0.3 kg would be indistinguishable from a bug.
     */
    public function accepts(string $quantity): bool
    {
        $step = self::toScaledInt($this->roundingPrecision);
        if ($step <= 0) {
            return true;
        }

        return self::toScaledInt($quantity) % $step === 0;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution, and the select on the product form. */
    public function getLabel(): string
    {
        if ($this->code === '' && $this->name === '') {
            return 'Unit #' . (string) ($this->id ?? 0);
        }

        return $this->name === '' ? $this->code : sprintf('%s — %s', $this->code, $this->name);
    }

    /** Whole numbers only: the answer for `each`, and what makes a count resolve cleanly. */
    public function isWholeNumbersOnly(): bool
    {
        return self::toScaledInt($this->roundingPrecision) === 1_000_000;
    }

    /** Six decimal places, zero-padded, so two equal factors compare equal as strings. */
    private static function normalizeFactor(string $value): string
    {
        $value = trim($value);

        return number_format((float) ($value === '' ? '0' : $value), 6, '.', '');
    }

    /** A decimal string as millionths, which is exactly the scale the columns store. */
    private static function toScaledInt(string $value): int
    {
        return (int) round(((float) $value) * 1_000_000);
    }
}
