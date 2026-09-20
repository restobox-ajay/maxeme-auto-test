<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260820150000 moves payments onto the invoice and drops the order's payment claim
 * (#539 stage 4).
 *
 * Replayed against a real database rather than read as source, for the reason InvoiceBackfillTest
 * and OrderStatusReanchorTest both state: the behaviour is SQL — a correlated re-pointing insert, a
 * CASE deriving three payment statuses from sums, and a column rebuild SQLite cannot do in place —
 * and only the data the chain actually produces is authoritative. Neither suite would otherwise see
 * this migration at all, since both build their schema from entity metadata.
 *
 * The orders and their payments are seeded BEFORE #539 stage 1, so they are genuinely pre-invoice
 * rows: stage 1 raises their invoices, stage 2 re-anchors their statuses, and this migration then
 * moves the money onto the documents that now own it.
 */
#[Group('migrations')]
final class PaymentsMoveToInvoiceTest extends TestCase
{
    /** The last migration before #539, i.e. the world as it was before any of this. */
    private const PRE_539 = 'DoctrineMigrations\Version20260810120000';

    private const VERSION_UNDER_TEST = 'DoctrineMigrations\Version20260820150000';

    /**
     * Orders with what was paid against them, and what the invoice's derived payment status must
     * become.
     *
     * Every order here totals 50.00. The last one is the case the derivation is FOR: the order
     * claimed to be Paid with no payment recorded against it, which the old free-text column allowed
     * and which stage 4 resolves in favour of the payment rows, because a status is now the sum of
     * them and there is no sum here to be Paid.
     *
     * @var list<array{0: string, 1: ?string, 2: list<string>, 3: string}>
     *      [order number, old order payment status, payment amounts, expected invoice payment status]
     */
    private const SEED_ORDERS = [
        ['SO-1', 'Paid', ['50.00'], 'Paid'],
        ['SO-2', 'Not Paid', [], 'Not Paid'],
        ['SO-3', 'Partially Paid', ['20.00'], 'Partially Paid'],
        ['SO-4', 'Paid', ['20.00', '30.00'], 'Paid'],
        ['SO-5', 'Not Paid', ['60.00'], 'Paid'],
        ['SO-6', 'Paid', [], 'Not Paid'],
        ['SO-7', null, [], 'Not Paid'],
    ];

    /** @var list<string> */
    private static array $temporaryFiles = [];

    private static ?\PDO $replay = null;

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

    public function testEveryPaymentEndsUpOnItsOrdersInvoice(): void
    {
        $pdo = $this->migrated();

        $rows = $pdo->query(
            'SELECT o.order_number AS number, p.amount AS amount
             FROM invoice_payment p
             JOIN invoice i ON i.id = p.invoice_id
             JOIN sales_order o ON o.id = i.sales_order_id
             ORDER BY o.order_number, p.amount',
        )->fetchAll(\PDO::FETCH_ASSOC);

        $expected = [];
        foreach (self::SEED_ORDERS as [$number, , $amounts]) {
            sort($amounts);
            foreach ($amounts as $amount) {
                $expected[] = ['number' => $number, 'amount' => (float) $amount];
            }
        }

        // Compared as floats: amount is NUMERIC, and SQLite hands a NUMERIC column back with its own
        // affinity applied, so '20.00' comes out of PDO as 20 whatever went in.
        $actual = array_map(
            static fn (array $row): array => ['number' => $row['number'], 'amount' => (float) $row['amount']],
            $rows,
        );

        self::assertSame($expected, $actual, 'Every payment must arrive on the invoice of the order it was recorded against.');
    }

    public function testNoPaymentIsLostOrDuplicated(): void
    {
        $pdo = $this->migrated();

        $expected = 0;
        foreach (self::SEED_ORDERS as [, , $amounts]) {
            $expected += count($amounts);
        }

        self::assertSame($expected, (int) $pdo->query('SELECT COUNT(*) FROM invoice_payment')->fetchColumn());
    }

    /** Ids are preserved, so anything that held a payment id still resolves to the same payment. */
    public function testPaymentIdsSurviveTheMove(): void
    {
        $pdo = $this->migrated();

        self::assertSame(
            ['1', '2', '3', '4', '5'],
            array_map(
                static fn ($id): string => (string) $id,
                $pdo->query('SELECT id FROM invoice_payment ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
            ),
        );
    }

    public function testThePaymentStatusIsDerivedFromThePaymentsAndNotCopied(): void
    {
        $pdo = $this->migrated();

        $rows = $pdo->query(
            'SELECT o.order_number AS number, i.payment_status AS status
             FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id
             ORDER BY o.order_number',
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $expected = [];
        foreach (self::SEED_ORDERS as [$number, , , $paymentStatus]) {
            $expected[$number] = $paymentStatus;
        }

        self::assertSame($expected, $rows);
    }

    public function testTheOldPaymentTableIsGone(): void
    {
        $pdo = $this->migrated();

        self::assertNotContains('sales_order_payment', $this->tables($pdo));
        self::assertContains('invoice_payment', $this->tables($pdo));
    }

    /**
     * The order's own claim is dropped outright rather than left dormant: a column nothing
     * recomputes is a second answer that is wrong the first time a payment is recorded.
     */
    public function testSalesOrderNoLongerHasAPaymentStatusColumn(): void
    {
        $pdo = $this->migrated();

        self::assertNotContains('payment_status', $this->columns($pdo, 'sales_order'));
        // The terms of the sale stay: they are not answers about money.
        self::assertContains('payment_method', $this->columns($pdo, 'sales_order'));
        self::assertContains('payment_term', $this->columns($pdo, 'sales_order'));
    }

    /** Derived means always present, which is what lets the grids filter and sort on it. */
    public function testTheInvoicesPaymentStatusColumnIsNotNullable(): void
    {
        $pdo = $this->migrated();

        $column = null;
        foreach ($pdo->query('PRAGMA table_info(invoice)')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['name'] === 'payment_status') {
                $column = $row;
            }
        }

        self::assertNotNull($column);
        self::assertSame(1, (int) $column['notnull']);
    }

    public function testTheStripeIntentIdStaysUniqueSoAWebhookRetryCannotDoubleRecord(): void
    {
        $pdo = $this->migrated();

        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'invoice_payment'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('uniq_invoice_payment_stripe_intent', $indexes);
    }

    /** @return list<string> */
    private function tables(\PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $pdo->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * Replays the chain: pre-#539, seed, then everything up to and including the migration under
     * test. Cached across this class's test methods — one replay is about a second, and running it
     * eight times would be eight seconds for no extra coverage.
     */
    private function migrated(): \PDO
    {
        if (self::$replay instanceof \PDO) {
            return self::$replay;
        }

        $projectDir = \dirname(__DIR__, 2);
        $databaseFile = sys_get_temp_dir() . '/payments-to-invoice-' . getmypid() . '.sqlite';
        @unlink($databaseFile);
        self::$temporaryFiles[] = $databaseFile;

        $this->migrate($projectDir, $databaseFile, self::PRE_539);

        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->seed($pdo);
        // Closed so the migrations below get the file to themselves.
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

    private function seed(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO company (id, name, code, status, account_type, created_at, api_enabled) VALUES (1, 'Acme Co', 'ACME', 'Active', 'Wholesale', '2026-01-01 00:00:00', 0)");

        $order = $pdo->prepare(
            'INSERT INTO sales_order
                (id, company_id, order_number, status, payment_status, version, document_date,
                 subtotal, tax, total, source, created_at)
             VALUES (?, 1, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
        );
        $line = $pdo->prepare(
            'INSERT INTO sales_order_line (order_id, name, sku, quantity, cost, price, subtotal, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $payment = $pdo->prepare(
            'INSERT INTO sales_order_payment (order_id, received_at, method, amount, created_at)
             VALUES (?, ?, ?, ?, ?)',
        );

        foreach (self::SEED_ORDERS as $index => [$number, $paymentStatus, $amounts]) {
            $id = $index + 1;
            $order->execute([$id, $number, 'Completed', $paymentStatus, '2026-05-09', '50.00', '0.00', '50.00', 'Admin', '2026-05-09 09:00:00']);
            $line->execute([$id, 'Widget', 'WIDGET-1', '10.00', '2.00', '5.00', '50.00', 0]);

            foreach ($amounts as $amount) {
                $payment->execute([$id, '2026-05-10', 'Bank Transfer', $amount, '2026-05-10 09:00:00']);
            }
        }
    }
}
