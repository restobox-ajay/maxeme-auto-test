<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 3 of #539: the Sales Hold bucket, and the inventory holds re-anchored onto the document that
 * actually owns each of them.
 *
 * Before this, a sales order held its whole quantity — in `pending` while the goods were owed and in
 * `approved` once they had gone. Stage 2 rewrote the order's statuses out from under that and left
 * the resolver deliberately re-pointed as a stopgap, so availability stayed correct while the
 * attribution did not. This settles it:
 *
 * - `product_inventory.sales_hold_quantity` is a fourth hold bucket, and Available becomes
 *   quantity − cart_hold − sales_hold − pending − approved.
 * - `invoice_inventory_reservation` is the invoice's ledger, holding `pending`/`approved`.
 * - `order_inventory_reservation` keeps the order's ledger and holds `sales_hold` alone: the
 *   (ordered − invoiced) remainder of every order Approved or later.
 *
 * ## Why this writes data at all
 *
 * Confirmed with the client before stage 1 and re-confirmed at each stage since: no production
 * instance holds any order data — every instance is a dev one. That is what makes an outright
 * re-anchoring cheap enough to do rather than carrying a compatibility layer, and it has a shelf
 * life. If real order data exists anywhere by the time this runs, the rebuild below needs
 * re-confirming first.
 *
 * ## Why the ledgers are rebuilt rather than moved row by row
 *
 * The obvious migration is "copy every `pending`/`approved` row across to the invoice of its order".
 * It is also wrong. Those rows were written against the ORDER's status, and the bucket an invoice
 * holds is a function of the INVOICE's status — an order that was `pending` may well have an
 * `On Hold` invoice, which must hold nothing at all. Copying the bucket across would carry that
 * error into the new table and leave it there permanently, since nothing recomputes a ledger row
 * that already looks plausible.
 *
 * So both ledgers are recomputed from source of truth — the same two rules
 * InventoryReservationReconciler applies from here on, expressed in SQL — and the cached bucket
 * columns are then summed back off them. What the re-anchoring preserves is the one thing that
 * cannot be recomputed: the `synced_quantity` baselines a product-import recount stamped, which are
 * carried onto the matching invoice rows so an import's approved-balance reset is not silently
 * undone by this migration.
 *
 * Every quantity is rounded to whole units, exactly as the reconciler does — buckets count units.
 */
final class Version20260820140000 extends AbstractMigration
{
    /** Counting invoices: neither drafts nor cancellations (SalesOrder::getCountingInvoices()). */
    private const NON_COUNTING_INVOICE_STATUSES = "('Draft', 'Cancelled')";

    /** Orders Approved or later — the ones that hold sales hold (SalesOrderStatus::isApprovedOrLater()). */
    private const SALES_HOLD_ORDER_STATUSES = "('approved', 'partially invoiced', 'invoiced', 'closed')";

    public function getDescription(): string
    {
        return '#539 stage 3: sales_hold_quantity, the invoice reservation ledger, and both ledgers rebuilt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN sales_hold_quantity INTEGER DEFAULT 0 NOT NULL');

        // Mirrors order_inventory_reservation column for column — the two ledgers share a shape, and
        // that shared shape is what lets one reconciler write both (see the InventoryReservation
        // interface). CASCADE on all three FKs, as on the order ledger: a ledger row is a cache of a
        // relationship, never a record worth keeping once one end of it is gone.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_inventory_reservation (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                fulfillment_region_id INTEGER NOT NULL,
                bucket VARCHAR(20) NOT NULL,
                quantity INTEGER NOT NULL,
                synced_quantity INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_invoice_reservation_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_reservation_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_reservation_region FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_invoice_reservation_invoice ON invoice_inventory_reservation (invoice_id)');
        $this->addSql('CREATE INDEX idx_invoice_reservation_product ON invoice_inventory_reservation (product_id)');
        $this->addSql('CREATE INDEX idx_invoice_reservation_region ON invoice_inventory_reservation (fulfillment_region_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_region ON invoice_inventory_reservation (invoice_id, product_id, fulfillment_region_id)');

