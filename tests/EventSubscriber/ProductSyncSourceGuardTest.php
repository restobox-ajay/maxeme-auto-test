<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\ProductCore;
use App\EventSubscriber\ProductSyncSourceGuard;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Covers the guard through the real registered listeners rather than by calling prePersist() by
 * hand: a mocked EntityManager fires no Doctrine events, so a hand-called listener would still pass
 * every assertion here on the day the #[AsEntityListener] attribute is deleted. The terminate cases
 * dispatch the real kernel events through the container's dispatcher for the same reason.
 *
 * The guard applies everywhere with no exemptions, this environment included, so nothing here has
 * to switch it on.
 */
final class ProductSyncSourceGuardTest extends DoctrineIntegrationTestCase
{
    public function testPersistingWithoutASyncSourceWritesExactlyOneRowNamingTheSku(): void
    {
        $product = $this->makeProduct('UNSTAMPED-1');
        $this->em->persist($product);
        $this->em->flush();

        $rows = $this->guardRows();

        self::assertCount(1, $rows, 'One unstamped product should produce exactly one error_log row.');
        self::assertSame('warning', $rows[0]['level']);
        self::assertSame(ProductSyncSourceGuard::AREA, $rows[0]['area']);

        $payload = json_decode((string) $rows[0]['message'], true);

        self::assertIsArray($payload, 'The message should decode, so /admin/error-log can lift file and line out of it.');
        self::assertSame('UNSTAMPED-1', $payload['sku']);
        self::assertStringContainsString('UNSTAMPED-1', (string) $payload['message']);
        self::assertStringContainsString('sync_source', (string) $payload['message']);
    }

    /**
     * The row has to name the offending path, not merely report that something forgot — otherwise
     * it says exactly what a nightly count of null sources already says. The trace is taken at
     * persist() even though the row is written much later, because by write time that stack is gone.
     */
    public function testTheRowNamesTheCodeThatPersistedTheProduct(): void
    {
        $this->em->persist($this->makeProduct('UNSTAMPED-CALLER'));
        $this->em->flush();

        $payload = json_decode((string) $this->guardRows()[0]['message'], true);

        self::assertSame(self::class . '::' . __FUNCTION__, $payload['persistedBy']);
        self::assertSame(__FILE__, $payload['file'], 'The file and line should point at the persist() call, not at the guard.');
        self::assertGreaterThan(0, $payload['line']);
        self::assertNotEmpty($payload['trace']);
    }

    public function testPersistingWithASyncSourceWritesNothing(): void
    {
        $product = $this->makeProduct('STAMPED-1');
        $product->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $this->em->persist($product);
        $this->em->flush();

        self::assertSame([], $this->guardRows());
    }

    /**
     * An empty string is the same hole as null: nothing owns the product, so no feed's scoping
     * protects it.
     */
    public function testAWhitespaceOnlySyncSourceCountsAsMissing(): void
    {
        $product = $this->makeProduct('BLANK-1');
        $product->setSyncSource('   ');

        $this->em->persist($product);
        $this->em->flush();

        self::assertCount(1, $this->guardRows());
    }

