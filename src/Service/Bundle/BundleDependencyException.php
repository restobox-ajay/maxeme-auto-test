<?php

declare(strict_types=1);

namespace App\Service\Bundle;

/**
 * Thrown by {@see \App\Repository\BundleStatusRepository::activate()} when a bundle's direct
 * requirement is not Active. Refuses the whole activation — it never auto-activates the
 * requirement (#788).
 */
final class BundleDependencyException extends \RuntimeException
{
    /**
     * @param list<string> $missingSources the required sources that are not Active, by source
     */
    public function __construct(
        public readonly string $source,
        public readonly array $missingSources,
    ) {
        parent::__construct(sprintf(
            '"%s" needs %s Active first.',
            $source,
            implode(', ', $missingSources),
        ));
    }
}
