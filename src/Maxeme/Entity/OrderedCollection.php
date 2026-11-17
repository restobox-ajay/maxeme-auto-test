<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use Doctrine\Common\Collections\Collection;

/**
 * Replaces an owned, ordered child collection (a repair order's services and fees, a service's
 * lines) with a new list: a child left out is removed (orphanRemoval deletes it), a kept child keeps
 * its id, and every child's position becomes its index in the list.
 */
final class OrderedCollection
{
    /**
     * @template T of object
     *
     * @param Collection<int, T>  $collection the owner's collection
     * @param list<T>             $items      the new contents, in order; each must have setPosition(int)
     * @param \Closure(T): bool   $belongs    whether an item belongs to this owner
     * @param string              $foreign    the message when one does not
     *
     * @throws \InvalidArgumentException for an item of another owner
     */
    public static function replace(Collection $collection, array $items, \Closure $belongs, string $foreign): void
    {
        foreach ($collection as $item) {
            if (!in_array($item, $items, true)) {
                $collection->removeElement($item);
            }
        }
        foreach ($items as $position => $item) {
            if (!$belongs($item)) {
                throw new \InvalidArgumentException($foreign);
            }
            $item->setPosition($position);
            if (!$collection->contains($item)) {
                $collection->add($item);
            }
        }
    }
}
