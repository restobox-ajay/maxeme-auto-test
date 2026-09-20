<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Label;

use BarcodeBundle\Symbology\Code128;

/**
 * One label: what to encode, and what to print above and below it in human-readable text (#552).
 *
 * ## Why the encoded value and the printed text are separate fields
 *
 * They are not the same thing and treating them as one is how a lot label becomes useless. A lot's
 * barcode encodes its row id — the only thing that identifies it, because batch codes are reused
 * across production runs with different expiry dates — while the text a person reads has to be the
 * code AND the expiry, or the label cannot be checked by eye against the box. Same for a product:
 * the barcode carries the SKU, the text carries the name nobody can scan.
 */
final class LabelSpec
{
    public const KIND_PRODUCT = 'product';
    public const KIND_BIN = 'bin';
    public const KIND_LOT = 'lot';
    public const KIND_SERIAL = 'serial';

    public function __construct(
        public readonly string $kind,
        public readonly string $encoded,
        public readonly string $title,
        public readonly string $subtitle = '',
    ) {
    }

    /** True when this label can be printed — an unencodable value is refused, never silently dropped. */
    public function isPrintable(): bool
    {
        return Code128::isEncodable($this->encoded);
    }
}
