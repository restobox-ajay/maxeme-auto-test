<?php

declare(strict_types=1);

namespace App\Contract\Hook;

interface InjectionPointMenuItemInterface
{
    public function getLabel(): string;

    public function getRoute(): string;

    /**
     * Admin nav sub-heading this item renders under, e.g. "Custom Page" or
     * "Number1 Integration" — items sharing the same section group together, in the
     * order sections first appear. Lets each bundle group itself sensibly instead of
     * every injection-point bundle sharing one fixed "Custom Page" bucket.
     */
    public function getSection(): string;
}
