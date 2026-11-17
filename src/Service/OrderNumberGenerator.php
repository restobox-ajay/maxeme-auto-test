<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Single source of truth for the next "<prefix><n>" order number, shared by
 * the admin and customer order-creation flows. Was previously duplicated
 * privately in both controllers.
 *
 * The prefix is admin-configurable (Settings > Document Prefixes) and not
 * retroactive: changing it starts a fresh numbering sequence under the new
 * prefix rather than renumbering existing orders — DocumentNumberAllocator
 * seeds a counter row per prefix from the current MAX(...) the first time
 * that prefix is used.
 *
 * Allocation itself is atomic (see DocumentNumberAllocator and
 * migrations/Version20260802110000.php): two concurrent checkouts can no
 * longer read the same next value, which used to surface as a
 * UniqueConstraintViolationException on flush and push a needless retry
 * onto the customer (#304). The unique DB constraint on order_number stays
 * as a backstop, but allocation should never trigger it now.
 *
 * DEFAULT_PREFIX was 'HD' until #436 — a leftover from the very first,
 * pre-configurable `sprintf('HD%d', ...)` implementation that never got
 * revisited to a Sales-Order-shaped default on a fresh instance.
 */
final class OrderNumberGenerator extends AbstractDocumentNumberGenerator
{
    public const DEFAULT_PREFIX = 'SO-';

    protected const PREFIX_SETTING = 'order_number_prefix';
    protected const KIND = 'order';
    protected const TABLE = 'sales_order';
    protected const COLUMN = 'order_number';
}
