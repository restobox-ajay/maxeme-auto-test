<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260922100000 adds the `company_id`/`vendor_id` column #708's pool/claim split needed on
 * `invoice_payment`/`vendor_bill_payment` but never got, and drops the pre-#708 `invoice_id`/
 * `vendor_bill_id NOT NULL` column nothing populates any more (#765/#766).
 *
 * Replayed against a real database rather than read as source, for the reason PaymentsMoveToInvoice
 * Test states: the behaviour is a correlated backfill and a column rebuild SQLite cannot do in
 * place, and PHPUnit/Codeception would never see either — both build their schema from CURRENT
 * entity metadata (`doctrine:schema:create`), which already has `company_id`/`vendor_id` mapped and
 * so never exercises the migration chain a real, already-deployed database has to climb.
 */
#[Group('migrations')]
final class InvoicePaymentCompanyIdAddedTest extends TestCase
{
    /** The last migration before this one — invoice_payment_application exists, the pool tables don't yet. */
    private const PRE = 'DoctrineMigrations\Version20260922090000';

    private const VERSION_UNDER_TEST = 'DoctrineMigrations\Version20260922100000';

    private static ?\PDO $replay = null;

    /** @var list<string> */
    private static array $temporaryFiles = [];

    public static function tearDownAfterClass(): void
    {
        self::$replay = null;

        foreach (self::$temporaryFiles as $file) {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        self::$temporaryFiles = [];
    }

    public function testInvoicePaymentGainsCompanyIdAndLosesInvoiceId(): void
    {
        $pdo = $this->migrated();

        $columns = $this->columns($pdo, 'invoice_payment');
        self::assertContains('company_id', $columns, 'the column #708 needed is finally there');
        self::assertNotContains('invoice_id', $columns, 'and the pre-#708 column nothing populates any more is gone');
    }

    public function testVendorBillPaymentGainsVendorIdAndLosesVendorBillId(): void
    {
        $pdo = $this->migrated();

        $columns = $this->columns($pdo, 'vendor_bill_payment');
        self::assertContains('vendor_id', $columns);
        self::assertNotContains('vendor_bill_id', $columns);
    }

    /** The value backfilled is not a guess: it is each row's own former invoice's company. */
    public function testExistingPaymentsAreBackfilledFromTheInvoiceTheyWereRecordedAgainst(): void
    {
        $pdo = $this->migrated();

        $companyId = $pdo->query('SELECT company_id FROM invoice_payment WHERE id = 1')->fetchColumn();
        self::assertSame('1', (string) $companyId, 'the invoice this payment was recorded against belongs to company 1');
    }

    public function testExistingBillPaymentsAreBackfilledFromTheBillTheyWereRecordedAgainst(): void
    {
        $pdo = $this->migrated();

        $vendorId = $pdo->query('SELECT vendor_id FROM vendor_bill_payment WHERE id = 1')->fetchColumn();
        self::assertSame('1', (string) $vendorId, 'the bill this payment was recorded against belongs to vendor 1');
    }

    public function testNoRowIsOrphanedByTheRebuild(): void
    {
        $pdo = $this->migrated();

        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([], $violations);
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $pdo->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function migrated(): \PDO
    {
        if (self::$replay instanceof \PDO) {
            return self::$replay;
        }

        $projectDir = \dirname(__DIR__, 2);
        $databaseFile = sys_get_temp_dir() . '/invoice-payment-company-id-' . getmypid() . '.sqlite';
        @unlink($databaseFile);
        self::$temporaryFiles[] = $databaseFile;

        $this->migrate($projectDir, $databaseFile, self::PRE);

        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $this->seed($pdo);
        $pdo = null;

        $this->migrate($projectDir, $databaseFile, self::VERSION_UNDER_TEST);

        return self::$replay = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function migrate(string $projectDir, string $databaseFile, string $version): void
    {
        $env = [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///' . $databaseFile,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
        }

        $output = [];
        $status = 0;
        exec(
            sprintf(
                'cd %s && %sphp bin/console doctrine:migrations:migrate %s --no-interaction -q 2>&1',
                escapeshellarg($projectDir),
                $prefix,
                escapeshellarg($version),
            ),
            $output,
            $status,
        );

        self::assertSame(0, $status, sprintf("Migrating to %s failed:\n%s", $version, implode("\n", $output)));
    }

    /** One company, one invoice, one payment against it (the pre-#708 shape) — and the buy-side mirror. */
    private function seed(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO company (id, name, code, status, account_type, created_at, api_enabled) VALUES (1, 'Acme Co', 'ACME', 'Active', 'Wholesale', '2026-01-01 00:00:00', 0)");
        $pdo->exec("INSERT INTO invoice (id, company_id, document_number, status, payment_status, document_date, subtotal, tax, total, source, created_at) VALUES (1, 1, 'INV-1', 'Issued', 'Not Paid', '2026-09-01', '100.00', '0.00', '100.00', 'Admin', '2026-09-01 09:00:00')");
        $pdo->exec("INSERT INTO invoice_payment (id, invoice_id, received_at, method, amount, created_at) VALUES (1, 1, '2026-09-02', 'Bank Transfer', '100.00', '2026-09-02 09:00:00')");

        $pdo->exec("INSERT INTO vendor (id, name, currency, status, created_at) VALUES (1, 'Acme Supply', 'CAD', 'Active', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO vendor_bill (id, vendor_id, vendor_name, bill_number, status, document_date, currency, subtotal, tax, total, created_at) VALUES (1, 1, 'Acme Supply', 'BILL-1', 'Approved', '2026-09-01', 'CAD', '50.00', '0.00', '50.00', '2026-09-01 09:00:00')");
        $pdo->exec("INSERT INTO vendor_bill_payment (id, vendor_bill_id, paid_at, method, amount, created_at) VALUES (1, 1, '2026-09-02', 'Bank Transfer', '50.00', '2026-09-02 09:00:00')");
    }
}
