<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Bundle\InstalledBundleDirectory;
use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;
use App\Service\Bundle\BundleDependencyCycleException;
use App\Service\Bundle\BundleDependencyGraph;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #788's design says a dependency cycle "fails loudly (container build or test), never silently".
 * There is no compiler pass here — the cheaper, equally loud alternative this repo already uses
 * elsewhere (BundlesOffPairingTest, EveryDocumentDeclaresItsContractTest) is an architecture test
 * that boots the real kernel and proves it. Two things, not one: that the REAL, installed
 * descriptor graph has no cycle today, and that the cycle CHECK ITSELF actually fires when one
 * exists — a detector nothing ever exercises is exactly the kind of thing that silently rots.
 */
final class BundleDependencyGraphTest extends KernelTestCase
{
    public function testTheRealInstalledGraphHasNoCycle(): void
    {
        self::bootKernel();

        $graph = self::getContainer()->get(BundleDependencyGraph::class);
        $installed = self::getContainer()->get(InstalledBundleDirectory::class)->sources();

        self::assertNotEmpty($installed, 'No bundles discovered at all — the graph would trivially have no cycle, proving nothing.');

        // build() (private, called by both of these) validates the WHOLE graph before answering
        // anything, not just the source asked about — so calling it once is enough to walk every
        // descriptor's requirements. Called once per installed source anyway, cheaply, so this
        // fails on the real source if BundleDependencyGraph's own "check the whole thing up front"
        // behavior is ever narrowed to something lazier that only checks part of the graph.
        foreach ($installed as $source) {
            $graph->transitiveDependents($source);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The detector itself, proven against two synthetic descriptors that require each other —
     * no kernel needed, since {@see BundleDependencyGraph} takes its descriptors as a plain
     * iterable and RequiresBundlesInterface needs nothing but a source and a requirement list.
     */
    public function testACycleIsDetectedAndFailsLoudly(): void
    {
        $a = $this->fakeDescriptor('CycleTestABundle', ['CycleTestBBundle']);
        $b = $this->fakeDescriptor('CycleTestBBundle', ['CycleTestABundle']);

        $graph = new BundleDependencyGraph([$a, $b]);

        $this->expectException(BundleDependencyCycleException::class);
        $graph->directRequirements('CycleTestABundle');
    }

    /**
     * A ← B ← C (C requires B, B requires A): deactivating A must cascade to both B and C, not
     * just the bundle that names A directly. No real bundle is three deep yet, hence synthetic
     * descriptors — the same reason the cycle test above needs them.
     */
    public function testTransitiveDependentsWalksTheWholeChain(): void
    {
        $a = $this->fakeDescriptor('ChainABundle', []);
        $b = $this->fakeDescriptor('ChainBBundle', ['ChainABundle']);
        $c = $this->fakeDescriptor('ChainCBundle', ['ChainBBundle']);

        $graph = new BundleDependencyGraph([$a, $b, $c]);

        self::assertSame(['ChainBBundle', 'ChainCBundle'], $graph->transitiveDependents('ChainABundle'));
        self::assertSame(['ChainCBundle'], $graph->transitiveDependents('ChainBBundle'));
        self::assertSame([], $graph->transitiveDependents('ChainCBundle'), 'nothing requires the bundle at the end of the chain');
    }

    private function fakeDescriptor(string $source, array $requires): BundleDescriptorInterface&RequiresBundlesInterface
    {
        return new class($source, $requires) implements BundleDescriptorInterface, RequiresBundlesInterface {
            /** @param list<string> $requires */
            public function __construct(private readonly string $source, private readonly array $requires) {}
            public function getName(): string { return $this->source; }
            public function getType(): string { return 'Test'; }
            public function getSource(): string { return $this->source; }
            public function getEditRoute(): ?string { return null; }
            public function getDocsUrl(): ?string { return null; }
            public function getRequiredBundles(): array { return $this->requires; }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
