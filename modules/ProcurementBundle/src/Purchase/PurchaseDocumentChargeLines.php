<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Contract\Tax\TaxContext;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;

/**
 * The charge-row rules for the purchase order form's bottom `[line type] + Add Line` control
 * (#655).
 *
 * Rows post as `charge_lines[i][label|amount|type|slug|taxClass|placement]` — the same post shape
 * the sell side's forms use, because the shape was asked to be mirrored and because a shared shape
 * is what would make a later consolidation possible at all. The CODE is this bundle's own: see
 * `PurchaseChargeLine` for why the fee halves of the two sides are parallel rather than shared.
 *
 * A row's `type` says what it becomes:
 *
 *   freight -> a PurchaseChargeLine of TYPE_FREIGHT in `purchase_order.charge_lines`
 *   fee     -> a PurchaseChargeLine of TYPE_FEE in the same snapshot
 *   tax     -> a manual TaxLine in `purchase_order.tax_lines`, through PurchaseTaxBreakdown
 *
 * Nothing is stored in between. There is no `purchase_order_charge_line` table, for the reasons
 * `PurchaseChargeLineSnapshot` sets out.
 *
 * ## A type this vocabulary does not have is refused, never filed somewhere
 *
 * Straight from the sell side's scar tissue, quoted because it is worth not re-learning: until fees
 * had a type of their own, *"anything that is not shipping"* was read as tax, which silently turned
 * a one-off charge into a tax line — wrong bucket, wrong tax treatment, wrong totals, no error.
 * Guessing caused that, so nothing is guessed here, including for a row naming no type at all.
 *
 * @phpstan-type ChargeRow array{label: string, amount: float, type: string, slug: string, taxClass?: string, placement?: string}
 */
final class PurchaseDocumentChargeLines
{
    /** A row that becomes a manual TaxLine. Freight and fee rows carry PurchaseChargeLine's own. */
    public const TYPE_TAX = 'tax';

    /** @return list<string> */
    public static function types(): array
    {
        return [PurchaseChargeLine::TYPE_FREIGHT, PurchaseChargeLine::TYPE_FEE, self::TYPE_TAX];
    }

