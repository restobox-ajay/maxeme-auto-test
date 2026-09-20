<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 4 of #539: payments move onto the invoice, and the order stops claiming to be paid.
 *
 * Three changes, in this order because each depends on the one before:
 *
 *  1. `sales_order_payment` becomes `invoice_payment`, re-pointed at the order's invoice. Stage 1
 *     gave every order that existed then exactly one, so the mapping is unambiguous — see up() for
 *     what happens to a payment whose order somehow has none.
 *  2. `invoice.payment_status` becomes derived: recomputed from the payments this migration just
 *     moved, normalised to the three values InvoicePaymentStatus knows, and made NOT NULL.
 *  3. `sales_order.payment_status` is dropped outright.
 *
 * ## Why the order's column is dropped rather than left dormant
 *
 * Because it is the one column of the four whose whole content is a claim about money, and the
 * invoice now owns that claim. Left in place it would be a second answer that nothing recomputes:
 * correct on the day of this migration and wrong the first time a payment is recorded, with no
 * error and nothing to notice it. Dropping it makes "nothing reads the order's payment status as
 * truth" a fact the type system enforces rather than a convention a reviewer has to police — the
 * same argument #539 makes for taking setStatus() off the entities.
 *
 * `payment_method`, `payment_term` and `invoice_date` are deliberately kept. They are terms of the
 * sale and a date, not answers about money: each is copied onto every invoice raised from the order
 * (OrderInvoicingService), and it is the invoice's copy that governs what is owed and when. Stage 6
 * re-homes what is left of them along with the order's own PDF and the admin grids.
 *
 * ## Why this rewrites payment statuses at all
 *
 * Confirmed with the client before stage 1 and unchanged: no production instance holds any order
 * data. Every instance is a dev one, which is what makes re-anchoring cheap enough to do outright.
 *
 * The rewrite is not cosmetic. A payment status that could be typed in was free to disagree with the
 * payment rows, and rows where it did exist: an invoice reading 'Paid' with nothing recorded against
 * it becomes 'Not Paid' here, because from stage 4 payment status IS the sum of the payments and
 * there is no payment. The alternative — inventing a payment row to justify the old label — would
 * fabricate money that was never received, which is worse than a status an admin can correct by
 * recording the payment that actually arrived.
 */
final class Version20260820150000 extends AbstractMigration
{
    /** Rebuilds two tables (production SQLite 3.26 has no DROP COLUMN); see SqliteTableRebuild. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return '#539 stage 4: move payments onto invoice_payment and derive payment status there.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        // Same columns as sales_order_payment, with invoice_id where order_id was. Constraint and
        // index names are spelled out rather than left as Doctrine's hashes, matching the invoice
        // tables stage 1 created.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_payment (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                received_at DATE NOT NULL,
                method VARCHAR(64) NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                comment VARCHAR(255) DEFAULT NULL,
                stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_invoice_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id),
                CONSTRAINT fk_invoice_payment_user FOREIGN KEY (user_id) REFERENCES admin_user (id)
            )
        SQL);

        // The unique index is what makes StripeOrderPaymentApplier idempotent against a browser and
        // a webhook racing on the same PaymentIntent, so it travels with the table.
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_payment_stripe_intent ON invoice_payment (stripe_payment_intent_id)');
        $this->addSql('CREATE INDEX idx_invoice_payment_invoice ON invoice_payment (invoice_id)');
        $this->addSql('CREATE INDEX idx_invoice_payment_user ON invoice_payment (user_id)');

        // A payment whose order has no invoice has nowhere to go — invoice_payment.invoice_id is NOT
        // NULL — and silently dropping money is not an option. So it stops the migration rather than
        // being skipped, and it is checked BEFORE anything is copied.
        //
        // Every order that existed when stage 1 ran has an invoice, which is what makes this a
        // guard rather than a real branch. It stopped being an invariant going forward: since stage
        // 2 an admin-created order carries no invoice until someone converts it (ecom checkout still
        // writes its 1:1 pair atomically), so an order created after that and paid through the old
        // order-payments screen before this migration ran would land here. Raising its invoice first
        // is the fix; this will not throw the payment away to make itself run.
        $orphans = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM sales_order_payment p
            WHERE NOT EXISTS (SELECT 1 FROM invoice i WHERE i.sales_order_id = p.order_id)
        SQL);
        $this->abortIf(
            $orphans > 0,
            sprintf(
                '%d payment(s) belong to an order with no invoice and cannot be moved onto one.'
                . ' Raise those orders\' invoices first; this migration will not drop the payments.',
                $orphans,
            ),
        );

        // Onto the order's oldest invoice. For every row this can meet, that is simply "its invoice"
        // — MIN(id) is how it stays a single statement rather than a loop, and it picks the stage 1
        // shadow if a second invoice was raised against the same order in between. Ids are preserved
        // so anything holding a payment id still resolves to the same payment.
        $this->addSql(<<<'SQL'
            INSERT INTO invoice_payment (
                id, invoice_id, user_id, received_at, method, amount, comment,
                stripe_payment_intent_id, created_at
            )
            SELECT
                p.id,
                (SELECT MIN(i.id) FROM invoice i WHERE i.sales_order_id = p.order_id),
                p.user_id, p.received_at, p.method, p.amount, p.comment,
                p.stripe_payment_intent_id, p.created_at
            FROM sales_order_payment p
        SQL);

        $this->addSql('DROP TABLE sales_order_payment');

        // payment_status becomes what the payments say, for every row, which is the whole point of
        // stage 4. Written before the column is made NOT NULL so nothing is left null to reject.
        $this->addSql(<<<'SQL'
            UPDATE invoice SET payment_status = CASE
                WHEN CAST(COALESCE(total, 0) AS REAL) <= 0 THEN 'Paid'
                WHEN (SELECT COALESCE(SUM(CAST(amount AS REAL)), 0) FROM invoice_payment WHERE invoice_id = invoice.id)
                     >= CAST(total AS REAL) THEN 'Paid'
                WHEN (SELECT COALESCE(SUM(CAST(amount AS REAL)), 0) FROM invoice_payment WHERE invoice_id = invoice.id)
                     > 0 THEN 'Partially Paid'
                ELSE 'Not Paid'
            END
        SQL);

        // SQLite cannot tighten a column to NOT NULL in place, so the table is rebuilt.
        $statements = [
            ...SqliteTableRebuild::statements($this->connection, 'invoice', [
                'payment_status' => "payment_status VARCHAR(40) DEFAULT 'Not Paid' NOT NULL",
            ]),
            ...SqliteTableRebuild::statements($this->connection, 'sales_order', ['payment_status' => null]),
        ];
        foreach ($statements as $sql) {
            $this->addSql($sql);
        }

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * Puts the payments back on the order and the claim back on both rows.
     *
     * Exact for everything up() did: an invoice knows the order it bills, so no payment loses its
     * order, and the order's payment status can be read back off its invoices. A payment on a
     * standalone invoice — one billing no order, which up() cannot produce but a later stage can —
     * has no order to return to, so this refuses rather than deleting it.
     */
    public function down(Schema $schema): void
    {
        $stranded = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM invoice_payment p
            JOIN invoice i ON i.id = p.invoice_id
            WHERE i.sales_order_id IS NULL
        SQL);
        $this->abortIf(
            $stranded > 0,
            sprintf('%d payment(s) sit on invoices that bill no order and cannot be moved back.', $stranded),
        );

