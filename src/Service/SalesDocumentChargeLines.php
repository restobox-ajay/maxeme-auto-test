<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Fee\FeeLine;
use App\Contract\Tax\TaxContext;
use App\Entity\Fee;
use App\Validation\Constraint\ValidChargeRows;
use Symfony\Component\Validator\Validation;

/**
 * The charge-row rules shared by the order and estimate forms' bottom `[line type] + Add Line`
 * control: rows posted as charge_lines[i][label|amount|type|slug|taxClass|placement].
 *
 * A row's `type` says what it becomes — `shipping` a FeeLine of TYPE_SHIPPING in the fee_lines
 * snapshot, `fee` a FeeLine of TYPE_FEE in the same snapshot, `tax` a manual TaxLine in tax_lines.
 * Nothing is stored in between: there is no charge_lines column any more, and this class no longer
 * has one to be named after (issue #165 step 8). The post array, this class and a row's fields are
 * named for the line each row becomes, which is the one vocabulary — `type`, `label`, `amount`,
 * `slug` — that the stored line already uses. `zone` lost to `type` in step 7 for the same reason.
 *
 * `shipping` and `fee` are FeeLine::TYPE_SHIPPING and FeeLine::TYPE_FEE themselves rather than
 * second constants spelling the same words. `tax` has no FeeLine counterpart on purpose — a tax
 * line is not a fee line — so the one value in this vocabulary that does not become a FeeLine is
 * the only one declared here.
 *
 * A type this vocabulary does not have is refused, not filed somewhere. Until fees had a type of
 * their own, "anything that is not shipping" was read as tax, which silently turned a one-off
 * charge into a tax line: wrong bucket, wrong tax treatment, wrong totals, no error. Guessing is
 * what caused that, so nothing is guessed here — including for a row that names no type at all,
 * which is a post the two forms cannot produce.
 *
 * @phpstan-type ChargeRow array{label: string, amount: float, type: string, slug?: string, taxClass?: string, placement?: string}
 */
final class SalesDocumentChargeLines
{
    /** A row that becomes a manual TaxLine. Shipping and fee rows carry FeeLine's own constants. */
    public const TYPE_TAX = 'tax';

