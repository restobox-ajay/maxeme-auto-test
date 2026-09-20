<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260820140000 adds the Sales Hold bucket and rebuilds both reservation ledgers
 * (#539 stage 3).
 *
 * Replayed against a real database rather than read as source, for the reason InvoiceBackfillTest
 * and OrderStatusReanchorTest both state: the behaviour is a page of SQL — a correlated remainder,
 * a case-insensitive region join and three aggregate re-sums — and only the data the chain actually
 * produces is authoritative. Neither suite would otherwise see this migration at all; both build
 * their schema from entity metadata via SchemaTool and never run a migration.
 *
 * Seeded before stage 1 so the whole chain runs over the rows: they are genuinely pre-#539 orders,
 * stage 1 raises their invoices, stage 2 maps their statuses, and only then does stage 3 decide what
 * each side holds. That ordering is the point — which bucket an invoice holds is a fact about the
 * invoice's status, which is why the rows cannot simply be copied across.
 */
#[Group('migrations')]
final class InventoryReanchorTest extends TestCase
{
    private const PRE_539 = 'DoctrineMigrations\Version20260810120000';

    private const STAGE_1 = 'DoctrineMigrations\Version20260820120000';

    private const VERSION_UNDER_TEST = 'DoctrineMigrations\Version20260820140000';

    /**
     * One order per shape the re-anchoring has to get right. Every order has a single line of 10
     * units of WIDGET-1 in West, so the only variable is the status — and therefore the bucket.
     *
     * The fourth column is what the old order ledger said, which is what stage 3 is re-anchoring:
     * [bucket, quantity, synced_quantity], or null for an order that held nothing.
     *
     * @var list<array{0: string, 1: string, 2: ?string, 3: ?array{0: string, 1: int, 2: int}}>
     */
    private const SEED_ORDERS = [
        // Issued and awaiting fulfilment: the invoice holds pending, the order holds nothing (it has
        // billed all of it).
        ['SO-PENDING', 'Pending', 'Not Paid', ['pending', 10, 0]],
        // Fulfilled and paid: the invoice holds approved, and the recount baseline of 4 travels with
        // it, so only 6 of the 10 reach the cache.
        ['SO-COMPLETED', 'Completed', 'Paid', ['approved', 10, 4]],
        // The abandoned checkout. Its invoice is On Hold, which holds nothing — and the order holds
        // nothing either, because On Hold still counts as invoiced. This is the row a naive
        // row-by-row copy would get wrong: it held 10 in pending before, and must now hold none.
        ['SO-ONHOLD', 'On Hold', null, ['pending', 10, 0]],
        // Never accepted. Draft on both documents, nothing held, nothing to re-anchor.
        ['SO-DRAFT', 'Draft', null, null],
        // The soft delete: Void order, Cancelled invoice, everything released.
        ['SO-CANCELLED', 'Cancelled', 'Not Paid', ['pending', 10, 0]],
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

    /**
     * The invoice ledger, rebuilt from each invoice's own status rather than copied from the order's.
     *
     * SO-ONHOLD is the case that makes the difference visible: it held 10 in `pending` under the old
     * mapping and holds nothing at all now, because an invoice awaiting an up-front payment reserves
     * no stock. Copying its row across would have carried that error in permanently.
     */
    public function testTheInvoiceLedgerHoldsWhatEachInvoicesStatusSays(): void
    {
        $rows = $this->migrated()
            ->query(
                'SELECT o.order_number, r.bucket || \':\' || r.quantity || \':\' || r.synced_quantity
                 FROM invoice_inventory_reservation r
                 JOIN invoice i ON i.id = r.invoice_id
                 JOIN sales_order o ON o.id = i.sales_order_id
                 ORDER BY o.id',
            )
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'SO-PENDING' => 'pending:10:0',
            // The baseline a product import stamped is the one thing a rebuild cannot recompute, so
            // it is carried across explicitly.
            'SO-COMPLETED' => 'approved:10:4',
            // Its invoice bills ten of the seventeen ordered; the rest is the order's, below.
            'SO-PARTIAL' => 'pending:10:0',
        ], $rows);
    }

    /**
     * The order ledger, rebuilt as sales hold on the uninvoiced remainder.
     *
     * Every order stage 1 touched was invoiced in full, so all of them hold nothing — the whole
     * ledger reduces to the one order carrying a line no invoice bills. A row per order, in whatever
     * bucket the order's old status implied, is exactly what this migration exists to stop.
     */
    public function testTheOrderLedgerHoldsOnlyWhatIsStillUninvoiced(): void
    {
        $rows = $this->migrated()
            ->query(
                "SELECT o.order_number, r.bucket || ':' || r.quantity
                 FROM order_inventory_reservation r
                 JOIN sales_order o ON o.id = r.order_id
                 ORDER BY o.id",
            )
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame(['SO-PARTIAL' => 'sales_hold:7'], $rows);
    }

    /** An order ledger row can only ever be sales hold now, whatever it said before. */
    public function testNoOrderReservationIsLeftInAnInvoiceBucket(): void
    {
        $buckets = $this->migrated()
            ->query("SELECT COUNT(*) FROM order_inventory_reservation WHERE bucket <> 'sales_hold'")
            ->fetchColumn();

        self::assertSame(0, (int) $buckets);
    }

    /**
     * The cached columns, summed back off the two ledgers.
     *
     * Pending is SO-PENDING's ten plus SO-PARTIAL's ten. Approved is SO-COMPLETED's ten minus its
     * baseline of four, which is what makes the import's approved-balance reset survive this
     * migration rather than being silently undone by it. Sales hold is SO-PARTIAL's uninvoiced
     * seven, and cart hold is untouched.
     */
    public function testTheCachedBucketsAreRecomputedFromTheLedgers(): void
    {
        $row = $this->migrated()
            ->query('SELECT sales_hold_quantity, pending_quantity, approved_quantity, cart_hold_quantity, quantity FROM product_inventory')
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertSame(7, (int) $row['sales_hold_quantity']);
        self::assertSame(20, (int) $row['pending_quantity']);
        self::assertSame(6, (int) $row['approved_quantity']);
        self::assertSame(3, (int) $row['cart_hold_quantity'], 'cart hold has never been an order hold and nothing here moved it');
        self::assertSame(100, (int) $row['quantity']);
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
        $databaseFile = sys_get_temp_dir() . '/inventory-reanchor-' . getmypid() . '.sqlite';
        @unlink($databaseFile);
        self::$temporaryFiles[] = $databaseFile;

        $this->migrate($projectDir, $databaseFile, self::PRE_539);

        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->seed($pdo);
        // Closed so the migrations below get the file to themselves.
        $pdo = null;

        // Stage 1 raises the invoices, stage 2 maps the order statuses, and the chain runs on to
        // stage 3 — the migration under test reads what both of those left behind.
        $this->migrate($projectDir, $databaseFile, self::STAGE_1);
        $this->addPartiallyInvoicedOrderLine($databaseFile);
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
        $pdo->exec("INSERT INTO fulfillment_region (id, name, status, guest_visible, created_at) VALUES (1, 'West', 'Active', 0, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO product_core (id, sku, name, is_private, status, visible, featured, deleted, created_at) VALUES (1, 'WIDGET-1', 'Widget', 0, 'Active', 1, 0, 0, '2026-01-01 00:00:00')");
        // Cart hold is stamped so the migration can be shown not to touch it.
        $pdo->exec("INSERT INTO product_inventory (id, quantity, reserved_quantity, cart_hold_quantity, pending_quantity, approved_quantity, updated_at, product_id, fulfillment_region_id) VALUES (1, 100, 0, 3, 30, 10, '2026-01-01 00:00:00', 1, 1)");

        $order = $pdo->prepare(
            'INSERT INTO sales_order
                (id, company_id, order_number, status, payment_status, version, document_date,
                 subtotal, tax, total, source, fulfillment_region, created_at)
             VALUES (?, 1, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)',
        );
        $line = $pdo->prepare(
            'INSERT INTO sales_order_line (order_id, product_id, name, sku, quantity, cost, price, subtotal, sort_order)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)',
        );
        $reservation = $pdo->prepare(
            'INSERT INTO order_inventory_reservation
                (order_id, product_id, fulfillment_region_id, bucket, quantity, synced_quantity, created_at, updated_at)
             VALUES (?, 1, 1, ?, ?, ?, ?, ?)',
        );

        foreach (self::SEED_ORDERS as $index => [$number, $status, $paymentStatus, $held]) {
            $id = $index + 1;
            $order->execute([$id, $number, $status, $paymentStatus, '2026-05-09', '50.00', '0.00', '50.00', 'Admin', 'West', '2026-05-09 09:00:00']);
            $line->execute([$id, 'Widget', 'WIDGET-1', '10.00', '2.00', '5.00', '50.00', 0]);

            if ($held !== null) {
                $reservation->execute([$id, $held[0], $held[1], $held[2], '2026-05-09 09:00:00', '2026-05-09 09:00:00']);
            }
        }

        // The partially-invoiced case: an order stage 1 will invoice in full, which then grows a
        // second line nobody has billed. Its remainder is what has to reach sales hold.
        $partialId = count(self::SEED_ORDERS) + 1;
        $order->execute([$partialId, 'SO-PARTIAL', 'Pending', 'Not Paid', '2026-05-09', '50.00', '0.00', '50.00', 'Admin', 'West', '2026-05-09 09:00:00']);
        $line->execute([$partialId, 'Widget', 'WIDGET-1', '10.00', '2.00', '5.00', '50.00', 0]);
    }

    /**
     * Adds the uninvoiced line AFTER stage 1's backfill has run, so no invoice bills it — the only
     * way to produce a partially invoiced order from a chain whose backfill is 1:1 by construction.
     */
    private function addPartiallyInvoicedOrderLine(string $databaseFile): void
    {
        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            "INSERT INTO sales_order_line (order_id, product_id, name, sku, quantity, cost, price, subtotal, sort_order)
             SELECT id, 1, 'Widget', 'WIDGET-1', '7.00', '2.00', '5.00', '35.00', 1
             FROM sales_order WHERE order_number = 'SO-PARTIAL'",
        );
    }
}
