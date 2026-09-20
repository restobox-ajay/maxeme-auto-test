<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

use WarehouseOpsBundle\Entity\PickList;

/**
 * A compiled round and the lines that did not make it onto it (#552).
 *
 * The second half is the point. A picker handed nine of the ten lines they expected has to be told
 * which one is missing and why — "on simple inventory", "already on an open list", "fulfilled from
 * the other warehouse" are all answerable and all actionable, and a compile that silently returned
 * nine would send someone to find out the hard way.
 */
final class CompiledPickList
{
    /** @param list<string> $skipped */
    public function __construct(
        public readonly PickList $list,
        public readonly array $skipped,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->list->getTasks()->isEmpty();
    }
}
