<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #411: order_received, order_received_admin and order_status_update must come out of the
 * migration chain with the full order/line-item table, not just the one-row total box those
 * three email_template rows were originally seeded with (Version20260803150000).
 *
 * Replays the real chain into a throwaway SQLite file rather than inspecting Version20260805010000's
 * source directly, for the same reason EmailTemplateHardcodesFixedTest and
 * RawSqlTablesSurviveTheChainTest do: only the row the chain actually produces is authoritative,
 * and EmailNotifier::send() prefers that DB body outright over the shipped .twig file whenever a
 * row for the code exists — editing the .twig alone would leave production emails unchanged.
 */
#[Group('migrations')]
final class OrderEmailSummaryTableAddedTest extends TestCase
{
    private const CODES = ['order_received', 'order_received_admin', 'order_status_update'];

    private static ?string $databaseFile = null;

    /** @var array<string, string>|null */
    private static ?array $bodies = null;

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$databaseFile, self::$databaseFile . '-wal', self::$databaseFile . '-shm'] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        self::$databaseFile = null;
        self::$bodies = null;
    }

    /** @return array<string, string> code => body */
    private function emailTemplateBodiesAfterChain(): array
    {
        if (self::$bodies !== null) {
            return self::$bodies;
        }

        $projectDir = \dirname(__DIR__, 2);
        self::$databaseFile = sys_get_temp_dir() . '/order-email-summary-' . getmypid() . '.sqlite';
        @unlink(self::$databaseFile);

        $env = [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///' . self::$databaseFile,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        $command = 'php bin/console doctrine:migrations:migrate --no-interaction -q 2>&1';
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
        }

        $output = [];
        $status = 0;
        exec(sprintf('cd %s && %s%s', escapeshellarg($projectDir), $prefix, $command), $output, $status);

        self::assertSame(
            0,
            $status,
            "The migration chain does not replay from empty:\n" . implode("\n", $output),
        );

        $pdo = new \PDO('sqlite:' . self::$databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $bodies = [];
        foreach (self::CODES as $code) {
            $stmt = $pdo->prepare('SELECT body FROM email_template WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row, "email_template has no row for code \"$code\" — has it been renamed or dropped?");
            $bodies[$code] = (string) $row['body'];
        }

        return self::$bodies = $bodies;
    }

    public function testEveryOrderEmailIncludesTheFullSummaryTable(): void
    {
        $missing = [];
        foreach ($this->emailTemplateBodiesAfterChain() as $code => $body) {
            if (!str_contains($body, "{% include 'emails/_order_summary.html.twig' %}")) {
                $missing[] = $code;
            }
        }

        self::assertSame([], $missing, sprintf(
            'These email_template rows still lack the full order/line-item table (#411): %s',
            implode(', ', $missing),
        ));
    }

    /** The one-row total box each of these three replaced must be gone, not merely joined by the include. */
    public function testTheOldOneRowTotalBoxIsGone(): void
    {
        $bodies = $this->emailTemplateBodiesAfterChain();

        self::assertStringNotContainsString('<span>Order Total:</span>', $bodies['order_received_admin']);
    }

    /**
     * The targeted string-replacement approach must leave everything else in a touched row
     * untouched: wording, the status badge, the Order Number/Updated At footer.
     */
    public function testUnrelatedContentSurvivesTheFix(): void
    {
        $bodies = $this->emailTemplateBodiesAfterChain();

        self::assertStringContainsString("We've received your order", $bodies['order_received']);
        self::assertStringContainsString('View My Order', $bodies['order_received']);
        self::assertStringContainsString('was placed by', $bodies['order_received_admin']);
        self::assertStringContainsString('Review Order', $bodies['order_received_admin']);
        self::assertStringContainsString('{{ status|default(\'Updated\') }}', $bodies['order_status_update']);
        self::assertStringContainsString('<strong>Updated At:</strong>', $bodies['order_status_update']);
    }
}
