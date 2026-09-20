<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot/serial as a reservation-scoped dimension, not a new mechanism (2026-09-14 lot/serial/expiry
 * plan). Four nullable columns, and the unique keys that make the reservation ledger widen safely.
 *
 * ## The columns
 *
 *   sales_order_line.lot_id / .serial      what the admin picked for this line, at fulfilment
 *   invoice_line.lot_id / .serial          what this line actually bills, at invoice time
 *   order_inventory_reservation.lot_id / .serial     the sales-hold/backordered hold, narrowed
 *   invoice_inventory_reservation.lot_id / .serial   the pending/approved hold, narrowed
 *
 * Every one of them is null on every existing row, and stays null for every product nobody has
 * opted into lot/serial tracking (TrackingPolicy::tracksLotsOutbound()/tracksSerialsOutbound()) —
 * which is what keeps this a widening rather than a behavior change. No backfill: there is nothing
 * to backfill, because no row before this migration ever named a lot or serial to begin with.
 *
 * ## Plain integers, real foreign keys
 *
 * `sales_order_line`/`invoice_line`/`order_inventory_reservation`/`invoice_inventory_reservation`
 * are core (`src/Entity`); `inventory_lot` belongs to modules/InventoryDepthBundle, which is
 * deletable. So `lot_id` is a plain nullable integer in every one of those four entities' mapping —
 * never a Doctrine association to InventoryLot — the same seam
 * App\Entity\InventoryBucketChangeLog::$groupId already uses and for the identical reason: a core
 * entity with an ORM association to a class that may not exist turns deleting the module into a
 * metadata load failure. The table is created by this same shared migration chain regardless of
 * whether the module's code is present, so the database enforces the reference for real
 * (`REFERENCES inventory_lot (id) ON DELETE SET NULL`) even though the mapping only sees an int —
 * see Version20260831090000, which took the same approach for `group_id`.
 *
 * ## The reservation ledgers' unique keys widen, matching InventoryDetail's own technique
 *
 * `order_inventory_reservation` was keyed `(order, product, warehouse, bucket)`;
 * `invoice_inventory_reservation` was keyed `(invoice, product, warehouse)` with no `bucket` in it
 * at all, because an invoice only ever holds one bucket at a time — until now: a lot-specific row
 * and this invoice's generic row for the same product+warehouse can no longer share an unqualified
 * key once a lot dimension exists, so `bucket` joins invoice_inventory_reservation's key for the
 * first time here.
 *
 * Both widened keys need `COALESCE(lot_id, 0), COALESCE(serial, '')` to collapse NULLs consistently
 * — a plain multi-column UNIQUE index treats two NULLs as distinct, which would let two rows with no
 * lot at all (the overwhelming majority, forever, for any product not opted in) coexist when they
 * are actually the same hold. That is exactly the technique `inventory_detail`'s own
 * `uniq_inventory_detail` index already uses (see Version20260821160000) and Doctrine's mapping
 * layer cannot emit it, so — like InventoryDetail itself — neither reservation entity declares an
 * `#[ORM\UniqueConstraint]` any more; the constraint lives here and only here.
 */
final class Version20260919210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot/serial/expiry reservation tracking: add lot_id/serial to sales_order_line, '
            . 'invoice_line, order_inventory_reservation and invoice_inventory_reservation, and widen '
            . 'the two reservation ledgers\' unique keys to include them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_line ADD COLUMN lot_id INTEGER DEFAULT NULL REFERENCES inventory_lot (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sales_order_line ADD COLUMN serial VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sales_order_line_lot ON sales_order_line (lot_id)');

        $this->addSql('ALTER TABLE invoice_line ADD COLUMN lot_id INTEGER DEFAULT NULL REFERENCES inventory_lot (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invoice_line ADD COLUMN serial VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_line_lot ON invoice_line (lot_id)');

        $this->addSql('ALTER TABLE order_inventory_reservation ADD COLUMN lot_id INTEGER DEFAULT NULL REFERENCES inventory_lot (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE order_inventory_reservation ADD COLUMN serial VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_order_inventory_reservation_lot ON order_inventory_reservation (lot_id)');
        $this->addSql('DROP INDEX uniq_reservation_order_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id, bucket, COALESCE(lot_id, 0), COALESCE(serial, \'\'))');

        $this->addSql('ALTER TABLE invoice_inventory_reservation ADD COLUMN lot_id INTEGER DEFAULT NULL REFERENCES inventory_lot (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invoice_inventory_reservation ADD COLUMN serial VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_inventory_reservation_lot ON invoice_inventory_reservation (lot_id)');
        $this->addSql('DROP INDEX uniq_invoice_reservation_invoice_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_warehouse ON invoice_inventory_reservation (invoice_id, product_id, warehouse_id, bucket, COALESCE(lot_id, 0), COALESCE(serial, \'\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_invoice_reservation_invoice_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_warehouse ON invoice_inventory_reservation (invoice_id, product_id, warehouse_id)');
        $this->addSql('DROP INDEX IF EXISTS idx_invoice_inventory_reservation_lot');

        $this->addSql('DROP INDEX uniq_reservation_order_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id, bucket)');
        $this->addSql('DROP INDEX IF EXISTS idx_order_inventory_reservation_lot');

        $this->addSql('DROP INDEX IF EXISTS idx_invoice_line_lot');
        $this->addSql('DROP INDEX IF EXISTS idx_sales_order_line_lot');

        // The four lot_id/serial columns themselves stay. SQLite gained DROP COLUMN in 3.35 and this
        // project's floor is 3.26, so removing them means create-copy-drop-rename of four tables
        // under a rollback, to delete columns that rolled-back code does not read — the same call
        // Version20260831090000 made for inventory_bucket_change_log.group_id, for the same reason.
    }
}
