<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ConsoleCookie;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the REAL public/db-admin.php over an actual HTTP request — via PHP's built-in web server —
 * rather than a stand-in for it. The gateway's whole authorisation decision lives inline in that one
 * file on purpose (see its docblock), so this drives the file itself instead of extracting its logic
 * into something separately testable.
 *
 * Covers the three key/expiry/IP combinations that matter beyond the plain valid-vs-invalid case: a
 * right token that has expired, a wrong token where a live session happens to exist, and a wrong token
 * where the only session on file also happens to be expired. In every case the requesting IP is the
 * legitimate one (127.0.0.1, since the built-in server sees connections from itself) — what's varied is
 * strictly the token and its expiry — so a failure here is unambiguously a key/expiry mistake, not an
 * IP one.
 */
final class DbAdminGatewaySubprocessTest extends TestCase
{
    private const HOST = '127.0.0.1';

    private string $projectRoot;
    private string $dbPath;
    private \PDO $pdo;
    private int $port;

    /** @var resource */
    private $serverProcess;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->dbPath = $this->projectRoot . '/var/data_test.db';

        // A dedicated, minimal schema — only the tables and columns public/db-admin.php actually
        // touches — rebuilt fresh so this test never depends on what another suite left behind.
        $this->resetDatabaseFile();
        $this->pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE admin_user (id INTEGER PRIMARY KEY, email TEXT, roles TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE db_console_session (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT, admin_id INTEGER, expires_at TEXT, ip_address TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE db_console_throttle (ip_address TEXT PRIMARY KEY, window_start INTEGER, attempts INTEGER)');
        $this->pdo->exec('CREATE TABLE app_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT, name TEXT, setting_value TEXT, description TEXT, created_at TEXT, updated_at TEXT, category TEXT)');
        $this->pdo->exec(
            "INSERT INTO admin_user (id, email, roles, status) VALUES (1, 'ts@example.test', '[\"ROLE_TECH_SUPPORT\"]', 'Active')"
        );

        // The kill-switch is ON for every test in this class. Without it the gateway would refuse
        // before ever reaching the token/expiry/IP checks these tests exist to exercise, and each test
        // would pass for the wrong reason.
        $this->setKillSwitch(time() + 1800);

        $this->port = $this->findFreePort();
        $this->serverProcess = $this->startBuiltInServer();
        $this->waitUntilServing();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        $this->resetDatabaseFile();
    }

    // ---- the three combinations ------------------------------------------------------------

    public function testValidIpRightTokenButExpiredIsDenied(): void
    {
        $this->mintSession('good-token', adminId: 1, expiresAt: time() - 5, ip: self::HOST);

        $this->assertDenied($this->requestGateway('good-token'), 'a token that matches but has expired must be refused');
    }

    public function testValidIpWrongTokenNotExpiredIsDenied(): void
    {
        // A live, non-expired session exists for the legitimate IP, but the request presents a
        // different token than the one that session was minted for.
        $this->mintSession('good-token', adminId: 1, expiresAt: time() + 1800, ip: self::HOST);

        $this->assertDenied($this->requestGateway('wrong-token'), 'a token that matches no session must be refused, even while a live one exists');
    }

    public function testValidIpWrongTokenAndExpiredIsDenied(): void
    {
        // The only session on file is both for a different token AND already expired.
        $this->mintSession('good-token', adminId: 1, expiresAt: time() - 5, ip: self::HOST);

        $this->assertDenied($this->requestGateway('wrong-token'), 'a wrong token must be refused regardless of any expired session on file');
    }

    // ---- the kill-switch --------------------------------------------------------------------

    public function testKillSwitchOffRefusesAnOtherwiseValidSession(): void
    {
        // Everything about this request is correct — right token, right IP, not expired, live account.
        // Switching the console off must still shut it out, which is what makes the switch a usable
        // incident-response lever against a session that is already open.
        $this->mintSession('good-token', adminId: 1, expiresAt: time() + 1800, ip: self::HOST);
        $this->setKillSwitch(0);

        $this->assertDenied($this->requestGateway('good-token'), 'switching the console off must lock out a live session');
    }

    // ---- harness ----------------------------------------------------------------------------

    private function setKillSwitch(int $enabledUntil): void
    {
        $this->pdo->exec('DELETE FROM app_setting');
        $this->pdo->prepare('INSERT INTO app_setting (setting_key, name, setting_value, created_at) VALUES (?, ?, ?, ?)')
            ->execute(['db_console_enabled_until', 'Database Console Enabled Until', (string) $enabledUntil, date('Y-m-d H:i:s')]);
    }

    private function mintSession(string $token, int $adminId, int $expiresAt, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO db_console_session (token_hash, admin_id, expires_at, ip_address, created_at) VALUES (:h, :a, :e, :i, :c)'
        );
        $stmt->execute([
            'h' => ConsoleCookie::hashToken($token),
            'a' => $adminId,
            'e' => date('Y-m-d H:i:s', $expiresAt),
            'i' => $ip,
            'c' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<string> the raw HTTP response headers from hitting the real gateway file */
    private function requestGateway(string $token): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Cookie: ' . ConsoleCookie::COOKIE_NAME . '=' . $token . "\r\n",
                'ignore_errors' => true,
                'follow_location' => false,
                'timeout' => 5,
            ],
        ]);

        @file_get_contents('http://' . self::HOST . ':' . $this->port . '/db-admin.php', false, $context);

        return $http_response_header ?? [];
    }

    /** @param list<string> $headers */
    private function assertDenied(array $headers, string $message): void
    {
        self::assertNotEmpty($headers, 'the gateway must respond at all');

        $location = null;
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
        }

        self::assertSame('/admin/login', $location, $message);
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0', $errno, $errstr);
        if ($socket === false) {
            self::fail("Could not allocate a free port for the built-in server: $errstr");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @return resource */
    private function startBuiltInServer()
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-S', self::HOST . ':' . $this->port,
                '-t', $this->projectRoot . '/public',
                $this->projectRoot . '/tests/Support/DbAdminGatewayRouter.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
        );

        if (!is_resource($process)) {
            self::fail('Could not start the built-in server for the gateway test.');
        }

        return $process;
    }

    private function waitUntilServing(): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen(self::HOST, $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        self::fail('The built-in server for the gateway test never became ready.');
    }

    private function resetDatabaseFile(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = $this->dbPath . $suffix;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
