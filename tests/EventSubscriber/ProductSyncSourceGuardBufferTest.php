<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\ProductCore;
use App\EventSubscriber\ProductSyncSourceGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\TestCase;

/**
 * The buffering machinery and the failure paths ProductSyncSourceGuardTest cannot reach, because it
 * runs against a database that works: what the guard does to a connection (and, more importantly,
 * what it never asks a connection for), what happens when the buffer fills, and what happens when
 * the write throws.
 *
 * Everything here drives the listener methods directly against a doubled connection. The wiring —
 * that these methods are called at all, and by the right events — is what
 * ProductSyncSourceGuardTest covers, through the real container.
 */
final class ProductSyncSourceGuardBufferTest extends TestCase
{
    /**
     * Holds the fixtures alive for the length of a test.
     *
     * The guard keeps only a WeakReference to a captured product, so that an import which clears
     * the EntityManager between batches is not kept in memory by a diagnostic. Under a real
     * EntityManager the UnitOfWork is what holds the entity; here nothing else would, and a
     * fixture passed straight into prePersist() as a temporary would be collected before the
     * drain — which is correct behaviour, and not what most of these tests are about.
     *
     * @var list<ProductCore>
     */
    private array $fixtures = [];

    /**
     * The id => sku pairs a doubled connection reports as present, mirroring what product_core
     * would hold. The guard confirms a row against both before writing it.
     *
     * @var array<int, string>
     */
    private array $skusById = [];

