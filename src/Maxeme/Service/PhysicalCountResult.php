<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Entity\Part;

/** What a physical count found (PartService::recordCount()). */
final class PhysicalCountResult
{
    /** Parts given a count. */
    public int $counted = 0;

    /** Units found that the system did not have. */
    public int $surplus = 0;

    /** Units the system had that were not found. */
    public int $shortfall = 0;

    /** @var list<Part> parts whose stock changed */
    public array $changed = [];

    public function summary(): string
    {
        if ($this->changed === []) {
            return sprintf('%d part(s) counted and everything agreed. Nothing was changed.', $this->counted);
        }

        return sprintf(
            '%d part(s) counted: %d changed, %d unit(s) found that the system did not have, %d unit(s) missing. Each change is in the part\'s stock history as "Physical count".',
            $this->counted,
            count($this->changed),
            $this->surplus,
            $this->shortfall,
        );
    }
}