    /**
     * @return list<ChargeRow>
     *
     * @throws \InvalidArgumentException when a row names a type this vocabulary does not have.
     *                                   Save paths ask errorFor() at the door instead, so by the
     *                                   time they get here the rows are already known good and a
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
            $amountRaw = (string) ($row['amount'] ?? '');
            $amount = (float) (preg_replace('/[^0-9.\\-]/', '', $amountRaw) ?? '0');
            $type = strtolower(trim((string) ($row['type'] ?? '')));

            // An empty row is a row the admin never filled in, not a row with a bad type: the
            // forms render spare rows for the no-JS path and expect them to be ignored.
            if ($label === '' && $amount <= 0) {
                continue;
            }
            if ($amount < 0) {
                $amount = 0.0;
            }
            if (!in_array($type, [FeeLine::TYPE_SHIPPING, FeeLine::TYPE_FEE, self::TYPE_TAX], true)) {
                throw new \InvalidArgumentException(self::unknownTypeMessage($label, $type));
            }

            $normalized = [
                'label' => $label !== '' ? $label : self::defaultLabel($type),
                'amount' => $amount,
                'type' => $type,
            ];
            // Every kind becomes a line, and a line wants a reporting key that survives the admin
            // relabelling it. Blank means "derive it from the label" at the point of building.
            $normalized['slug'] = trim((string) ($row['slug'] ?? ''));

            // A fee line has a tax class and a placement. A shipping row takes both from the
            // document at save (see toShippingLines()) and a tax row has neither, but an admin-typed
            // fee has no Fee definition to inherit them from, so the row is the only place they can
            // come from.
            if ($type === FeeLine::TYPE_FEE) {
                $normalized['taxClass'] = self::taxClass($row);
                $normalized['placement'] = self::placement($row);
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * The posted charge rows with the one the no-JS ✕ asked to remove taken out.
     *
     * The rows are a posted array and every one of them posts, so the pressed button's own value —
     * its index — is the only thing that says which row was meant. An index that isn't there is
     * ignored rather than treated as an error: a stale form re-submitted after the rows shifted
     * should drop nothing, not refuse the save or delete a neighbour.
     *
     * Reindexed on the way out so the remaining rows stay a dense list, which is what normalize()
     * and the templates' `loop.index0` both assume.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int|string, mixed>
     */
    public static function withoutRemovedRow(array $rows, ?string $removeIndex): array
    {
        if ($removeIndex === null || !preg_match('/^\d+$/', trim($removeIndex))) {
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
     * The charge row the forms' bottom `[line type] + Add Line` control asks for, read off that
     * select's own value — the server-side twin of app.js's `.js-order-bottom-add` handler, for the
     * no-JS submit that appends a row as part of a save. Null when nothing was chosen or the value
     * is not one this vocabulary has.
     *
     * The vocabulary is the select's own: `shipping:Label`, `tax:Label`,
     * `shipping-method:Label|Amount`, and the three `empty-*` free-text kinds. It is read here,
     * beside normalize(), so the browser's reading and the server's cannot drift.
     *
     * The `empty-*` kinds are the one place this differs from the JS control, and they have to be:
     * JS puts an empty label input on the page for the admin to type into before anything is saved,
     * whereas this row IS the save, and normalize() drops a row with no label and no amount as one
     * the admin never filled in. So it starts under the type's plain default label — which the row's
     * own label input, on the page that comes straight back, exists to replace.
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
        $rest = $colon === false ? '' : substr($choice, $colon + 1);

        // Same three-way split app.js makes: "empty-shipping" is still a shipping row, it just
        // carries no fixed label.
        $type = match (true) {
            in_array($kind, [FeeLine::TYPE_SHIPPING, 'shipping-method', 'empty-shipping'], true) => FeeLine::TYPE_SHIPPING,
            $kind === 'empty-fee' => FeeLine::TYPE_FEE,
            in_array($kind, [self::TYPE_TAX, 'empty-tax'], true) => self::TYPE_TAX,
            default => null,
        };
        if ($type === null) {
            return null;
        }

        $label = $rest;
        $amount = 0.0;
        if ($kind === 'shipping-method') {
            // "shipping-method:Label|Amount": the amount was computed server-side when the option
            // was rendered and is an ordinary editable figure from here on — and a named method's
            // is recomputed at save anyway, by recalculateShippingCharge().
            $pipe = strrpos($rest, '|');
            $method = $pipe === false ? $rest : substr($rest, 0, $pipe);
            $amount = $pipe === false ? 0.0 : (float) substr($rest, $pipe + 1);
            $label = sprintf('Shipping (%s)', trim($method));
        }

        $label = trim($label);

        return [
            'label' => $label !== '' ? $label : self::defaultLabel($type),
            'amount' => max(0.0, $amount),
            'type' => $type,
            'slug' => '',
        ];
    }

    /**
     * Whether this row is "the" document's shipping rather than a manual extra stacked beside it —
     * the same split the forms mark with data-charge-kind="named".
     *
     * Only one can exist at a time, which is why app.js's Add Line removes the previous one before
     * inserting a new one. The no-JS submit has to do the same, or a second press would leave the
     * order charged for shipping twice.
     *
     * @param array<string, mixed> $row
     */
    public static function isNamedShippingRow(array $row): bool
    {
        if (($row['type'] ?? '') !== FeeLine::TYPE_SHIPPING) {
            return false;
        }

        $label = trim((string) ($row['label'] ?? ''));

        return $label === 'Custom Shipping' || preg_match('/^Shipping \(.+\)$/', $label) === 1;
    }

    /**
     * The admin-facing reason this post's charge rows cannot become lines, or null.
     *
     * Both save paths ask before they touch the document. A fee calculator flushes partway through
     * a save (FeeRepository::ensureBySlug() persists and flushes), so a row that cannot become a
     * line has to be refused at the door rather than discovered halfway through — by which point
     * some of the document is already written.
     *
     * Ported to a symfony/validator constraint for #317, following ValidRedirect's #222/#305
     * pattern: ValidChargeRowsValidator runs the same normalize()+assertValid() pair this method
     * used to run by hand, so a future fix to either rule is inherited by OrderController and
     * EstimateController without either changing anything.
     */
    public static function errorFor(mixed $chargesRaw): ?string
    {
        $violations = Validation::createValidator()->validate($chargesRaw, new ValidChargeRows());

        return count($violations) > 0 ? (string) $violations[0]->getMessage() : null;
    }

    /**
     * The one rule Fee::assertValid() enforces that a line built outside a Fee never runs: an
     * after-tax line is added to the grand total once tax is already settled, so taxing it would
     * tax money the breakdown above it never saw.
     *
     * @param list<ChargeRow> $charges
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(array $charges): void
    {
        foreach (self::feeRows($charges) as $row) {
            if (self::placement($row) === Fee::PLACEMENT_AFTER_TAX && self::taxClass($row) !== 'E') {
                throw new \InvalidArgumentException(
                    'After Tax fees cannot be taxable — set the fee row\'s tax class to Exempt first.',
                );
            }
        }
    }

    /**
     * @param list<ChargeRow> $charges
     * @return list<ChargeRow>
     */
    public static function shippingRows(array $charges): array
    {
        return array_values(array_filter(
            $charges,
            static fn (array $row): bool => ($row['type'] ?? '') === FeeLine::TYPE_SHIPPING,
        ));
    }

    /**
     * @param list<ChargeRow> $charges
     * @return list<ChargeRow>
     */
    public static function feeRows(array $charges): array
    {
        return array_values(array_filter(
            $charges,
            static fn (array $row): bool => ($row['type'] ?? '') === FeeLine::TYPE_FEE,
        ));
    }

    /**
     * The shipping rows as document lines.
     *
     * Every row takes the same tax class — the highest across the document's goods — because that is
     * exactly what the retired shipping scalar was taxed at, and shipping mixed goods is charged at
     * the highest class among them. It is resolved at save from the document rather than frozen into
     * the row when the admin adds it, so adding a taxable product afterwards still lifts the class
     * the way it always did.
     *
     * Placement is main_line for the same reason a shipping charge has always sat inside "subtotal
     * excluding taxes": it is charged before tax and is itself taxable. That also means the
     * after_tax_line/Exempt rule cannot be reached from here.
     *
     * A fee row is the opposite case and is why toFeeLines() is a separate method rather than a
     * flag on this one: it has no document-wide answer to inherit, so it states both itself.
     *
     * @param list<ChargeRow> $charges
     * @return FeeLine[]
     */
    public static function toShippingLines(array $charges, string $taxClass): array
    {
        $lines = [];
        foreach (self::shippingRows($charges) as $row) {
            $label = (string) ($row['label'] ?? '');
            $slug = self::sluggify((string) ($row['slug'] ?? '')) ?: self::sluggify($label);
            $lines[] = new FeeLine(
                null,
                $slug !== '' ? $slug : FeeLine::TYPE_SHIPPING,
                $label,
                $taxClass,
                (float) ($row['amount'] ?? 0),
                'main_line',
                FeeLine::TYPE_SHIPPING,
                FeeLine::SOURCE_MANUAL,
            );
        }

        return $lines;
    }

    /**
     * The fee rows as document lines: the one-off charge that is neither tax nor shipping and that
     * nobody wants a reusable Fee definition for.
     *
     * They carry no feeId because there is no definition to point at, and SOURCE_MANUAL because an
     * admin decided the amount rather than a calculator. From the snapshot on they are fee lines
     * like any other — taxed by their own tax class, summed into the same fee total, rendered on
     * the detail and invoice pages by the loops that already read every row.
     *
     * The slug is whatever the admin typed, else the label sluggified, because reporting wants a key
     * that survives a relabel. Two rows are free to end up with the same slug: nothing keys on
     * uniqueness, and two rows labelled "Adjustment" are two rows.
     *
     * @param list<ChargeRow> $charges
     * @return FeeLine[]
     */
    public static function toFeeLines(array $charges): array
    {
        // Asked again here and not only at the door: this is the last point before a bad row would
        // reach the snapshot, and a save path that forgot errorFor() should fail loudly rather than
        // freeze a line the Fee rules forbid.
        self::assertValid($charges);

        $lines = [];
        foreach (self::feeRows($charges) as $row) {
            $label = (string) ($row['label'] ?? '');
            $slug = self::sluggify((string) ($row['slug'] ?? '')) ?: self::sluggify($label);
            $lines[] = new FeeLine(
                null,
                $slug !== '' ? $slug : FeeLine::TYPE_FEE,
                $label,
                self::taxClass($row),
                (float) ($row['amount'] ?? 0),
                self::placement($row),
                FeeLine::TYPE_FEE,
                FeeLine::SOURCE_MANUAL,
            );
        }

        return $lines;
    }

    /**
     * Read-path counterpart of toShippingLines(): the admin forms edit shipping as charge rows, and
     * a save rebuilds the document from whatever the form posts back, so a saved document's shipping
     * rows have to come back as rows or the first re-save would silently drop them.
     *
     * @param FeeLine[] $lines
     * @return list<ChargeRow>
     */
    public static function fromShippingLines(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if ($line->type !== FeeLine::TYPE_SHIPPING) {
                continue;
            }

            $rows[] = ['label' => $line->label, 'amount' => $line->amount, 'type' => FeeLine::TYPE_SHIPPING, 'slug' => $line->slug];
        }

        return $rows;
    }

