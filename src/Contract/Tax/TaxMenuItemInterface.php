<?php

declare(strict_types=1);

namespace App\Contract\Tax;

interface TaxMenuItemInterface
{
    public function getLabel(): string;

    public function getRoute(): string;

    public function getSource(): string;
}
