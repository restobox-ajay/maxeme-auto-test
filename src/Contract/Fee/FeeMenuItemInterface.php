<?php

declare(strict_types=1);

namespace App\Contract\Fee;

interface FeeMenuItemInterface
{
    public function getLabel(): string;

    public function getRoute(): string;
}
