<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Scan;

/**
 * What one scanned barcode turned out to be (#552).
 *
 * `kind` is one of the four label targets; `entity` is the row it resolved to. An unresolved scan is
 * not represented here at all — ScanResolver returns null for that, and the caller has to say so out
 * loud, because a silently ignored scan is how stock goes missing.
 */
final class ScanMatch
{
    public const KIND_BIN = 'bin';
    public const KIND_PRODUCT = 'product';
    public const KIND_LOT = 'lot';
    public const KIND_SERIAL = 'serial';

    public function __construct(
        public readonly string $kind,
        public readonly object $entity,
        public readonly string $label,
    ) {
    }
}
