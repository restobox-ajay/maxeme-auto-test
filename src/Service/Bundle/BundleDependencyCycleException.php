<?php

declare(strict_types=1);

namespace App\Service\Bundle;

/**
 * A bundle dependency cycle (A requires B requires A) — a configuration error in the descriptors
 * themselves, not a runtime refusal, so it is a LogicException and it fails loudly rather than
 * being absorbed anywhere (#788). {@see \App\Tests\Architecture\BundleDependencyGraphTest} builds
 * the real, installed graph and asserts this is never thrown for it.
 */
final class BundleDependencyCycleException extends \LogicException
{
    /**
     * @param list<string> $cycle the sources in the cycle, in the order the walk found them, with
     *     the first source repeated at the end to show the loop closing
     */
    public function __construct(public readonly array $cycle)
    {
        parent::__construct(sprintf(
            'Bundle dependency cycle: %s. A bundle cannot require, directly or transitively, a bundle that requires it.',
            implode(' -> ', $cycle),
        ));
    }
}