    /**
     * Read-path counterpart of toFeeLines(), and the whole reason a manual fee line survives.
     *
     * A save rebuilds fee_lines from the calculators, which have never heard of this row — so
     * unless the form is handed it back and posts it again, the very next save wipes it. Only the
     * manual rows come back: a calculated line is rebuilt from its own definition and is not the
     * admin's to edit here.
     *
     * @param FeeLine[] $lines
     * @return list<ChargeRow>
     */
    public static function fromFeeLines(array $lines): array
    {
        $rows = [];
        foreach (self::manualFeeLines($lines) as $line) {
            $rows[] = [
                'label' => $line->label,
                'amount' => $line->amount,
                'type' => FeeLine::TYPE_FEE,
                'slug' => $line->slug,
                'taxClass' => $line->taxClass,
                'placement' => $line->placement,
            ];
        }

        return $rows;
    }

    /**
     * The admin-typed fee lines among a document's frozen rows.
     *
     * @param FeeLine[] $lines
     * @return FeeLine[]
     */
    public static function manualFeeLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (FeeLine $line): bool => $line->type === FeeLine::TYPE_FEE
                && $line->source === FeeLine::SOURCE_MANUAL,
        ));
    }

    /** @param list<ChargeRow> $charges */
    public static function deriveShippingMethod(array $charges): ?string
    {
        foreach (self::shippingRows($charges) as $charge) {
            $label = trim((string) ($charge['label'] ?? ''));
            // The charge row displays "Shipping (Method Name)"; store the bare method name so
            // $shippingMethod stays clean for use elsewhere.
            if ($label !== '' && preg_match('/^Shipping \((.+)\)$/', $label, $m)) {
                return $m[1];
            }

            return $label !== '' ? $label : null;
        }

        return null;
    }

    private static function defaultLabel(string $type): string
    {
        return match ($type) {
            FeeLine::TYPE_SHIPPING => 'Shipping',
            FeeLine::TYPE_FEE => 'Fee',
            default => 'Tax',
        };
    }

    private static function unknownTypeMessage(string $label, string $type): string
    {
        $row = $label !== '' ? sprintf('Charge row "%s"', $label) : 'A charge row';

        return $type === ''
            ? sprintf('%s has no line type. Expected shipping, fee or tax.', $row)
            : sprintf('%s has an unknown line type "%s". Expected shipping, fee or tax.', $row, $type);
    }

    /**
     * The document's own rule for reading a tax code, so a row states its class the same way a
     * product line does. Exempt is what a blank one means, which is the only default that cannot
     * overcharge: a row whose tax class never arrived is not silently taxed.
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
        $known = [Fee::PLACEMENT_MAIN_LINE, Fee::PLACEMENT_BEFORE_TAX_LINE, Fee::PLACEMENT_AFTER_TAX];

        return in_array($placement, $known, true) ? $placement : Fee::PLACEMENT_MAIN_LINE;
    }

    private static function sluggify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }
}
