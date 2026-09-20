<?php

declare(strict_types=1);

namespace App\Contract\Fee;

/**
 * The one encoder and decoder for the fee_lines JSON snapshot.
 *
 * Six save sites wrote the same array_map by hand and two readers wrote their own decoder, which was
 * survivable while only a calculator ever produced a line and only a template ever read one back.
 * Shipping rows end that: the document itself has to read its own rows to answer what it charges for
 * shipping, so the shape needs one owner rather than eight copies that are free to drift.
 */
final class FeeLineSnapshot
{
    /**
     * Null for an empty list rather than "[]", so "no rows" is one state in the column.
     *
     * @param FeeLine[] $lines
     */
    public static function encode(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        return json_encode(
            array_map(
                static function (FeeLine $l): array {
                    $row = [
                        'slug' => $l->slug,
                        'label' => $l->label,
                        'taxClass' => $l->taxClass,
                        'amount' => $l->amount,
                        'placement' => $l->placement,
                        'type' => $l->type,
                        'source' => $l->source,
                    ];

                    // Written only when it says something (#539 stage 5). Absent means one whole
                    // charge, which is what decode() reads it as and what every row written before
                    // charges were quantified meant — so a document that bills no fraction of
                    // anything still encodes byte-for-byte the snapshot it always did, and a save
                    // that touches nothing rewrites nothing.
                    if ($l->quantity !== 1.0) {
                        $row['quantity'] = $l->quantity;
                    }

                    return $row;
                },
                $lines,
            ),
            JSON_UNESCAPED_UNICODE,
        ) ?: null;
    }

    /**
     * feeId is deliberately not round-tripped: it was never written to the snapshot, and a frozen row
     * is a record of what was charged rather than a pointer at the definition that produced it.
     *
     * quantity defaults to 1 when absent, which is both what every row frozen before charges were
     * quantified meant and what encode() deliberately leaves out — see FeeLine::$quantity.
     *
     * @return FeeLine[]
     */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $lines = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = new FeeLine(
                null,
                (string) ($row['slug'] ?? ''),
                (string) ($row['label'] ?? ''),
                (string) ($row['taxClass'] ?? 'E'),
                (float) ($row['amount'] ?? 0),
                (string) ($row['placement'] ?? 'main_line'),
                // Rows frozen before these fields existed read back as a generic calculator-produced
                // fee, which is what every one of them was.
                (string) ($row['type'] ?? FeeLine::TYPE_FEE),
                (string) ($row['source'] ?? FeeLine::SOURCE_AUTO_CALC),
                // A row frozen before charges were quantified is the whole charge, which is
                // quantity 1. That default is the entire migration this widening needs: nothing has
                // to be rewritten for an existing snapshot to keep meaning what it meant.
                (float) ($row['quantity'] ?? 1),
            );
        }

        return $lines;
    }
}
