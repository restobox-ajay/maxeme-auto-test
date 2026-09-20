<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260820130000 re-anchors sales_order.status onto the rewritten SalesOrderStatus
 * (#539 stage 2).
 *
 * Replayed against a real database rather than read as source, for the reason InvoiceBackfillTest
 * states: the behaviour is a nested SQL CASE, and only the data the chain actually produces is
 * authoritative. Neither suite would otherwise see this migration at all — both build their schema
 * from entity metadata via SchemaTool and never run a migration.
 *
 * Seeded before stage 1 so the whole chain runs over them: the orders are genuinely pre-#539 rows,
 * stage 1's backfill raises their invoices, and stage 2 then maps their statuses. That ordering is
 * the point — an order's new status is a statement about the invoices it now has.
 */
#[Group('migrations')]
final class OrderStatusReanchorTest extends TestCase
{
    /** The last migration before #539 stage 1, i.e. the world as it was before any of this. */
    private const BEFORE_539 = 'DoctrineMigrations\Version20260820120000';

    private const PRE_539 = 'DoctrineMigrations\Version20260810120000';

    private const VERSION_UNDER_TEST = 'DoctrineMigrations\Version20260820130000';

    /**
     * Orders as they existed before stage 2, with the payment status that decides between Invoiced
     * and Closed.
     *
     * The status column is a plain string on purpose (see SalesOrder::$status), which is why the
     * legacy values sit here beside the live ones: rows carrying them still exist, still hydrate,
     * and still have to end up somewhere sensible.
     *
     * @var list<array{0: string, 1: string, 2: ?string, 3: string}> [number, old status, payment status, expected]
     */
    private const SEED_ORDERS = [
        // Fully invoiced by construction — stage 1 gave each of these an invoice carrying every line
        // at full quantity — so the only question left is whether the money arrived.
        ['SO-1', 'Pending', 'Not Paid', 'Invoiced'],
        ['SO-2', 'On Hold', null, 'Invoiced'],
        ['SO-3', 'Processing', 'Not Paid', 'Invoiced'],
        ['SO-4', 'Completed', 'Paid', 'Closed'],
        ['SO-5', 'Pending', 'Paid', 'Closed'],
        // Case-insensitively paid: the column is free text and has never been normalised.
        ['SO-6', 'Completed', 'PAID', 'Closed'],

        // Never accepted, nothing owed.
        ['SO-7', 'Draft', null, 'Draft'],

        // The soft delete. Cancelled is what it was called before the two documents were separated.
        ['SO-8', 'Cancelled', 'Not Paid', 'Void'],
        ['SO-9', 'Canceled', 'Not Paid', 'Void'],

        // Pre-enum strays and quote-era strings. Draft is the one status that asserts nothing about
        // goods or money — and note 'Approved' deliberately does NOT become the new Approved case:
        // it predates any enum, means something nobody can now reconstruct, and stage 1 already gave
        // it a Draft invoice, which Approved would contradict.
        ['SO-10', 'Approved', null, 'Draft'],
        ['SO-11', 'APPROVED', null, 'Draft'],
        ['SO-12', 'Waiting for Quote', null, 'Draft'],
        ['SO-13', 'Accepted Quotes', null, 'Draft'],
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

    public function testEveryOrderLandsOnAStatusTheEnumKnows(): void
    {
        $statuses = $this->migrated()
            ->query('SELECT DISTINCT status FROM sales_order ORDER BY status')
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($statuses as $status) {
            self::assertContains(
                $status,
                ['Draft', 'Approved', 'Partially Invoiced', 'Invoiced', 'Closed', 'Void'],
                sprintf('%s is not a SalesOrderStatus case; the CASE has a hole in it.', $status),
            );
        }
    }

    public function testEachOldStatusMapsWhereItShould(): void
    {
        $rows = $this->migrated()
            ->query('SELECT order_number, status FROM sales_order ORDER BY id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        $expected = [];
        foreach (self::SEED_ORDERS as [$number, , , $target]) {
            $expected[$number] = $target;
        }

        self::assertSame($expected, $rows);
    }

    /**
     * No existing row can be either, and that is a fact about the data rather than a gap in the
     * mapping: stage 1 gave every order exactly one invoice carrying every line at full quantity, so
     * every one of them is fully invoiced. The deriver produces both the moment someone raises a
     * second invoice or cancels the only one.
     */
    public function testNoRowBecomesApprovedOrPartiallyInvoiced(): void
    {
        $counts = $this->migrated()
            ->query("SELECT COUNT(*) FROM sales_order WHERE status IN ('Approved', 'Partially Invoiced')")
            ->fetchColumn();

        self::assertSame(0, (int) $counts);
    }

    /** The invoices stage 1 raised are not touched: their statuses were always theirs. */
    public function testTheInvoicesAreLeftExactlyAsStageOneWroteThem(): void
    {
        $rows = $this->migrated()
            ->query('SELECT o.order_number, i.status FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id ORDER BY o.id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'SO-1' => 'Pending',
            'SO-2' => 'On Hold',
            'SO-3' => 'Processing',
            'SO-4' => 'Completed',
            'SO-5' => 'Pending',
            'SO-6' => 'Completed',
            'SO-7' => 'Draft',
            'SO-8' => 'Cancelled',
            // 'Canceled' is the US spelling and was never an enum case, so stage 1 read it as
            // unrecognised. Stage 2 still voids the ORDER, because the order column is free text and
            // the spelling is a data reality rather than a decision.
            'SO-9' => 'Draft',
            'SO-10' => 'Draft',
            'SO-11' => 'Draft',
            'SO-12' => 'Draft',
            'SO-13' => 'Draft',
        ], $rows);
    }

    /** The column stays a plain string, which is what lets a value the enum forgot still load. */
    public function testTheColumnIsStillFreeText(): void
    {
        $sql = (string) $this->migrated()
            ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'sales_order'")
            ->fetchColumn();

        self::assertMatchesRegularExpression('/status VARCHAR\(32\)/i', $sql);
        self::assertStringNotContainsString('CHECK', $sql, 'a check constraint would stop a legacy row loading at all');
    }

    /**
     * Replays the chain to the migration under test and returns a connection to the result.
     *
     * Cached: a replay is a couple of seconds and running one per assertion would be most of a
     * minute for no extra coverage.
     */
    private function migrated(): \PDO
    {
        if (self::$replay instanceof \PDO) {
            return self::$replay;
        }

        $projectDir = \dirname(__DIR__, 2);
        $databaseFile = sys_get_temp_dir() . '/order-status-reanchor-' . getmypid() . '.sqlite';
        @unlink($databaseFile);
        self::$temporaryFiles[] = $databaseFile;

        $this->migrate($projectDir, $databaseFile, self::PRE_539);

        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->seed($pdo);
        // Closed so the migrations below get the file to themselves.
        $pdo = null;

        // Stage 1 first, so the invoices these orders' new statuses describe actually exist.
        $this->migrate($projectDir, $databaseFile, self::BEFORE_539);
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

        foreach (self::SEED_ORDERS as $index => [$number, $status, $paymentStatus]) {
            $id = $index + 1;
            $order->execute([$id, $number, $status, $paymentStatus, '2026-05-09', '50.00', '0.00', '50.00', 'Admin', '2026-05-09 09:00:00']);
            $line->execute([$id, 'Widget', 'WIDGET-1', '10.00', '2.00', '5.00', '50.00', 0]);
        }
    }
}
