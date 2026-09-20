<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The email_template rows a real database carries must come out of the migration chain free of
 * the "Wholesale Catalog" hardcode, the "Best regards, The Wholesale Catalog Team" sign-off, and
 * the customer@example.test placeholder default that Version20260803080000 (#351) fixes.
 *
 * Same replay-the-real-chain approach as RawSqlTablesSurviveTheChainTest, for the same reason:
 * both suites build their schema from entity mappings, not the migration chain, so email_template
 * carries none of the seeded rows a real (or dev) database has — a functional/PHPUnit test reading
 * the table directly would find it empty and pass whether or not the fix is even present.
 */
#[Group('migrations')]
final class EmailTemplateHardcodesFixedTest extends TestCase
{
    private const CODES = [
        'forgot_password', 'invite', 'new_user_invited', 'company_registration',
        'register', 'email_changed', 'invoice_customer', 'login',
    ];

    private static ?string $databaseFile = null;

    /** @var array<string, array{subject: string, body: string}>|null */
    private static ?array $rows = null;

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$databaseFile, self::$databaseFile . '-wal', self::$databaseFile . '-shm'] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        self::$databaseFile = null;
        self::$rows = null;
    }

    /** @return array<string, array{subject: string, body: string}> */
    private function emailTemplateRowsAfterChain(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $projectDir = \dirname(__DIR__, 2);
        self::$databaseFile = sys_get_temp_dir() . '/email-template-hardcodes-' . getmypid() . '.sqlite';
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
        $rows = [];
        foreach (self::CODES as $code) {
            $stmt = $pdo->prepare('SELECT subject, body FROM email_template WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row, "email_template has no row for code \"$code\" — has it been renamed or dropped?");
            $rows[$code] = ['subject' => (string) $row['subject'], 'body' => (string) $row['body']];
        }

        return self::$rows = $rows;
    }

    public function testNoRowHardcodesTheBusinessName(): void
    {
        $offenders = [];
        foreach ($this->emailTemplateRowsAfterChain() as $code => $row) {
            if (str_contains($row['subject'], 'Wholesale Catalog') || str_contains($row['body'], 'Wholesale Catalog')) {
                $offenders[] = $code;
            }
        }

        self::assertSame([], $offenders, sprintf(
            'These email_template rows still hardcode "Wholesale Catalog" instead of {{ site_name() }}: %s',
            implode(', ', $offenders),
        ));
    }

    public function testNoRowCarriesTheOldSignOff(): void
    {
        $offenders = [];
        foreach ($this->emailTemplateRowsAfterChain() as $code => $row) {
            if (str_contains($row['body'], 'Best regards') || str_contains($row['body'], 'The Wholesale Catalog Team')) {
                $offenders[] = $code;
            }
        }

        self::assertSame([], $offenders, sprintf(
            'These email_template rows still carry the "Best regards, The Wholesale Catalog Team" sign-off: %s',
            implode(', ', $offenders),
        ));
    }

    public function testLoginRowNoLongerDefaultsToThePlaceholderEmail(): void
    {
        $body = $this->emailTemplateRowsAfterChain()['login']['body'];

        self::assertStringNotContainsString("default('customer@example.test')", $body);
        self::assertStringContainsString("default('-')", $body);
    }

    public function testEmailChangedOffersAWorkingContactLink(): void
    {
        $body = $this->emailTemplateRowsAfterChain()['email_changed']['body'];

        self::assertStringContainsString('mailto:{{ support_email }}', $body);
    }

    /**
     * The targeted string-replacement approach must leave everything else in a touched row
     * untouched: an existing |default(...) guard, surrounding markup, unrelated wording. This
     * pins down exactly the rows the fix should NOT have altered further than it needed to.
     */
    public function testUnrelatedDefaultGuardsSurviveTheFix(): void
    {
        $rows = $this->emailTemplateRowsAfterChain();

        self::assertStringContainsString("{{ user_email|default('User') }}", $rows['forgot_password']['body']);
        self::assertStringContainsString("{{ reset_url|default('#') }}", $rows['forgot_password']['body']);
        self::assertStringContainsString("{{ user_email|default('User') }}", $rows['invite']['body']);
        self::assertStringContainsString("{{ reset_url|default('#') }}", $rows['invite']['body']);
        // Version20260919180000 made this template invoice-first, order-optional: every field
        // (including the total) now reads off `invoice`, always present, instead of `order`,
        // present only when the invoice bills one — see that migration's own docblock.
        self::assertStringContainsString("{{ invoice.total|default(0)|number_format(2) }}", $rows['invoice_customer']['body']);
    }
}
