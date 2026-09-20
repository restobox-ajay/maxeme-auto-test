<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

/**
 * "Someone got there first."
 *
 * Thrown when the conditional decrement matched no row — either the source never held that much, or
 * a concurrent operation took it between reading and writing. Deliberately a distinct exception
 * rather than a constraint violation, because the two are the same event seen from different
 * layers and only this one can be shown to a person as a sentence.
 */
final class InsufficientStockException extends \RuntimeException
{
    public static function forKey(DetailKey $key, int $wanted): self
    {
        return new self(sprintf(
            'Only part of the requested %d unit(s) is still in %s — it moved while this was being submitted. Re-check the stock and try again.',
            $wanted,
            $key->describe(),
        ));
    }
}
