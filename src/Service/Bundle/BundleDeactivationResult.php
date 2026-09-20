<?php

declare(strict_types=1);

namespace App\Service\Bundle;

use App\Entity\BundleStatus;

/**
 * What {@see \App\Repository\BundleStatusRepository::deactivate()} actually did: the bundle asked
 * for, plus every Active transitive dependent it cascaded off along with it (#788).
 */
final class BundleDeactivationResult
{
    /** @param list<string> $alsoDeactivated sources, not including $target's own source */
    public function __construct(
        public readonly BundleStatus $target,
        public readonly array $alsoDeactivated,
    ) {
    }
}
