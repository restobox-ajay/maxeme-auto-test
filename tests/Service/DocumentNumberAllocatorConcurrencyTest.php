<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DocumentNumberAllocator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Proves the actual claim in #304: N genuinely concurrent allocations for the same (kind, prefix)
 * never produce a duplicate. A single PHP process can't demonstrate a database race — everything it
 * does is serialized by the interpreter — so this forks real OS processes, each with its own SQLite
 * connection to the same file, and has them race to allocate at (as close as this gets to) the same
 * moment.
 *
 * Skipped where pcntl isn't available (the extension is optional; CI/dev here has it).
 */
final class DocumentNumberAllocatorConcurrencyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available.');
        }

        $this->dbPath = sys_get_temp_dir() . '/document_number_allocator_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE document_number_counter (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(20) NOT NULL, prefix VARCHAR(20) NOT NULL, last_value INTEGER NOT NULL, UNIQUE (kind, prefix))');
        $pdo->exec('CREATE TABLE sales_order (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number VARCHAR(80))');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dbPath . $suffix);
        }
    }

    private function entityManagerFor(string $path): EntityManagerInterface
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }

    public function testConcurrentAllocationsAcrossRealProcessesNeverCollide(): void
    {
        $processCount = 6;
        $pipe = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertNotFalse($pipe, 'could not create a socket pair for child->parent results');
        [$parentSocket, $childSocket] = $pipe;

        $pids = [];
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                // Child: allocate exactly once, report the result, exit. Runs in the child's own
                // memory space with its own SQLite connection — nothing here is shared with the
                // parent or the other children except the database file on disk.
                fclose($parentSocket);
                try {
                    $allocator = new DocumentNumberAllocator();
                    $value = $allocator->next($this->entityManagerFor($this->dbPath), 'order', 'CONC', 'sales_order', 'order_number');
                    fwrite($childSocket, $value . "\n");
                } catch (\Throwable $e) {
                    fwrite($childSocket, 'ERROR: ' . $e->getMessage() . "\n");
                }
                fclose($childSocket);
                exit(0);
            }

            $pids[] = $pid;
        }

        fclose($childSocket);

        $lines = [];
        while (count($lines) < $processCount) {
            $line = fgets($parentSocket);
            if ($line === false) {
                break;
            }
            $lines[] = trim($line);
        }
        fclose($parentSocket);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        self::assertCount($processCount, $lines, 'not every child reported a value — one likely crashed or deadlocked: ' . implode(' | ', $lines));
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^\d+$/', $line, 'a child reported an error instead of a value: ' . $line);
        }
        $results = array_map('intval', $lines);
        self::assertCount(
            $processCount,
            array_unique($results),
            'two processes allocated the same order number: ' . implode(', ', $results),
        );
        self::assertSame(range(1, $processCount), self::sorted($results), 'allocated numbers should be exactly 1..N with no gaps or duplicates');
    }

    /** @param list<int> $values @return list<int> */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
