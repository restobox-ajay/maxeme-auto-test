<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260820120000 backfills one invoice per existing sales order (#539 stage 1). Stage 1's
 * whole promise is that every order in every environment has exactly one, so what the backfill does
 * to rows that already exist has to be as pinned as what OrderInvoicingService does to new ones.
 *
 * Replayed against a real database rather than read as source, for the reason
 * RawSqlTablesSurviveTheChainTest states: the interesting behaviour is in SQL — a CASE mapping, two
 * joins and a window function numbering the rows — and only the data the chain actually produces is
 * authoritative. Neither test suite would otherwise see this migration at all: both
 * build their schema from entity metadata via SchemaTool and never run a migration.
 *
 * The seed rows are inserted with the chain paused at the migration BEFORE this one, so they are
 * genuinely pre-existing orders rather than rows written into a schema that already had invoices.
 */
#[Group('migrations')]
final class InvoiceBackfillTest extends TestCase
{
    /** The migration immediately before the one under test. */
    private const PREVIOUS_VERSION = 'DoctrineMigrations\Version20260810120000';

    private const VERSION_UNDER_TEST = 'DoctrineMigrations\Version20260820120000';

    /**
     * Orders seeded before the backfill runs, in the order they are inserted — so their ids are
     * 1..N and the invoice numbering below is checked against a known sequence.
     *
     * The status column is a plain string on purpose (see SalesOrder::$status), which is why the
     * legacy values are here beside the live ones: rows carrying them still exist and still have to
     * produce an invoice.
     *
     * @var list<array{0: string, 1: string}> [order number, stored status]
     */
    private const SEED_ORDERS = [
        ['SO-1', 'Pending'],
        ['SO-2', 'On Hold'],
        ['SO-3', 'Draft'],
        ['SO-4', 'Processing'],
        ['SO-5', 'Completed'],
        ['SO-6', 'Cancelled'],
        // Pre-enum rows. 'Approved' and 'APPROVED' predate the current SalesOrderStatus and the
        // quote-era strings predate quotes being their own document.
        ['SO-7', 'Approved'],
        ['SO-8', 'APPROVED'],
        ['SO-9', 'Waiting for Quote'],
        ['SO-10', 'Accepted Quotes'],
    ];

    /** @var list<string> */
    private static array $temporaryFiles = [];

    /** @var array<string, \PDO> */
    private static array $replays = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryFiles as $file) {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        self::$replays = [];
        self::$temporaryFiles = [];
    }

    public function testEveryOrderGetsExactlyOneInvoice(): void
    {
        $pdo = $this->backfilled();

        self::assertSame(
            \count(self::SEED_ORDERS),
            (int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM sales_order o WHERE NOT EXISTS (SELECT 1 FROM invoice i WHERE i.sales_order_id = o.id)')->fetchColumn(),
            'an order with no invoice breaks the invariant every later stage is allowed to assume',
        );
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM (SELECT sales_order_id FROM invoice GROUP BY sales_order_id HAVING COUNT(*) > 1)')->fetchColumn(),
            'and so does an order with two',
        );
    }

    /**
     * The order's fulfilment status becomes the invoice's one for one — these cases were always
     * really the invoice's. Anything the enum no longer knows becomes Draft, which is the only case
     * that asserts nothing about goods or money. Same mapping OrderInvoicingService applies to new
     * orders, so an order raised a minute after this migration matches one raised a minute before it.
     */
    public function testStatusesMapOneForOneAndLegacyStringsBecomeDraft(): void
    {
        $rows = $this->backfilled()
            ->query('SELECT o.order_number, i.status FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id ORDER BY o.id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'SO-1' => 'Pending',
            'SO-2' => 'On Hold',
            'SO-3' => 'Draft',
            'SO-4' => 'Processing',
            'SO-5' => 'Completed',
            'SO-6' => 'Cancelled',
            'SO-7' => 'Draft',
            'SO-8' => 'Draft',
            'SO-9' => 'Draft',
            'SO-10' => 'Draft',
        ], $rows);
    }

    /** Numbered from 1 in order id order, with no holes for an auditor to ask about. */
    public function testInvoicesAreNumberedSequentiallyInOrderIdOrder(): void
    {
        $rows = $this->backfilled()
            ->query('SELECT o.order_number, i.document_number FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id ORDER BY i.id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        $expected = [];
        foreach (self::SEED_ORDERS as $index => [$orderNumber]) {
            $expected[$orderNumber] = 'INV-' . ($index + 1);
        }

        self::assertSame($expected, $rows);
    }

    /**
     * Honours an invoice_number_prefix an admin had already set, matching InvoiceNumberGenerator and
     * the Document Prefixes screen. No counter row is written: DocumentNumberAllocator seeds a
     * (kind, prefix) counter from MAX(...) on first use, so it picks up from whatever this left.
     */
    public function testAConfiguredPrefixIsHonoured(): void
    {
        $numbers = $this->backfilled('BILL-')
            ->query('SELECT document_number FROM invoice ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['BILL-1', 'BILL-2'], \array_slice($numbers, 0, 2));
        self::assertSame(
            0,
            (int) $this->backfilled('BILL-')->query("SELECT COUNT(*) FROM document_number_counter WHERE kind = 'invoice'")->fetchColumn(),
            'the allocator seeds itself from the numbers already in the table, so a counter row here would only be a second answer',
        );
    }

    public function testTheInvoiceCarriesTheOrdersHeaderAndDates(): void
    {
        $pdo = $this->backfilled();
        $invoice = $pdo->query("SELECT i.* FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id WHERE o.order_number = 'SO-1'")
            ->fetch(\PDO::FETCH_ASSOC);
        $order = $pdo->query("SELECT * FROM sales_order WHERE order_number = 'SO-1'")->fetch(\PDO::FETCH_ASSOC);

        // Asserted against the ORDER's own row rather than against literals: the copy is only right
        // if it says what the order says, and the money columns come back through SQLite's NUMERIC
        // affinity, so a literal here would be asserting PDO's coercion rather than the migration.
        foreach ([
            'po_number', 'user_name', 'source', 'document_date', 'subtotal', 'tax', 'total',
            'special_instructions', 'fee_lines', 'coupon_codes', 'tax_lines', 'company_snapshot',
            'shipping_method', 'fulfillment_region', 'created_at',
            // Payment is copied but stays authoritative on the order until stage 4 — the point of
            // copying it now is that the two agree from the outset.
            'payment_status', 'payment_method', 'payment_term',
        ] as $column) {
            self::assertSame($order[$column], $invoice[$column], sprintf('%s was not carried across', $column));
        }

        self::assertSame(1, (int) $invoice['version'], 'a backfilled invoice starts at version 1 like any other row');
        self::assertNull($invoice['due_date'], 'nothing in stage 1 knows when payment falls due');
    }

    /** invoice_date falls back to the order's document date, so no invoice lands undated. */
    public function testTheInvoiceDateFallsBackToTheOrdersDocumentDate(): void
    {
        $dates = $this->backfilled()
            ->query('SELECT o.order_number, i.invoice_date FROM invoice i JOIN sales_order o ON o.id = i.sales_order_id WHERE o.order_number IN (\'SO-1\', \'SO-2\')')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame('2026-02-02', $dates['SO-1'], 'SO-1 has its own invoice date typed on it');
        self::assertSame('2026-01-05', $dates['SO-2'], 'SO-2 has none, so it takes the order date');
        self::assertSame(
            0,
            (int) $this->backfilled()->query('SELECT COUNT(*) FROM invoice WHERE invoice_date IS NULL')->fetchColumn(),
        );
    }

    public function testEveryOrderLineIsCopiedAndAttributedToItsOrderRow(): void
    {
        $pdo = $this->backfilled();

        self::assertSame(
            (int) $pdo->query('SELECT COUNT(*) FROM sales_order_line')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM invoice_line')->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM invoice_line WHERE sales_order_line_id IS NULL')->fetchColumn(),
            'every copied row names the order row it bills',
        );

        $line = $pdo->query(
            "SELECT il.* FROM invoice_line il
             JOIN invoice i ON i.id = il.invoice_id
             JOIN sales_order o ON o.id = i.sales_order_id
             WHERE o.order_number = 'SO-1' AND il.sku = 'WIDGET-1'",
        )->fetch(\PDO::FETCH_ASSOC);
        $orderLine = $pdo->query("SELECT * FROM sales_order_line WHERE id = " . (int) $line['sales_order_line_id'])
            ->fetch(\PDO::FETCH_ASSOC);

        foreach ([
            'name', 'location', 'sku', 'quantity', 'weight', 'unit', 'tax_code',
            'cost', 'price', 'subtotal', 'batch', 'sort_order', 'product_id',
        ] as $column) {
            self::assertSame($orderLine[$column], $line[$column], sprintf('%s was not carried across', $column));
        }

        self::assertSame('Widget', $line['name'], 'guard: the row this compared is the one the seed wrote');
    }

    public function testBothOfAnOrdersAddressesAreCopied(): void
    {
        $pdo = $this->backfilled();

        self::assertSame(
            (int) $pdo->query('SELECT COUNT(*) FROM sales_order_address')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM invoice_address')->fetchColumn(),
        );

        $addresses = $pdo->query(
            "SELECT ia.type, ia.address_line1 FROM invoice_address ia
             JOIN invoice i ON i.id = ia.invoice_id
             JOIN sales_order o ON o.id = i.sales_order_id
             WHERE o.order_number = 'SO-1' ORDER BY ia.type",
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame(['billing' => '1 Bill St', 'shipping' => '2 Ship Rd'], $addresses);
    }

    /**
     * The order's own history is not duplicated onto a document that did not exist when it was
     * written — that would put events on a timeline they never happened on. The invoice's timeline
     * starts empty and is written from stage 2 onward by the actions that change it.
     */
    public function testOrderLogsAreNotCopiedOntoTheInvoice(): void
    {
        $pdo = $this->backfilled();

        self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM sales_order_log')->fetchColumn(), 'guard: the seed wrote an order log');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoice_log')->fetchColumn());
    }

    /** Nothing moves OFF the order in stage 1 — its status in particular is stage 2's job. */
    public function testTheOrdersOwnRowsAreLeftAlone(): void
    {
        $statuses = $this->backfilled()
            ->query('SELECT order_number, status FROM sales_order ORDER BY id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        $expected = [];
        foreach (self::SEED_ORDERS as [$orderNumber, $status]) {
            $expected[$orderNumber] = $status;
        }

        self::assertSame($expected, $statuses);
    }

    /**
     * Replays the chain up to the previous migration, seeds orders, then runs the migration under
     * test — and returns a connection to the result.
     *
     * Cached per prefix: a replay is a couple of seconds, and running one per assertion would be
     * most of a minute for no extra coverage.
     */
    private function backfilled(?string $configuredPrefix = null): \PDO
    {
        $key = $configuredPrefix ?? '';
        if (isset(self::$replays[$key])) {
            return self::$replays[$key];
        }

        $projectDir = \dirname(__DIR__, 2);
        $databaseFile = sys_get_temp_dir() . '/invoice-backfill-' . getmypid() . '-' . md5($key) . '.sqlite';
        @unlink($databaseFile);
        self::$temporaryFiles[] = $databaseFile;

        $this->migrate($projectDir, $databaseFile, self::PREVIOUS_VERSION);

        $pdo = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->seed($pdo, $configuredPrefix);
        // Closed so the migration below gets the file to itself; SQLite is happy with concurrent
        // readers but this keeps a stray WAL handle out of the picture entirely.
        $pdo = null;

        $this->migrate($projectDir, $databaseFile, self::VERSION_UNDER_TEST);

        return self::$replays[$key] = new \PDO('sqlite:' . $databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
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

    /** Orders as they existed before #539: one company, ten orders, lines, addresses and a log. */
    private function seed(\PDO $pdo, ?string $configuredPrefix): void
    {
        $pdo->exec("INSERT INTO company (id, name, code, status, account_type, created_at, api_enabled) VALUES (1, 'Acme Co', 'ACME', 'Active', 'Wholesale', '2026-01-01 00:00:00', 0)");

        if ($configuredPrefix !== null) {
            $insert = $pdo->prepare("INSERT INTO app_setting (setting_key, name, setting_value, created_at, visibility) VALUES ('invoice_number_prefix', 'Invoice Number Prefix', ?, '2026-01-01 00:00:00', 'public')");
            $insert->execute([$configuredPrefix]);
        }

        $order = $pdo->prepare(
            'INSERT INTO sales_order
                (id, company_id, order_number, status, invoice_date, payment_status, payment_method, payment_term,
                 version, po_number, user_name, document_date, subtotal, tax, total, source, special_instructions,
                 fee_lines, coupon_codes, tax_lines, company_snapshot, shipping_method, fulfillment_region, created_at)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );

        foreach (self::SEED_ORDERS as $index => [$orderNumber, $status]) {
            $id = $index + 1;
            $order->execute([
                $id,
                $orderNumber,
                $status,
                // Only the first order carries its own invoice date; the rest fall back to the
                // document date, which is the case the backfill's COALESCE exists for.
                $id === 1 ? '2026-02-02' : null,
                'Not Paid',
                'Credit Card',
                'Net 30',
                'PO-' . $id,
                'Jane Buyer',
                '2026-01-05',
                '100.00',
                '5.00',
                '115.00',
                'Customer',
                'Leave at dock',
                '[{"slug":"shipping","label":"Shipping (Ground)","amount":10.0,"taxClass":"G","placement":"main_line","type":"shipping","source":"auto-calc"}]',
                '["SAVE10"]',
                '{"lines":[]}',
                '{"name":"Acme Co"}',
                'Ground',
                'West',
                '2026-01-05 09:00:00',
            ]);
        }

        $line = $pdo->prepare(
            'INSERT INTO sales_order_line (order_id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, batch, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach (self::SEED_ORDERS as $index => [$orderNumber]) {
            $id = $index + 1;
            $line->execute([$id, 'Widget', 'Aisle 1', 'WIDGET-1', '2.00', '3.5', 'lb', 'TAX1', '40.00', '50.00', '100.00', 'LOT-9', 1]);
            // A second row on the first order, so "one invoice line per order line" is not trivially
            // true by every order having exactly one.
            if ($id === 1) {
                $line->execute([$id, 'Gadget', null, 'GADGET-2', '1.00', null, null, null, '10.00', '15.00', '15.00', null, 0]);
            }
        }

        $address = $pdo->prepare(
            'INSERT INTO sales_order_address (order_id, type, first_name, last_name, company_name, address_line1, city, province, country, postal_code, delivery_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $address->execute([1, 'billing', 'Jane', 'Buyer', 'Acme Co', '1 Bill St', 'Vancouver', 'BC', 'CA', 'V5K0A1', null]);
        $address->execute([1, 'shipping', 'Sam', 'Receiver', 'Acme Depot', '2 Ship Rd', 'Burnaby', 'BC', 'CA', 'V5H1Z9', 'Ring the bell']);

        $pdo->exec("INSERT INTO sales_order_log (order_id, user_name, comment, type, customer_notified, created_at) VALUES (1, 'Jane Buyer', 'Order SO-1 created by Jane Buyer.', 'System', 0, '2026-01-05 09:00:00')");
    }
}