        $this->addSql("ALTER TABLE sales_order ADD COLUMN payment_status VARCHAR(40) DEFAULT NULL");

        $this->addSql(<<<'SQL'
            CREATE TABLE sales_order_payment (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                received_at DATE NOT NULL,
                method VARCHAR(64) NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                comment VARCHAR(255) DEFAULT NULL,
                stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                order_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_5E745BF58D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_5E745BF5A76ED395 FOREIGN KEY (user_id) REFERENCES admin_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E745BF5FC72F97E ON sales_order_payment (stripe_payment_intent_id)');
        $this->addSql('CREATE INDEX IDX_5E745BF58D9F6D38 ON sales_order_payment (order_id)');
        $this->addSql('CREATE INDEX IDX_5E745BF5A76ED395 ON sales_order_payment (user_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO sales_order_payment (
                id, received_at, method, amount, comment, stripe_payment_intent_id, created_at,
                order_id, user_id
            )
            SELECT
                p.id, p.received_at, p.method, p.amount, p.comment, p.stripe_payment_intent_id,
                p.created_at, i.sales_order_id, p.user_id
            FROM invoice_payment p
            JOIN invoice i ON i.id = p.invoice_id
        SQL);

        $this->addSql('DROP TABLE invoice_payment');

        // The order's claim comes back off its invoices rather than being guessed: Paid only when
        // every invoice that counts is paid, which is the same rollup the grids compute.
        $this->addSql(<<<'SQL'
            UPDATE sales_order SET payment_status = CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM invoice i
                    WHERE i.sales_order_id = sales_order.id AND i.status NOT IN ('Draft', 'Cancelled')
                ) THEN 'Not Paid'
                WHEN NOT EXISTS (
                    SELECT 1 FROM invoice i
                    WHERE i.sales_order_id = sales_order.id AND i.status NOT IN ('Draft', 'Cancelled')
                      AND i.payment_status <> 'Paid'
                ) THEN 'Paid'
                WHEN EXISTS (
                    SELECT 1 FROM invoice i
                    WHERE i.sales_order_id = sales_order.id AND i.status NOT IN ('Draft', 'Cancelled')
                      AND i.payment_status <> 'Not Paid'
                ) THEN 'Partially Paid'
                ELSE 'Not Paid'
            END
        SQL);

        // Back to nullable, which is what the column was before stage 4 derived it.
        $this->addSql("ALTER TABLE invoice ADD COLUMN payment_status_free VARCHAR(40) DEFAULT NULL");
        $this->addSql('UPDATE invoice SET payment_status_free = payment_status');
        $this->addSql('ALTER TABLE invoice DROP COLUMN payment_status');
        $this->addSql('ALTER TABLE invoice RENAME COLUMN payment_status_free TO payment_status');
    }
}
