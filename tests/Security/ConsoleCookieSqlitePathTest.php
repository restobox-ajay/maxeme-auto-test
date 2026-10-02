<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ConsoleCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ConsoleCookie::sqlitePath() resolves a Doctrine SQLite DATABASE_URL to a filesystem path for the
 * out-of-kernel gateway. It is what keeps the gateway pointed at the same database the app uses, so it
 * must handle every path layout a deployment might declare and fail closed (null) on anything that
 * isn't a concrete sqlite file.
 */
final class ConsoleCookieSqlitePathTest extends TestCase
{
    private const ROOT = '/home/wholebcd/app-root';

    public function testResolvesTheConventionPathWithEnvPlaceholder(): void
    {
        // The committed .env form: file directly under var/, env in the name.
        self::assertSame(
            self::ROOT . '/var/data_prod.db',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data_prod.db', self::ROOT),
        );
    }

    public function testResolvesTheEnvironmentPlaceholderAlongsideProjectDir(): void
    {
        // The actual committed .env form: both placeholders in one value. %kernel.environment% is a
        // container parameter Doctrine only ever sees resolved (via framework.yaml's
        // env(resolve:DATABASE_URL)), so plain Dotenv loading outside the kernel leaves it literal —
        // this is what public/db-admin.php must substitute by hand, or it resolves to a file that
        // never exists and fails closed on every request, for the wrong reason.
        self::assertSame(
            self::ROOT . '/var/data_dev.db',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db', self::ROOT, 'dev'),
        );
    }

    public function testResolvesASubdirectoryPath(): void
    {
        // The layout on the dev server: grouped under var/data/, .sqlite extension. This is the case
        // the hardcoded path used to miss, leaving the gateway denying every request.
        self::assertSame(
            self::ROOT . '/var/data/data_prod.sqlite',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data/data_prod.sqlite', self::ROOT),
        );
    }

    public function testResolvesAnAbsolutePathWithoutPlaceholder(): void
    {
        self::assertSame(
            '/srv/data/app.db',
            ConsoleCookie::sqlitePath('sqlite:////srv/data/app.db', self::ROOT),
        );
    }

    public function testDoesNotPrefixAWindowsProjectDirTwice(): void
    {
        // On Windows %kernel.project_dir% is C:\...; the result is already absolute.
        $root = 'C:\\wamp64\\www\\app';

        self::assertSame(
            $root . '/var/data_dev.db',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db', $root, 'dev'),
        );
        self::assertSame('D:/data/app.db', ConsoleCookie::sqlitePath('sqlite:///D:/data/app.db', $root));
    }

    public function testAnchorsARelativePathToTheProjectDir(): void
    {
        self::assertSame(
            self::ROOT . '/var/data.db',
            ConsoleCookie::sqlitePath('sqlite:///var/data.db', self::ROOT),
        );
    }

    public function testDropsAQueryString(): void
    {
        self::assertSame(
            self::ROOT . '/var/data_prod.db',
            ConsoleCookie::sqlitePath('sqlite:///%kernel.project_dir%/var/data_prod.db?cache=shared', self::ROOT),
        );
    }

    #[DataProvider('failsClosedCases')]
    public function testFailsClosedOnNonFilePaths(string $url): void
    {
        self::assertNull(ConsoleCookie::sqlitePath($url, self::ROOT));
    }

    /** @return iterable<string, array{string}> */
    public static function failsClosedCases(): iterable
    {
        yield 'empty' => [''];
        yield 'in-memory' => ['sqlite:///:memory:'];
        yield 'mysql driver' => ['mysql://app:secret@127.0.0.1:3306/app'];
        yield 'postgres driver' => ['postgresql://app@localhost/app'];
        yield 'not a url' => ['nonsense'];
    }
}
