<?php

declare(strict_types=1);

namespace App\Contract\Hook;

interface InjectionPointProviderInterface
{
    public function getPoint(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /** @param array<string, mixed> $context */
    public function render(array $context): string;
}