    /**
     * The real failure, not a mocked one: with the table gone the insert raises, which is the
     * closest this suite can get to the database being unavailable at exactly the wrong moment.
     */
    public function testAFailureInsideTheGuardDoesNotPreventTheProductBeingSaved(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE error_log');

        $product = $this->makeProduct('SAVES-ANYWAY');
        $this->em->persist($product);
        $this->em->flush();
        $this->terminateRequest();

        self::assertNotNull($product->getId());
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_core WHERE sku = ?', ['SAVES-ANYWAY']),
        );
    }

    /**
     * prePersist runs in the middle of somebody else's work. The guard must not write, flush or
     * disturb anything while it is standing there — which is why it only buffers.
     */
    public function testTheGuardDoesNotFlushOrDisturbTheCallingUnitOfWork(): void
    {
        $alreadyPending = $this->makeProduct('PENDING-1');
        $alreadyPending->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($alreadyPending);

        $unstamped = $this->makeProduct('UNSTAMPED-2');
        $this->em->persist($unstamped);

        $unitOfWork = $this->em->getUnitOfWork();

        self::assertTrue($unitOfWork->isScheduledForInsert($alreadyPending), "The caller's pending insert should still be pending.");
        self::assertTrue($unitOfWork->isScheduledForInsert($unstamped));
        self::assertNull($alreadyPending->getId());
        self::assertNull($unstamped->getId());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_core'));

        // ...and nothing is on disk yet either: until the product commits there is nothing true to
        // say about it.
        self::assertSame([], $this->guardRows());

        $this->em->flush();

        self::assertCount(1, $this->guardRows());
    }

    /**
     * The defect #490 fixes. A product that was rolled back does not exist, so it cannot have a
     * missing-source problem, and a row describing it would be noise about an unrelated failure
     * pointing at code that did nothing wrong. The guard waits out the transaction and then finds
     * nothing to report.
     */
    public function testARolledBackPersistLeavesNoRowAtAll(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->em->persist($this->makeProduct('ROLLED-BACK'));
            $this->em->flush();

            self::assertSame([], $this->guardRows(), 'Nothing may be written while the transaction is still open.');
        } finally {
            $connection->rollBack();
        }

        $this->em->clear();
        $this->terminateRequest();

        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM product_core WHERE sku = ?', ['ROLLED-BACK']),
            'The product itself should be gone — otherwise this test is not exercising a rollback.',
        );
        self::assertSame([], $this->guardRows(), 'A product that does not exist gets no row.');
    }

    /**
     * The other half of the same mechanism: an explicit transaction that commits does produce the
     * row, once it is over. postFlush cannot serve this case — it still runs inside the caller's
     * transaction — so this is what kernel.terminate is for.
     */
    public function testAPersistInsideAnExplicitTransactionIsWrittenOnceItCommits(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->persist($this->makeProduct('COMMITTED-TX'));
        $this->em->flush();

        self::assertSame([], $this->guardRows(), 'Still undecided while the transaction is open.');

        $connection->commit();

        $this->terminateRequest();

        $rows = $this->guardRows();

        self::assertCount(1, $rows);
        self::assertStringContainsString('COMMITTED-TX', (string) $rows[0]['message']);
    }

    /**
     * Imports are the paths most likely to trip this guard and they never touch the HTTP kernel, so
     * console.terminate has to drain too.
     */
    public function testTheConsoleTerminateEventDrainsTheBufferAsWell(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->persist($this->makeProduct('CLI-TX'));
        $this->em->flush();
        $connection->commit();

        self::assertSame([], $this->guardRows());

        $this->dispatcher()->dispatch(
            new ConsoleTerminateEvent(new Command('app:whatever'), new ArrayInput([]), new NullOutput(), 0),
            ConsoleEvents::TERMINATE,
        );

        self::assertCount(1, $this->guardRows());
    }

    /**
     * The second connection is gone, not merely unused (#490). Each SQLite connection holds its own
     * descriptor on the database file, so the count of descriptors pointing at it is a direct
     * reading of how many connections exist — and the previous version, which cloned the caller's
     * connection in prePersist and kept the handle, would show two here.
     */
    public function testTheGuardOpensNoConnectionOfItsOwn(): void
    {
        $before = $this->openHandlesOnTheDatabase();

        self::assertGreaterThan(0, $before, "The caller's own connection should be open, or this test measures nothing.");

        $this->em->persist($this->makeProduct('NO-SECOND-CONN'));
        $this->em->flush();
        $this->terminateRequest();

        self::assertCount(1, $this->guardRows(), 'The row is still written — on the connection that was already there.');
        self::assertSame($before, $this->openHandlesOnTheDatabase(), 'The guard must not open a connection of its own.');
    }

    /**
     * Detecting an unstamped product and quietly stamping one are different things. A default
     * invented here would be a guess written into the column whose entire value is being trusted.
     */
    public function testTheGuardNeverMutatesTheProduct(): void
    {
        $product = $this->makeProduct('UNTOUCHED-1');
        $this->em->persist($product);

        self::assertNull($product->getSyncSource(), 'The guard must not stamp the product on the way through.');

        $this->em->flush();

        self::assertNull($product->getSyncSource());

        $this->terminateRequest();
        $this->em->clear();

        $reloaded = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'UNTOUCHED-1']);

        self::assertInstanceOf(ProductCore::class, $reloaded);
        self::assertNull($reloaded->getSyncSource(), 'The stored row must still record no source.');
    }

    /**
     * The point of recording a trace rather than just a class name: when the immediate caller is a
     * shared service, the frame behind it is what tells an admin-form save from an import batch.
     */
    public function testTheTraceNamesTheMethodThatPersistedTheProduct(): void
    {
        $this->persistThroughAHelper('TRACED-1', 'irrelevant');

        $trace = (string) $this->payload()['trace'];

        self::assertStringContainsString(self::class . '->persistThroughAHelper()', $trace);
        self::assertStringContainsString(self::class . '->' . __FUNCTION__ . '()', $trace, 'The frame behind the immediate caller should be in the trace too.');
        self::assertStringContainsString(__FILE__, $trace);
        self::assertStringStartsWith('#0 ', $trace);
    }

    /**
     * Arguments are captured nowhere. They are entities, request payloads and import rows, and this
     * table is rendered at /admin/error-log — the question the row answers is which path ran, not
     * what it was carrying.
     */
    public function testCallArgumentsAreNotCaptured(): void
    {
        $secret = 'sk-live-do-not-log-me-4f9c2a';

        $this->persistThroughAHelper('NO-ARGS-1', $secret);

        $stored = (string) $this->guardRows()[0]['message'];

        self::assertStringNotContainsString($secret, $stored, 'An argument value must never reach the error log.');
        self::assertStringContainsString('NO-ARGS-1', $stored, '...but the SKU still has to be there, or this asserts nothing.');
    }

    public function testTheTraceIsCapped(): void
    {
        $this->recurseThenPersist(ProductSyncSourceGuard::MAX_TRACE_FRAMES + 20, 'DEEP-1');

        $trace = (string) $this->payload()['trace'];
        $lines = explode("\n", $trace);

        self::assertStringContainsString('[trace truncated after ' . ProductSyncSourceGuard::MAX_TRACE_FRAMES . ' frames', $trace);
        self::assertCount(
            ProductSyncSourceGuard::MAX_TRACE_FRAMES + 1,
            $lines,
            'Capped frames plus the one line that says so.',
        );
        self::assertLessThanOrEqual(ProductSyncSourceGuard::MAX_TRACE_BYTES + 64, strlen($trace));
    }

    /**
     * A shallow stack must not be dressed up as a capped one, or the marker stops meaning anything.
     */
    public function testAShortTraceIsNotMarkedTruncated(): void
    {
        $this->em->persist($this->makeProduct('SHALLOW-1'));
        $this->em->flush();

        self::assertStringNotContainsString('truncated', (string) $this->payload()['trace']);
    }

    /** $sensitiveArgument is deliberately unused: its only job is to exist as a call argument. */
    private function persistThroughAHelper(string $sku, string $sensitiveArgument): void
    {
        $this->em->persist($this->makeProduct($sku));
        $this->em->flush();
    }

    private function recurseThenPersist(int $depth, string $sku): void
    {
        if ($depth > 0) {
            $this->recurseThenPersist($depth - 1, $sku);

            return;
        }

        $this->em->persist($this->makeProduct($sku));
        $this->em->flush();
    }

    private function makeProduct(string $sku): ProductCore
    {
        $product = new ProductCore();
        $product->setSku($sku);
        $product->setName('Guard fixture ' . $sku);

        return $product;
    }

    /**
     * The kernel's last event, dispatched for real so the listener registration is part of what is
     * under test.
     */
    private function terminateRequest(): void
    {
        $this->dispatcher()->dispatch(
            new TerminateEvent(self::$kernel, new Request(), new Response()),
            KernelEvents::TERMINATE,
        );
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');

        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * Open file descriptors pointing at the test database file. One per SQLite connection; the WAL
     * and shared-memory files are separate paths and are not counted.
     */
    private function openHandlesOnTheDatabase(): int
    {
        $path = realpath(dirname(__DIR__, 2) . '/var/data_test.db');

        self::assertIsString($path);

        $open = 0;

        foreach ((array) glob('/proc/self/fd/*') as $descriptor) {
            if (@readlink((string) $descriptor) === $path) {
                ++$open;
            }
        }

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $rows = $this->guardRows();

        self::assertCount(1, $rows);

        $payload = json_decode((string) $rows[0]['message'], true);

        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function guardRows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT level, area, message FROM error_log WHERE area = ? ORDER BY id',
            [ProductSyncSourceGuard::AREA],
        );
    }
}