        $this->rebuildInvoiceLedger();
        $this->carryApprovedBaselinesOntoInvoices();
        $this->rebuildOrderLedger();
        $this->recomputeCachedBuckets();
    }

    /**
     * Every invoice that holds stock, one row per (invoice, product, region).
     *
     * `Pending` holds `pending`; `Processing` and `Completed` hold `approved`. `Draft`, `On Hold` and
     * `Cancelled` hold nothing and are simply absent — an abandoned card checkout's invoice must not
     * reserve stock, and it does not appear here at all rather than appearing with a zero.
     */
    private function rebuildInvoiceLedger(): void
    {
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO invoice_inventory_reservation
                (invoice_id, product_id, fulfillment_region_id, bucket, quantity, synced_quantity, created_at, updated_at)
            SELECT il.invoice_id,
                   il.product_id,
                   fr.id,
                   CASE WHEN i.status = 'Pending' THEN 'pending' ELSE 'approved' END,
                   CAST(ROUND(SUM(il.quantity)) AS INTEGER),
                   0,
                   %1$s,
                   %1$s
            FROM invoice_line il
            JOIN invoice i ON i.id = il.invoice_id
            JOIN fulfillment_region fr
              ON LOWER(TRIM(fr.name)) = LOWER(%2$s)
            WHERE i.status IN ('Pending', 'Processing', 'Completed')
              -- A reservation must name a product that still exists: both ledgers carry
              -- ON DELETE CASCADE on product_id, so a row for a deleted product is not a state
              -- either table can hold. Order and invoice LINES may legitimately point at a
              -- deleted product — they are a record of what was sold, and product imports
              -- delete rows routinely — so the lines are kept and only the reservation is
              -- skipped. There is no stock to reserve for a product the catalogue no longer has.
              AND il.product_id IS NOT NULL
              AND il.product_id IN (SELECT id FROM product_core)
              AND %2$s <> ''
            GROUP BY il.invoice_id, il.product_id, fr.id
            HAVING CAST(ROUND(SUM(il.quantity)) AS INTEGER) > 0
        SQL, $this->now(), $this->resolvedRegionName('il.location', 'i.fulfillment_region')));
    }

    /**
     * The one thing a rebuild cannot recompute: the recount baselines.
     *
     * A product import's "clear all Approved balance" stamps `synced_quantity = quantity` on every
     * outstanding approved reservation, so that only the amount BEYOND the recount re-enters Approved
     * when the document is next edited. Those stamps lived on the order ledger, because Approved was
     * the order's bucket; they move to the invoice rows covering the same product and region on that
     * order's invoices. Dropping them instead would make every recount silently reappear the next
     * time anything touched the document.
     */
    private function carryApprovedBaselinesOntoInvoices(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE invoice_inventory_reservation
            SET synced_quantity = COALESCE((
                    SELECT MAX(r.synced_quantity)
                    FROM order_inventory_reservation r
                    JOIN invoice i ON i.id = invoice_inventory_reservation.invoice_id
                    WHERE r.order_id = i.sales_order_id
                      AND r.product_id = invoice_inventory_reservation.product_id
                      AND r.fulfillment_region_id = invoice_inventory_reservation.fulfillment_region_id
                      AND r.bucket = 'approved'
                ), 0)
            WHERE bucket = 'approved'
        SQL);
    }

    /**
     * The order ledger, rebuilt as sales hold on the uninvoiced remainder.
     *
     * Emptied first rather than updated in place: every existing row is in a bucket the order may no
     * longer hold, and on a quantity that was the ordered one rather than the remainder, so there is
     * nothing in one worth keeping. A line whose remainder is zero — the whole of an ecom order,
     * invoiced in full from the instant it existed — produces no row at all, which is the same thing
     * the reconciler does with it.
     */
    private function rebuildOrderLedger(): void
    {
        $this->addSql('DELETE FROM order_inventory_reservation');

        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO order_inventory_reservation
                (order_id, product_id, fulfillment_region_id, bucket, quantity, synced_quantity, created_at, updated_at)
            SELECT sol.order_id,
                   sol.product_id,
                   fr.id,
                   'sales_hold',
                   CAST(ROUND(SUM(
                       MAX(0, sol.quantity - COALESCE((
                           SELECT SUM(il.quantity)
                           FROM invoice_line il
                           JOIN invoice i ON i.id = il.invoice_id
                           WHERE il.sales_order_line_id = sol.id
                             AND i.status NOT IN %3$s
                       ), 0))
                   )) AS INTEGER),
                   0,
                   %1$s,
                   %1$s
            FROM sales_order_line sol
            JOIN sales_order so ON so.id = sol.order_id
            JOIN fulfillment_region fr
              ON LOWER(TRIM(fr.name)) = LOWER(%2$s)
            WHERE LOWER(TRIM(so.status)) IN %4$s
              AND sol.product_id IS NOT NULL
              AND sol.product_id IN (SELECT id FROM product_core)
              AND %2$s <> ''
            GROUP BY sol.order_id, sol.product_id, fr.id
            HAVING CAST(ROUND(SUM(
                       MAX(0, sol.quantity - COALESCE((
                           SELECT SUM(il.quantity)
                           FROM invoice_line il
                           JOIN invoice i ON i.id = il.invoice_id
                           WHERE il.sales_order_line_id = sol.id
                             AND i.status NOT IN %3$s
                       ), 0))
                   )) AS INTEGER) > 0
        SQL, $this->now(), $this->resolvedRegionName('sol.location', 'so.fulfillment_region'), self::NON_COUNTING_INVOICE_STATUSES, self::SALES_HOLD_ORDER_STATUSES));
    }

    /**
     * The cached bucket columns, summed straight back off the two ledgers just written.
     *
     * Approved sums the EFFECTIVE quantity — max(0, quantity − synced_quantity) — because that is
     * what reaches the cache everywhere else; the raw quantity would undo every recount baseline the
     * step above just took care to preserve. Cart hold is untouched: it has never been an
     * order-or-invoice hold and nothing here moved it.
     *
     * A product_inventory row with no region holds nothing, and correctly falls to zero on all three:
     * a reservation is always for a specific region.
     */
    private function recomputeCachedBuckets(): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE product_inventory SET
                sales_hold_quantity = COALESCE((
                    SELECT SUM(r.quantity) FROM order_inventory_reservation r
                    WHERE r.product_id = product_inventory.product_id
                      AND r.fulfillment_region_id = product_inventory.fulfillment_region_id
                      AND r.bucket = 'sales_hold'
                ), 0),
                pending_quantity = COALESCE((
                    SELECT SUM(r.quantity) FROM invoice_inventory_reservation r
                    WHERE r.product_id = product_inventory.product_id
                      AND r.fulfillment_region_id = product_inventory.fulfillment_region_id
                      AND r.bucket = 'pending'
                ), 0),
                approved_quantity = COALESCE((
                    SELECT SUM(MAX(0, r.quantity - r.synced_quantity)) FROM invoice_inventory_reservation r
                    WHERE r.product_id = product_inventory.product_id
                      AND r.fulfillment_region_id = product_inventory.fulfillment_region_id
                      AND r.bucket = 'approved'
                ), 0),
                updated_at = %1$s
        SQL, $this->now()));
    }

    /**
     * A line's own location wins if it has one, otherwise the document header's region — the SQL
     * equivalent of OrderInventoryBucketResolver::resolveLineRegion(), which every PHP path uses.
     */
    private function resolvedRegionName(string $lineColumn, string $documentColumn): string
    {
        return sprintf(
            "COALESCE(NULLIF(TRIM(COALESCE(%s, '')), ''), TRIM(COALESCE(%s, '')))",
            $lineColumn,
            $documentColumn,
        );
    }

    private function now(): string
    {
        return $this->connection->quote((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    /**
     * Lossy, and unavoidably so.
     *
     * Down puts the order ledger back the way stage 2 left it — the order's whole quantity, in
     * `pending` while it is owed and in `approved` once it is closed — which is what the code being
     * rolled back to expects to find. It cannot restore the rows this migration deleted, because
     * those rows described the same holds under a mapping that no longer exists; what it restores is
     * a ledger consistent with the stage 2 resolver, which is the thing that matters for the app to
     * keep working.
     *
     * The recount baselines travel back with it, by the same product-and-region match that carried
     * them forward.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM order_inventory_reservation');

        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO order_inventory_reservation
                (order_id, product_id, fulfillment_region_id, bucket, quantity, synced_quantity, created_at, updated_at)
            SELECT sol.order_id,
                   sol.product_id,
                   fr.id,
                   CASE WHEN LOWER(TRIM(so.status)) = 'closed' THEN 'approved' ELSE 'pending' END,
                   CAST(ROUND(SUM(sol.quantity)) AS INTEGER),
                   0,
                   %1$s,
                   %1$s
            FROM sales_order_line sol
            JOIN sales_order so ON so.id = sol.order_id
            JOIN fulfillment_region fr
              ON LOWER(TRIM(fr.name)) = LOWER(%2$s)
            WHERE LOWER(TRIM(so.status)) IN %3$s
              AND sol.product_id IS NOT NULL
              AND sol.product_id IN (SELECT id FROM product_core)
              AND %2$s <> ''
            GROUP BY sol.order_id, sol.product_id, fr.id
            HAVING CAST(ROUND(SUM(sol.quantity)) AS INTEGER) > 0
        SQL, $this->now(), $this->resolvedRegionName('sol.location', 'so.fulfillment_region'), self::SALES_HOLD_ORDER_STATUSES));

        $this->addSql(<<<'SQL'
            UPDATE order_inventory_reservation
            SET synced_quantity = COALESCE((
                    SELECT MAX(r.synced_quantity)
                    FROM invoice_inventory_reservation r
                    JOIN invoice i ON i.id = r.invoice_id
                    WHERE i.sales_order_id = order_inventory_reservation.order_id
                      AND r.product_id = order_inventory_reservation.product_id
                      AND r.fulfillment_region_id = order_inventory_reservation.fulfillment_region_id
                      AND r.bucket = 'approved'
                ), 0)
            WHERE bucket = 'approved'
        SQL);

        $this->addSql(sprintf(<<<'SQL'
            UPDATE product_inventory SET
                pending_quantity = COALESCE((
                    SELECT SUM(r.quantity) FROM order_inventory_reservation r
                    WHERE r.product_id = product_inventory.product_id
                      AND r.fulfillment_region_id = product_inventory.fulfillment_region_id
                      AND r.bucket = 'pending'
                ), 0),
                approved_quantity = COALESCE((
                    SELECT SUM(MAX(0, r.quantity - r.synced_quantity)) FROM order_inventory_reservation r
                    WHERE r.product_id = product_inventory.product_id
                      AND r.fulfillment_region_id = product_inventory.fulfillment_region_id
                      AND r.bucket = 'approved'
                ), 0),
                updated_at = %1$s
        SQL, $this->now()));

        $this->addSql('DROP TABLE invoice_inventory_reservation');
        $this->addSql('ALTER TABLE product_inventory DROP COLUMN sales_hold_quantity');
    }
}
