<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Enforces that the bundles-off proof (#562) is actually wired up.
 *
 * The environment and the CI gate can both silently not-run: an environment nobody invokes is inert,
 * and a group that quietly empties out passes instantly while proving nothing. Neither failure is
 * visible in a green suite, which is exactly the class of thing an architecture test exists for —
 * the same reasoning behind WarehouseOpsBundle's NoSecondWritePathTest, which greps the source
 * because a behavioural test can only prove today's paths are clean.
 *
 * This asserts the structure, not the behaviour. bin/ci-bundles-off asserts the behaviour.
 */
final class BundlesOffPairingTest extends TestCase
{
    private const GROUP = 'bundle-agnostic';

    /**
     * The core paths that must hold with the optional bundles off.
     *
     * A literal list, deliberately. The point of this test is to fail when someone removes a tag,
     * and a list derived from whatever happens to carry the tag today would agree with any answer
     * — including an empty one.
     */
    private const REQUIRED = [
        'CustomerCartCest',
        'CustomerCheckoutOrderFirstCest',
        'AdminOrderStockCheckCest',
        'AdminConvertOrderToInvoiceCest',
        'EveryOrderPathRaisesAnInvoiceCest',
        'AdminInventoryCest',
    ];

    public function testEveryCorePathCestIsTaggedForTheBundlesOffRun(): void
    {
        foreach (self::REQUIRED as $cest) {
            $path = $this->root() . '/tests/Functional/' . $cest . '.php';

            self::assertFileExists($path, sprintf(
                '%s is required to run with the optional bundles off. If it was renamed, update this'
                . ' list; if it was deleted, say why the path it covered no longer needs proving.',
                $cest,
            ));

            self::assertStringContainsString(
                '@group ' . self::GROUP,
                (string) file_get_contents($path),
                sprintf(
                    '%s lost its @group %s tag, so it no longer runs with the optional inventory'
                    . ' bundles off — and nothing else would have told you.',
                    $cest,
                    self::GROUP,
                ),
            );
        }
    }

    /** The environment the gate depends on has to exist, and has to enable the helper that does the work. */
    public function testTheBundlesOffEnvironmentIsDefined(): void
    {
        $suite = (string) file_get_contents($this->root() . '/tests/Functional.suite.yml');

        self::assertStringContainsString('bundles-off:', $suite, 'the bundles-off environment is gone from the suite config');
        self::assertStringContainsString('BundlesOff', $suite, 'the bundles-off environment no longer enables the helper that deactivates the bundles');
    }

    /**
     * The gate must invoke the environment AND the group. Dropping either turns it into a second
     * ordinary run that passes for the wrong reason.
     */
    public function testTheCiGateInvokesTheEnvironmentAndTheGroup(): void
    {
        $gate = $this->root() . '/bin/ci-bundles-off';

        self::assertFileExists($gate, 'bin/ci-bundles-off is the only thing that runs the bundles-off proof');
        self::assertFileIsReadable($gate);

        $source = (string) file_get_contents($gate);
        self::assertStringContainsString('--env bundles-off', $source, 'the gate no longer selects the bundles-off environment');
        self::assertStringContainsString('-g ' . self::GROUP, $source, 'the gate no longer restricts to the bundle-agnostic group');
    }

    /**
     * And it must ALSO run the harness proof, outside that group filter.
     *
     * BundlesOffEnvironmentCest is what rules out the gate passing against an application whose
     * bundles are still on. It ran in neither gate for its whole life: it is `@env bundles-off`, so
     * the ordinary suite reports "No tests executed!" for it, and its own docblock explains why it
     * cannot carry the `bundle-agnostic` tag — so `-g bundle-agnostic` filtered it out of the only
     * run that selects its environment. Two filters, each individually reasonable, and between
     * them the test that exists to catch a false green had never executed.
     *
     * Hence a separate invocation, and hence this: re-adding `-g` to it, or dropping it back to
     * one command, would restore exactly that blind spot and nothing else would say so.
     */
    public function testTheCiGateAlsoRunsTheHarnessProofWithNoGroupFilter(): void
    {
        $source = (string) file_get_contents($this->root() . '/bin/ci-bundles-off');

        self::assertMatchesRegularExpression(
            '/BundlesOffEnvironmentCest --env bundles-off(?! *-g)/',
            $source,
            'bin/ci-bundles-off no longer runs BundlesOffEnvironmentCest in the bundles-off environment'
            . ' WITHOUT a group filter — which is the only way that Cest ever executes, because the'
            . ' ordinary suite skips it on @env and -g ' . self::GROUP . ' excludes it.',
        );
    }

    /** Every bundle the helper switches off must be one that actually exists. */
    public function testTheHelperNamesRealBundles(): void
    {
        $helper = $this->root() . '/tests/Support/Helper/BundlesOff.php';
        self::assertFileExists($helper);

        preg_match_all("/'([A-Za-z0-9]+Bundle)'/", (string) file_get_contents($helper), $matches);
        self::assertNotEmpty($matches[1], 'the helper switches off no bundles at all');

        foreach ($matches[1] as $bundle) {
            self::assertDirectoryExists(
                $this->root() . '/modules/' . $bundle,
                sprintf('BundlesOff names %s, which is not a bundle in modules/', $bundle),
            );
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
