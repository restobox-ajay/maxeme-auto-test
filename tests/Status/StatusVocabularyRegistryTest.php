<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Contract\Status\StatusVocabularyProviderInterface;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The one static call an entity makes to reach the loader.
 *
 * Static state outlives a test, so every case here resets it on the way in AND on the way out. The
 * house rule about Codeception reusing one Cest instance is the same hazard in a different shape.
 */
final class StatusVocabularyRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::reset();
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private function loader(): StatusVocabularyLoader
    {
        $provider = new class implements StatusVocabularyProviderInterface {
            public function statusVocabularies(): array
            {
                return ['invoice' => ['statuses' => ['Draft' => 'Draft'], 'transitions' => ['Draft' => []]]];
            }
        };

        return new StatusVocabularyLoader([$provider]);
    }

    /**
     * The failure mode this guards is a status seam that quietly works with no providers. It must
     * say which class primes it, because "unprimed" tells whoever hit it nothing about what to do.
     */
    public function testAnUnprimedRegistryThrowsAndNamesTheSubscriberThatPrimesIt(): void
    {
        self::assertFalse(StatusVocabularyRegistry::isPrimed());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('StatusVocabularyRegistrySubscriber');

        StatusVocabularyRegistry::get('invoice');
    }

    public function testItResolvesThroughTheLoaderOncePrimed(): void
    {
        StatusVocabularyRegistry::use($this->loader());

        self::assertTrue(StatusVocabularyRegistry::isPrimed());
        self::assertSame('invoice', StatusVocabularyRegistry::get('invoice')->key);
        self::assertSame(['Draft'], StatusVocabularyRegistry::get('invoice')->slugs());
    }

    public function testAnUnknownKeyStillThrowsFromTheLoaderRatherThanTheRegistry(): void
    {
        StatusVocabularyRegistry::use($this->loader());

        // The control: the real key resolves on the same primed registry, so the failure below is
        // about the key and not about priming.
        self::assertSame('invoice', StatusVocabularyRegistry::get('invoice')->key);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No status vocabulary is registered for "purchase_order"');

        StatusVocabularyRegistry::get('purchase_order');
    }

    public function testResetForgetsTheLoader(): void
    {
        StatusVocabularyRegistry::use($this->loader());
        self::assertTrue(StatusVocabularyRegistry::isPrimed());

        StatusVocabularyRegistry::reset();

        self::assertFalse(StatusVocabularyRegistry::isPrimed(), 'a test must not leak its loader into the next one');
    }
}