    /**
     * The rework's central claim (#490): the guard has no connection but the caller's.
     *
     * getParams() and getConfiguration() are the only way to clone a connection into a second one,
     * which is how the previous version escaped the caller's transaction — and how it ended up
     * queuing behind SQLite's single writer. A guard that never asks for them cannot open one.
     */
    public function testItNeverAsksTheConnectionForWhatWouldOpenASecondOne(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getParams');
        $connection->expects(self::never())->method('getConfiguration');
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllNumeric')->willReturn([[1, 'NO-SECOND-CONN']]);
        // ...and the row still gets written, on the one connection it was given.
        $connection->expects(self::once())->method('insert')->with('error_log', self::anything());

        $guard = new ProductSyncSourceGuard($connection);
        $product = $this->product('NO-SECOND-CONN', 1);

        $guard->prePersist($product, $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
    }

    /**
     * Nothing may be written while a transaction is open — that is the entire mechanism by which a
     * rolled-back product leaves no row. The guard holds the row instead, and the drain at
     * kernel.terminate / console.terminate picks it up once the transaction has closed.
     */
    public function testItWritesNothingWhileATransactionIsStillActive(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('insert');

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($this->product('HELD-1', 1), $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
    }

    /**
     * The buffer is bounded, and says so when it overflows rather than dropping rows in silence.
     */
    public function testTheBufferIsBoundedAndOverflowIsRecordedOnce(): void
    {
        $overflow = 3;
        $written = [];
        $connection = $this->countingConnection($written);

        $guard = new ProductSyncSourceGuard($connection);

        for ($i = 1; $i <= ProductSyncSourceGuard::MAX_BUFFERED_ROWS + $overflow; ++$i) {
            $guard->prePersist($this->product('BULK-' . $i, $i), $this->prePersistArgs());
        }

        $guard->postFlush($this->postFlushArgs());

        $rows = $this->rowsWrittenToTheErrorLog($written);

        self::assertCount(
            ProductSyncSourceGuard::MAX_BUFFERED_ROWS + 1,
            $rows,
            'Every buffered row, plus the single row reporting the ones that were dropped.',
        );

        $lastBuffered = json_decode((string) $rows[ProductSyncSourceGuard::MAX_BUFFERED_ROWS - 1]['message'], true);
        self::assertSame('BULK-' . ProductSyncSourceGuard::MAX_BUFFERED_ROWS, $lastBuffered['sku']);

        $marker = json_decode((string) $rows[ProductSyncSourceGuard::MAX_BUFFERED_ROWS]['message'], true);

        self::assertSame($overflow, $marker['dropped'], 'The persists past the cap should be counted, not forgotten.');
        self::assertSame(ProductSyncSourceGuard::MAX_BUFFERED_ROWS, $marker['limit']);
        self::assertArrayNotHasKey('sku', $marker, 'The overflow row is about the guard, not about any one product.');
        self::assertSame(ProductSyncSourceGuard::AREA, $rows[ProductSyncSourceGuard::MAX_BUFFERED_ROWS]['area']);
    }

    /**
     * Once drained, the buffer is empty again — an overflow is a report about a moment, not a
     * permanent state, and the count must not be repeated on the next drain.
     */
    public function testASecondDrainRewritesNothing(): void
    {
        $written = [];
        $connection = $this->countingConnection($written);

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($this->product('ONCE-1', 1), $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
        $guard->postFlush($this->postFlushArgs());
        $guard->onTerminate();

        self::assertCount(
            1,
            $this->rowsWrittenToTheErrorLog($written),
            'A committed unstamped persist gets exactly one row, however often the drain runs.',
        );
    }

    /**
     * The guard swallows its own failures because a diagnostic that blocks the save is worse than a
     * missing diagnostic.
     */
    public function testAFailingInsertDoesNotEscapeToTheCaller(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllNumeric')->willReturn([[1, 'THROWS-1']]);
        $connection->method('insert')->willThrowException(new \RuntimeException('database is gone'));

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($this->product('THROWS-1', 1), $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());

        $this->expectNotToPerformAssertions();
    }

    /**
     * The same protection has to cover the existence check the drain runs before it writes.
     */
    public function testAFailingExistenceCheckDoesNotEscapeToTheCaller(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllNumeric')->willThrowException(new \RuntimeException('no such table'));
        $connection->expects(self::never())->method('insert');

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($this->product('UNCHECKABLE-1', 1), $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
    }

    /**
     * The same protection has to cover assembling the row, not just writing it — the trace capture
     * and everything read off the product happen inside the same try. This product throws the
     * moment the guard reads it, which is immediately after the trace is taken.
     */
    public function testAThrowWhileBuildingTheMessageDoesNotEscapeToTheCaller(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        $product = new class () extends ProductCore {
            public function getSku(): string
            {
                throw new \RuntimeException('unreadable product');
            }
        };

        (new ProductSyncSourceGuard($connection))->prePersist($product, $this->prePersistArgs());
    }

    public function testItBuffersNothingWhenTheProductAlreadyNamesItsSource(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection->method('isTransactionActive')->willReturn(false);

        $product = $this->product('STAMPED-2', 1)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($product, $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
        $guard->onTerminate();
    }

    /**
     * A product that never reached the database has no id, so it is never promoted out of the
     * capture buffer and never written — the same rule as a rollback, reached the other way.
     */
    public function testAPersistThatWasNeverFlushedIsNeverWritten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('insert');

        $guard = new ProductSyncSourceGuard($connection);

        // No id: the insert never happened.
        $guard->prePersist($this->product('NEVER-FLUSHED'), $this->prePersistArgs());
        $guard->postFlush($this->postFlushArgs());
        $guard->onTerminate();
    }

    /**
     * The capture holds a WeakReference, so a product discarded before it was ever written takes
     * its buffered row with it. That is the same rule again — nothing committed, nothing to say —
     * and it is what keeps a batching import from being held in memory by its own diagnostics.
     */
    public function testAProductCollectedBeforeItWasWrittenIsForgotten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('insert');

        $guard = new ProductSyncSourceGuard($connection);

        $guard->prePersist($this->product('COLLECTED-1', 7), $this->prePersistArgs());

        $this->fixtures = [];
        gc_collect_cycles();

        $guard->postFlush($this->postFlushArgs());
        $guard->onTerminate();
    }

    /**
     * A connection that reports every fixture as present on disk and collects what is written to
     * it, as ['table' => ..., 'row' => ...] per insert.
     *
     * @param list<array{table: string, row: array<string, mixed>}> $written
     */
    private function countingConnection(array &$written): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        // Not a static closure, and the map is read at call time: the fixtures are created after
        // this connection is built.
        $connection->method('fetchAllNumeric')->willReturnCallback(
            fn (string $sql, array $params): array => array_map(
                fn (int $id): array => [$id, $this->skusById[$id] ?? ''],
                $params[0],
            ),
        );
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $row) use (&$written): int {
                $written[] = ['table' => $table, 'row' => $row];

                return 1;
            },
        );

        return $connection;
    }

    /**
     * @param list<array{table: string, row: array<string, mixed>}> $written
     *
     * @return list<array<string, mixed>>
     */
    private function rowsWrittenToTheErrorLog(array $written): array
    {
        $tables = array_values(array_unique(array_column($written, 'table')));

        self::assertSame($written === [] ? [] : ['error_log'], $tables, 'The guard writes to one table and no other.');

        return array_column($written, 'row');
    }

    /**
     * A product that reports an id, standing in for one the ORM has just inserted. Pass no id for
     * one that was never written.
     */
    private function product(string $sku, ?int $id = null): ProductCore
    {
        $product = new class () extends ProductCore {
            public ?int $fakeId = null;

            public function getId(): ?int
            {
                return $this->fakeId;
            }
        };

        $product->fakeId = $id;
        $product->setSku($sku)->setName('Guard fixture ' . $sku);

        if ($id !== null) {
            $this->skusById[$id] = $sku;
        }

        $this->fixtures[] = $product;

        return $product;
    }

    private function prePersistArgs(): PrePersistEventArgs
    {
        return new PrePersistEventArgs(new \stdClass(), $this->createStub(EntityManagerInterface::class));
    }

    private function postFlushArgs(): PostFlushEventArgs
    {
        return new PostFlushEventArgs($this->createStub(EntityManagerInterface::class));
    }
}