    /**
     * @return list<ChargeRow>
     *
     * @throws \InvalidArgumentException when a row names a type this vocabulary does not have.
     *                                   The save path asks errorFor() at the door, so reaching this
     *                                   throw means a caller skipped that check.
     */
    public static function normalize(mixed $chargesRaw): array
    {
        if (!is_array($chargesRaw)) {
            return [];
        }

        $rows = [];
        foreach ($chargesRaw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $amount = (float) (preg_replace('/[^0-9.\-]/', '', (string) ($row['amount'] ?? '')) ?? '0');
            $type = strtolower(trim((string) ($row['type'] ?? '')));

            // An empty row is one the admin never filled in, not a row with a bad type: the form
            // renders spare rows for the no-JS path and expects them ignored.
            if ($label === '' && $amount <= 0.0) {
                continue;
            }
            if ($amount < 0.0) {
                $amount = 0.0;
            }
            if (!in_array($type, self::types(), true)) {
                throw new \InvalidArgumentException(self::unknownTypeMessage($label, $type));
            }

            $normalized = [
                'label' => $label !== '' ? $label : self::defaultLabel($type),
                'amount' => $amount,
                'type' => $type,
                // Every kind becomes a line, and a line wants a reporting key that survives the
                // admin relabelling it. Blank derives it from the label at the point of building.
                'slug' => trim((string) ($row['slug'] ?? '')),
            ];

            // A fee row states its own tax class and placement: unlike a freight row, which takes
            // the document's highest goods class at save, it has no definition to inherit from.
            if ($type === PurchaseChargeLine::TYPE_FEE) {
                $normalized['taxClass'] = self::taxClass($row);
                $normalized['placement'] = self::placement($row);
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * The posted rows with the one the no-JS ✕ asked to remove taken out.
     *
     * The rows are a posted array and every one of them posts, so the pressed button's own value —
     * its index — is the only thing that says which row was meant. An index that is not there is
     * ignored rather than treated as an error: a stale form re-submitted after the rows shifted
     * should drop nothing, not refuse the save or delete a neighbour.
     *
     * Reindexed on the way out so the remaining rows stay a dense list, which normalize() and the
     * template's `loop.index0` both assume.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int|string, mixed>
     */
    public static function withoutRemovedRow(array $rows, ?string $removeIndex): array
    {
        if ($removeIndex === null || preg_match('/^\d+$/', trim($removeIndex)) !== 1) {
            return $rows;
        }

        $index = (int) trim($removeIndex);
        if (!array_key_exists($index, $rows)) {
            return $rows;
        }

        unset($rows[$index]);

        return array_values($rows);
    }

    /**
     * The row the form's bottom `[line type] + Add Line` control asks for, read off that select's
     * own value — the server-side half of a control that must work with scripting off.
     *
     * The vocabulary is the select's own: `freight:Label`, `fee:Label`, `tax:Label`, and the three
     * `empty-*` free-text kinds. It is read here, beside normalize(), so the two cannot drift.
     *
     * An `empty-*` row starts under its type's plain default label, because normalize() drops a row
     * with no label and no amount as one nobody filled in — and the row's own label input, on the
     * page that comes straight back, exists to replace it.
     *
     * @return ChargeRow|null
     */
    public static function rowFromAddLineChoice(string $choice): ?array
    {
        $choice = trim($choice);
        if ($choice === '') {
            return null;
        }

        $colon = strpos($choice, ':');
        $kind = $colon === false ? $choice : substr($choice, 0, $colon);
        $label = trim($colon === false ? '' : substr($choice, $colon + 1));

        $type = match ($kind) {
            PurchaseChargeLine::TYPE_FREIGHT, 'empty-freight' => PurchaseChargeLine::TYPE_FREIGHT,
            PurchaseChargeLine::TYPE_FEE, 'empty-fee' => PurchaseChargeLine::TYPE_FEE,
            self::TYPE_TAX, 'empty-tax' => self::TYPE_TAX,
            default => null,
        };
        if ($type === null) {
            return null;
        }

        $row = [
            'label' => $label !== '' ? $label : self::defaultLabel($type),
            'amount' => 0.0,
            'type' => $type,
            'slug' => '',
        ];
        if ($type === PurchaseChargeLine::TYPE_FEE) {
            $row['taxClass'] = 'E';
            $row['placement'] = PurchaseChargeLine::PLACEMENT_MAIN_LINE;
        }

        return $row;
    }

    /**
     * The admin-facing reason this post's charge rows cannot become lines, or null.
     *
     * Asked at the door, before the document is touched, so a row that cannot become a line is
     * refused rather than discovered halfway through a save.
     */
    public static function errorFor(mixed $chargesRaw): ?string
    {
        try {
            self::assertValid(self::normalize($chargesRaw));
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The one rule with teeth: an after-tax charge is added once tax is settled, so taxing it would
     * tax money the breakdown above it never saw.
     *
     * The sell side enforces exactly this, in `SalesDocumentChargeLines::assertValid()`. Refused
     * rather than silently untaxed, because a fee row that reads "S" and is not taxed is a screen
     * telling the admin something false.
     *
     * @param list<ChargeRow> $charges
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(array $charges): void
    {
        foreach (self::rowsOfType($charges, PurchaseChargeLine::TYPE_FEE) as $row) {
            if (self::placement($row) === PurchaseChargeLine::PLACEMENT_AFTER_TAX && self::taxClass($row) !== 'E') {
                throw new \InvalidArgumentException(
                    'After Tax charges cannot be taxable — set the charge row\'s tax class to Exempt first.',
                );
            }
        }
    }

    /**
     * @param list<ChargeRow> $charges
     * @return list<ChargeRow>
     */
    public static function rowsOfType(array $charges, string $type): array
    {
        return array_values(array_filter(
            $charges,
            static fn (array $row): bool => ($row['type'] ?? '') === $type,
        ));
    }

    /**
     * The freight rows as charge lines.
     *
     * Every row takes the same tax class — the highest across the document's goods — because
     * freight on mixed goods is charged at the highest class among them, and because that is the
     * rule the sell side's `toShippingLines()` already states for the identical position in the
     * document. Resolved at save from the document rather than frozen when the admin typed the row,
     * so adding a taxable product afterwards still lifts the class.
     *
     * Placement is main_line for the reason freight has always sat inside the goods figure: it is
     * charged before tax and is itself taxable. Which also means the after-tax rule above cannot be
     * reached from here.
     *
     * @param list<ChargeRow> $charges
     * @return PurchaseChargeLine[]
     */
    public static function toFreightLines(array $charges, string $taxClass): array
    {
        $lines = [];
        foreach (self::rowsOfType($charges, PurchaseChargeLine::TYPE_FREIGHT) as $row) {
            $label = (string) ($row['label'] ?? '');
            $slug = self::sluggify((string) ($row['slug'] ?? '')) ?: self::sluggify($label);
            $lines[] = new PurchaseChargeLine(
                $slug !== '' ? $slug : PurchaseChargeLine::TYPE_FREIGHT,
                $label,
                $taxClass,
                (float) ($row['amount'] ?? 0),
                PurchaseChargeLine::PLACEMENT_MAIN_LINE,
                PurchaseChargeLine::TYPE_FREIGHT,
                PurchaseChargeLine::SOURCE_MANUAL,
            );
        }

        return $lines;
    }

    /**
     * The fee rows as charge lines — the one-off a vendor adds that is neither freight nor tax:
     * brokerage, duty, a pallet charge, a small-order surcharge.
     *
     * SOURCE_MANUAL because an admin read the amount off the vendor's paperwork rather than a
     * calculator deriving it. From the snapshot on they behave like any other charge.
     *
     * @param list<ChargeRow> $charges
     * @return PurchaseChargeLine[]
     */
    public static function toFeeLines(array $charges): array
    {
        // Asked again here and not only at the door: this is the last point before a bad row would
        // reach the snapshot, and a save path that forgot errorFor() should fail loudly rather than
        // freeze a line the rules forbid.
        self::assertValid($charges);

        $lines = [];
        foreach (self::rowsOfType($charges, PurchaseChargeLine::TYPE_FEE) as $row) {
            $label = (string) ($row['label'] ?? '');
            $slug = self::sluggify((string) ($row['slug'] ?? '')) ?: self::sluggify($label);
            $lines[] = new PurchaseChargeLine(
                $slug !== '' ? $slug : PurchaseChargeLine::TYPE_FEE,
                $label,
                self::taxClass($row),
                (float) ($row['amount'] ?? 0),
                self::placement($row),
                PurchaseChargeLine::TYPE_FEE,
                PurchaseChargeLine::SOURCE_MANUAL,
            );
        }

        return $lines;
    }

    /**
     * Read-path counterpart of the two above, and the whole reason a typed charge survives a
     * re-save.
     *
     * A save rebuilds the snapshot from what the form posts, so unless a saved document's charges
     * come back as rows the very next save wipes them. Only the MANUAL rows come back: a calculated
     * line is rebuilt from its own calculator every save and is not the admin's to edit here — the
     * same split `SalesDocumentChargeLines::fromFeeLines()` makes.
     *
     * @param PurchaseChargeLine[] $lines
     * @return list<ChargeRow>
     */
    public static function fromChargeLines(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if ($line->source !== PurchaseChargeLine::SOURCE_MANUAL) {
                continue;
            }

            $row = [
                'label' => $line->label,
                'amount' => $line->amount,
                'type' => $line->type,
                'slug' => $line->slug,
            ];
            if ($line->type === PurchaseChargeLine::TYPE_FEE) {
                $row['taxClass'] = $line->taxClass;
                $row['placement'] = $line->placement;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function defaultLabel(string $type): string
    {
        return match ($type) {
            PurchaseChargeLine::TYPE_FREIGHT => 'Freight',
            self::TYPE_TAX => 'Tax',
            default => 'Charge',
        };
    }

    private static function unknownTypeMessage(string $label, string $type): string
    {
        $row = $label !== '' ? sprintf('Charge row "%s"', $label) : 'A charge row';

        return $type === ''
            ? sprintf('%s has no line type. Expected freight, fee or tax.', $row)
            : sprintf('%s has an unknown line type "%s". Expected freight, fee or tax.', $row, $type);
    }

    /**
     * The document's own rule for reading a tax code, so a charge row states its class exactly the
     * way a product line does — and through the SHARED `TaxContext`, because the tax vocabulary is
     * the one thing the two sides genuinely have in common.
     *
     * Exempt is what a blank means: the only default that cannot overcharge.
     *
     * @param array<string, mixed> $row
     */
    private static function taxClass(array $row): string
    {
        return TaxContext::mapTaxCode(strtoupper(trim((string) ($row['taxClass'] ?? ''))));
    }

    /** @param array<string, mixed> $row */
    private static function placement(array $row): string
    {
        $placement = trim((string) ($row['placement'] ?? ''));

        return in_array($placement, PurchaseChargeLine::PLACEMENTS, true)
            ? $placement
            : PurchaseChargeLine::PLACEMENT_MAIN_LINE;
    }

    private static function sluggify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }
}
