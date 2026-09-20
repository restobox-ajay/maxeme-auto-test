<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

/**
 * A pack conversion that the declaration itself will not allow (#22).
 *
 * Distinct from {@see InsufficientStockException}, which is about the shelf. This one is about the
 * RULE: the two products are the same product, the pack holds one, the pack was never marked
 * rebuildable, a side is lot-tracked, the chain would close a loop. Every message it carries is a
 * sentence an operator can act on, because the screen prints it verbatim as a flash.
 *
 * Both are raised BEFORE `StockMovementService::apply()` opens its transaction, which is what makes
 * "refused" mean neither balance moved rather than "rolled back" — a `wrapInTransaction()` failure
 * closes the EntityManager, and closing it to say "that pack is not rebuildable" would be an
 * expensive way to decline.
 */
final class PackConversionRefusal extends \RuntimeException
{
}
