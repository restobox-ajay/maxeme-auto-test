<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let one order hold two buckets for the same product and warehouse (#548).
 *
 * A split line holds both at once — six units of real stock in `sales_hold` and four promises in
 * `backordered`, for one (order, product, warehouse) — and the three-column unique key could not
 * represent that. `bucket` joins it.
 *
 * This has to land in the same release as the code that starts writing `backordered` rows, because
 * it changes what uniqueness MEANS rather than adding a column: run the code without it and the
 * second bucket's insert violates the old index.
 *
 * ## No table rebuild
 *
 * The plan called for create-copy-drop-rename, on the assumption that the constraint was part of
 * the table definition. It is not: Version20260821090000 emitted it as a standalone
 * `CREATE UNIQUE INDEX`, so redefining it is a DROP INDEX and a CREATE INDEX, and the rows are
 * never touched. That is strictly better than the rebuild — a rebuild here would mean a
 * `DROP TABLE order_inventory_reservation`, which is the operation bin/ci-migration-replay exists
 * to be suspicious of, for no gain whatsoever.
 *
 * Every pre-existing row's bucket is `sales_hold`, so widening the key cannot collide: it can only
 * ever admit rows the old key would have rejected.
 */
final class Version20260823103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#548: order reservations are unique per bucket, so one order can hold stock and a promise for the same SKU.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_reservation_order_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id, bucket)');
    }

    public function down(Schema $schema): void
    {
        // Narrowing the key again cannot succeed while a split order exists — two rows would
        // collide on it — so the promises are removed first. They are a cache of
        // sales_order_line.backordered_quantity and nothing else, rebuilt by the next reconcile of
        // each order, so this loses no fact the lines do not still hold.
        $this->addSql("DELETE FROM order_inventory_reservation WHERE bucket = 'backordered'");
        $this->addSql('DROP INDEX uniq_reservation_order_product_warehouse');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id)');
    }
}
