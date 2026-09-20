<?php

declare(strict_types=1);

namespace App\Service\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The bundle dependency graph, built once from every registered {@see BundleDescriptorInterface}
 * that also implements {@see RequiresBundlesInterface} (#788).
 *
 * Built lazily from the same `app.bundle_descriptor` tag {@see \App\Controller\Admin\BundleManagementController}
 * already iterates — there is no second source of truth to keep in sync.
 */
final class BundleDependencyGraph
{
    /** @var array<string, list<string>>|null source => direct requirements, once built */
    private ?array $requires = null;

    /** @var array<string, list<string>>|null source => direct dependents, once built */
    private ?array $dependents = null;

    /** @param iterable<BundleDescriptorInterface> $descriptors */
    public function __construct(
        #[AutowireIterator('app.bundle_descriptor')]
        private readonly iterable $descriptors,
    ) {
    }

    /**
     * @return list<string> the sources $source directly declares as required, in declaration order
     */
    public function directRequirements(string $source): array
    {
        $this->build();

        return $this->requires[$source] ?? [];
    }

    /**
     * Every source that requires $source, directly or through another source — i.e. everything
     * that stops being safely runnable the moment $source goes Inactive. Used by
     * {@see \App\Repository\BundleStatusRepository::deactivate()} to cascade.
     *
     * @return list<string> deduplicated, in breadth-first discovery order
     */
    public function transitiveDependents(string $source): array
    {
        $this->build();

        $seen = [];
        $queue = $this->dependents[$source] ?? [];

        while ($queue !== []) {
            $next = array_shift($queue);

            if (isset($seen[$next])) {
                continue;
            }

            $seen[$next] = true;

            foreach ($this->dependents[$next] ?? [] as $grandDependent) {
                if (!isset($seen[$grandDependent])) {
                    $queue[] = $grandDependent;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Builds {@see self::$requires} / {@see self::$dependents} from the descriptors and checks the
     * whole graph for a cycle — once, memoized. A cycle fails loudly, here and in
     * {@see \App\Tests\Architecture\BundleDependencyGraphTest}, rather than silently producing an
     * empty or truncated dependents list.
     */
    private function build(): void
    {
        if ($this->requires !== null) {
            return;
        }

        $requires = [];
        $dependents = [];

        foreach ($this->descriptors as $descriptor) {
            $source = $descriptor->getSource();

            if (!$descriptor instanceof RequiresBundlesInterface) {
                continue;
            }

            $required = $descriptor->getRequiredBundles();
            $requires[$source] = $required;

            foreach ($required as $requiredSource) {
                $dependents[$requiredSource][] = $source;
            }
        }

        $this->requires = $requires;
        $this->dependents = $dependents;

        foreach (array_keys($requires) as $source) {
            $this->assertNoCycleFrom($source);
        }
    }

    /** @param list<string> $path the sources visited on the current walk, in order */
    private function assertNoCycleFrom(string $source, array $path = []): void
    {
        if (in_array($source, $path, true)) {
            throw new BundleDependencyCycleException([...$path, $source]);
        }

        foreach ($this->requires[$source] ?? [] as $required) {
            $this->assertNoCycleFrom($required, [...$path, $source]);
        }
    }
}
