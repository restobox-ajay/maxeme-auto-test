<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Contract\Status\StatusVocabularyProviderInterface;
use App\Status\StatusVocab;
use App\Status\StatusVocabularyLoader;
use PHPUnit\Framework\TestCase;

/**
 * The loader itself: what it collects, and what it refuses to collect (handoff sections 3 and 4).
 *
 * Every case here carries something that must NOT change alongside the thing being proved — a
 * second vocabulary that still resolves after a collision is refused, a sibling status untouched by
 * a malformed one. A refusal that also quietly lost an unrelated vocabulary would pass a test that
 * only looked at the thing it refused.
 */
final class StatusVocabularyLoaderTest extends TestCase
{
    /** @param array<string, mixed> $vocabularies */
    private function provider(array $vocabularies): StatusVocabularyProviderInterface
    {
        return new class($vocabularies) implements StatusVocabularyProviderInterface {
            /** @param array<string, mixed> $vocabularies */
            public function __construct(private readonly array $vocabularies)
            {
            }

            public function statusVocabularies(): array
            {
                return $this->vocabularies;
            }
        };
    }

    /** @return array<string, mixed> */
    private function twoStatusVocabulary(string $a = 'Draft', string $b = 'Issued'): array
    {
        return [
            'statuses' => [$a => $a, $b => ['label' => $b, 'derived' => true]],
            'transitions' => [$a => [$b], $b => []],
        ];
    }

    public function testItCollectsVocabulariesFromEveryProvider(): void
    {
        $loader = new StatusVocabularyLoader([
            $this->provider(['alpha' => $this->twoStatusVocabulary()]),
            $this->provider(['beta' => $this->twoStatusVocabulary('Open', 'Closed')]),
        ]);

        self::assertSame(['alpha', 'beta'], array_keys($loader->getVocabularies()));
        self::assertSame(['Draft', 'Issued'], $loader->getVocabulary('alpha')->slugs());
        self::assertSame(['Open', 'Closed'], $loader->getVocabulary('beta')->slugs());
    }

    public function testItNamesBothProvidersWhenTwoClaimTheSameKey(): void
    {
        $first = $this->provider(['invoice' => $this->twoStatusVocabulary()]);
        $second = $this->provider(['invoice' => $this->twoStatusVocabulary('Open', 'Closed')]);

        $this->expectException(\LogicException::class);
        // Both providers and the key: the whole of what the person reading the trace needs. The
        // anonymous classes share a name prefix, so this asserts the key and the shape of the
        // sentence rather than two identical class names.
        $this->expectExceptionMessage('Two providers both declare the status vocabulary "invoice"');

        new StatusVocabularyLoader([$first, $second]);
    }

    /**
     * The collision must be fatal, not "the second one is dropped and everything else still works".
     * Last-one-wins is impossible to debug, which is why this differs from DocumentPrefixCatalogue.
     */
    public function testACollisionTakesTheWholeLoaderDownRatherThanDroppingOneVocabulary(): void
    {
        $built = null;

        try {
            $built = new StatusVocabularyLoader([
                $this->provider(['keeper' => $this->twoStatusVocabulary()]),
                $this->provider(['invoice' => $this->twoStatusVocabulary()]),
                $this->provider(['invoice' => $this->twoStatusVocabulary('Open', 'Closed')]),
            ]);
        } catch (\LogicException) {
            // expected
        }

        self::assertNull($built, 'A collision must refuse to build the loader at all.');
    }

    /** The positive control for the case above: the same three keys, no collision, all three resolve. */
    public function testThreeDistinctKeysAllResolve(): void
    {
        $loader = new StatusVocabularyLoader([
            $this->provider(['keeper' => $this->twoStatusVocabulary()]),
            $this->provider(['invoice' => $this->twoStatusVocabulary()]),
            $this->provider(['purchase_order' => $this->twoStatusVocabulary('Open', 'Closed')]),
        ]);

        self::assertSame(['keeper', 'invoice', 'purchase_order'], array_keys($loader->getVocabularies()));
    }

    public function testAnUnknownKeyThrowsAndListsWhatDoesExist(): void
    {
        $loader = new StatusVocabularyLoader([$this->provider(['invoice' => $this->twoStatusVocabulary()])]);

        self::assertTrue($loader->hasVocabulary('invoice'), 'positive control: the real key resolves');
        self::assertFalse($loader->hasVocabulary('invoise'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No status vocabulary is registered for "invoise". Registered: invoice.');

        $loader->getVocabulary('invoise');
    }

    public function testATransitionToAStatusThatDoesNotExistFailsAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the transition "Draft" -> "Pendign"');

        new StatusVocabularyLoader([
            $this->provider([
                'invoice' => [
                    'statuses' => ['Draft' => 'Draft', 'Pending' => 'Pending'],
                    'transitions' => ['Draft' => ['Pendign']],
                ],
            ]),
        ]);
    }

    public function testATransitionFromAStatusThatDoesNotExistFailsAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares transitions FROM "Pendign"');

        new StatusVocabularyLoader([
            $this->provider([
                'invoice' => [
                    'statuses' => ['Draft' => 'Draft', 'Pending' => 'Pending'],
                    'transitions' => ['Pendign' => ['Draft']],
                ],
            ]),
        ]);
    }

    public function testAVocabularyWithNoStatusesFailsAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Status vocabulary "invoice" declares no statuses.');

        new StatusVocabularyLoader([$this->provider(['invoice' => ['statuses' => []]])]);
    }

    /**
     * The config-backed loader refuses an edit rather than taking it and dropping it at the end of
     * the request. A saved change that silently evaporates is worse than a refusal naming the
     * implementation that will support it.
     */
    public function testSetVocabularyRefusesAndSaysWhichImplementationWouldStoreIt(): void
    {
        $loader = new StatusVocabularyLoader([$this->provider(['invoice' => $this->twoStatusVocabulary()])]);

        try {
            $loader->setVocabulary('invoice', $this->twoStatusVocabulary('Open', 'Closed'));
            self::fail('setVocabulary() must refuse in the config-backed implementation.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot be edited at runtime', $e->getMessage());
            self::assertStringContainsString('database-backed implementation', $e->getMessage());
        }

        // The row that must not change: the refusal left the vocabulary exactly as it was.
        self::assertSame(['Draft', 'Issued'], $loader->getVocabulary('invoice')->slugs());
    }

    public function testItIgnoresATaggedServiceThatIsNotAProvider(): void
    {
        $loader = new StatusVocabularyLoader([
            new \stdClass(),
            $this->provider(['invoice' => $this->twoStatusVocabulary()]),
        ]);

        self::assertSame(['invoice'], array_keys($loader->getVocabularies()));
    }

    public function testItReportsWhichProviderDeclaredAKey(): void
    {
        $provider = $this->provider(['invoice' => $this->twoStatusVocabulary()]);
        $loader = new StatusVocabularyLoader([$provider]);

        self::assertSame($provider::class, $loader->declaredBy('invoice'));
        self::assertNull($loader->declaredBy('purchase_order'));
    }

    public function testVocabulariesComeBackAsStatusVocabObjects(): void
    {
        $loader = new StatusVocabularyLoader([$this->provider(['invoice' => $this->twoStatusVocabulary()])]);

        self::assertInstanceOf(StatusVocab::class, $loader->getVocabulary('invoice'));
        self::assertSame('invoice', $loader->getVocabulary('invoice')->key);
    }
}
