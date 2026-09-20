<?php

declare(strict_types=1);

namespace App\Contract\Menu;

interface FrontendMenuItemInterface
{
    /** Unique, stable-across-deploys key identifying this item for the admin on/off toggle. */
    public function getKey(): string;

    public function getLabel(): string;

    public function getRoute(): string;

    /**
     * Extra path() parameters for getRoute() — e.g. a category filter for a shared catalog
     * route. Empty for routes that take none.
     *
     * @return array<string, mixed>
     */
    public function getRouteParams(): array;

    public function getSource(): string;

    /**
     * Runtime visibility decided by the bundle itself (e.g. company/user/date-based logic) —
     * checked in addition to, and independently of, the admin per-item on/off toggle and the
     * bundle-level Active/Inactive kill-switch (both already checked before this is called).
     */
    public function isVisible(): bool;
}
