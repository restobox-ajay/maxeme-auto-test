<?php

declare(strict_types=1);

namespace ProcurementBundle\Contract\Purchase;

/**
 * The one encoder and decoder for a purchase document's `charge_lines` column (#655, #658).
 *
 * The buy-side twin of `App\Contract\Fee\FeeLineSnapshot`, and it exists for the reason that one
 * does: the moment two places write the shape, the copies drift. A document has to read its own
 * charges back to answer what it is worth, and a save has to hand them to the form so that the next
 * save does not silently drop them.
 *
 * ## Why a JSON snapshot rather than a `vendor_bill_charge_line` table
 *
 * Because that is the sell side's shape, and the shape is what was asked to be mirrored. The sell
 * side used to have a charge-lines store and deliberately removed it — `App\Service\SalesDocumentChargeLines`
 * says so outright — and what it stores instead is two CLOB snapshots on the document itself,
 * `fee_lines` and `tax_lines`. A new table would re-introduce exactly what was thrown away, to buy
 * a cross-document queryability nothing asks for (that is `#599`, parked).
 *
 * ## Null, not "[]"
 *
 * "No charges" is one state in the column, not two.
 */
final class PurchaseChargeLineSnapshot
{
    /** @param PurchaseChargeLine[] $lines */
    public static function encode(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        return json_encode(
            array_map(
                static fn (PurchaseChargeLine $l): array => [
                    'slug' => $l->slug,
                    'label' => $l->label,
                    'taxClass' => $l->taxClass,
                    'amount' => $l->amount,
                    'placement' => $l->placement,
                    'type' => $l->type,
                    'source' => $l->source,
                ],
                array_values($lines),
            ),
            JSON_UNESCAPED_UNICODE,
        ) ?: null;
    }

    /**
     * Anything unreadable decodes to no charges rather than throwing: a document whose snapshot a
     * hand-edit corrupted must still open, and a charge that cannot be read is a charge that cannot
     * be printed either.
     *
     * @return PurchaseChargeLine[]
     */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $lines = [];
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $lines[] = new PurchaseChargeLine(
                (string) ($row['slug'] ?? ''),
                (string) ($row['label'] ?? ''),
                (string) ($row['taxClass'] ?? 'E'),
                (float) ($row['amount'] ?? 0),
                (string) ($row['placement'] ?? PurchaseChargeLine::PLACEMENT_MAIN_LINE),
                (string) ($row['type'] ?? PurchaseChargeLine::TYPE_FEE),
                (string) ($row['source'] ?? PurchaseChargeLine::SOURCE_AUTO_CALC),
            );
        }

        return $lines;
    }
}
