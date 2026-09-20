<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SqliteWalMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\TestCase;

final class SqliteWalMiddlewareTest extends TestCase
{
    public function testConnectAppliesConfiguredPragmasForPdoSqliteDriver(): void
    {
        $executedSql = $this->captureConnectPragmas(new SqliteWalMiddleware(), ['driver' => 'pdo_sqlite']);

        $this->assertSame([
            'PRAGMA journal_mode = WAL',
            'PRAGMA busy_timeout = 500',
            'PRAGMA foreign_keys = ON',
            'PRAGMA locking_mode = NORMAL',
            'PRAGMA synchronous = FULL',
        ], $executedSql);
    }

    public function testDefaultSynchronousLevelIsFullSoDeploymentsAreDurableWithoutConfiguringAnything(): void
    {
        $this->assertContains('PRAGMA synchronous = FULL', (new SqliteWalMiddleware())->pragmas());
    }

    public function testConfiguredSynchronousLevelReplacesOnlyThatPragmaAndLeavesTheRestIntact(): void
    {
        $executedSql = $this->captureConnectPragmas(
            new SqliteWalMiddleware(SqliteWalMiddleware::SYNCHRONOUS_OFF),
            ['driver' => 'pdo_sqlite'],
        );

        $this->assertSame([
            'PRAGMA journal_mode = WAL',
            'PRAGMA busy_timeout = 500',
            'PRAGMA foreign_keys = ON',
            'PRAGMA locking_mode = NORMAL',
            'PRAGMA synchronous = OFF',
        ], $executedSql);
    }

    public function testSynchronousLevelIsAcceptedCaseInsensitively(): void
    {
        $this->assertContains('PRAGMA synchronous = NORMAL', (new SqliteWalMiddleware('normal'))->pragmas());
    }

    /**
     * The level is interpolated into SQL rather than bound, so an unrecognised value must be
     * rejected outright instead of reaching the database.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedSynchronousLevels')]
    public function testUnknownSynchronousLevelIsRejectedRatherThanConcatenatedIntoSql(string $level): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SqliteWalMiddleware($level);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedSynchronousLevels(): iterable
    {
        yield 'unknown keyword' => ['SOMETIMES'];
        yield 'empty string' => [''];
        yield 'trailing statement' => ['OFF; DROP TABLE users'];
        yield 'numeric alias is not accepted' => ['2'];
    }

    public function testConnectDoesNotApplyPragmasForNonSqliteDriver(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('exec');

        $wrappedDriver = $this->createMock(Driver::class);
        $wrappedDriver->expects($this->once())
            ->method('connect')
            ->with(['driver' => 'pdo_mysql'])
            ->willReturn($connection);

        $wrappedConnection = (new SqliteWalMiddleware())->wrap($wrappedDriver)->connect(['driver' => 'pdo_mysql']);

        $this->assertSame($connection, $wrappedConnection);
    }

    public function testConnectDoesNotApplyPragmasWhenDriverKeyMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('exec');

        $wrappedDriver = $this->createMock(Driver::class);
        $wrappedDriver->expects($this->once())
            ->method('connect')
            ->with([])
            ->willReturn($connection);

        (new SqliteWalMiddleware())->wrap($wrappedDriver)->connect([]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function captureConnectPragmas(SqliteWalMiddleware $middleware, array $params): array
    {
        $executedSql = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(5))
            ->method('exec')
            ->willReturnCallback(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $wrappedDriver = $this->createMock(Driver::class);
        $wrappedDriver->expects($this->once())
            ->method('connect')
            ->with($params)
            ->willReturn($connection);

        $wrappedConnection = $middleware->wrap($wrappedDriver)->connect($params);

        $this->assertSame($connection, $wrappedConnection);

        return $executedSql;
    }
}
