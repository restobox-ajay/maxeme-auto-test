<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The sales return (#596): two new tables and one new nullable column. Nothing else.
 *
 * `inventory_detail.status = 'returned'` gained a producer in #586, but only one, and only as a side
 * effect: a credit note ticked "the goods came back". Goods could therefore come back if and only if
 * a credit was issued in the same act. `sales_return` is the document that separates the two, so a
 * customer can ship something back before anybody has decided whether to credit it — which is the
 * ordinary case, not the edge case.
 *
 * ## NO BACKFILL. NOT OF ANYTHING.
 *
 * In particular, NOT of returns implied by existing credit notes. Every `credit_memo` with
 * `restock = 1` on an existing database describes goods that came back with no RMA behind them,
 * because no RMA existed to raise. Manufacturing one here would invent a document nobody
 * authorised, date it from a credit note's date, and attribute it to whichever warehouse the
 * restock happened to resolve to — three facts this migration cannot know and would be stating as
 * though it did. Those notes keep their `restock` flag, keep their `returned` rows, and keep
 * working exactly as they did: `credit_memo.sales_return_id` is NULL for every one of them, which
 * is precisely the "#586 credit note, unchanged" case CreditMemo::setRestock() still permits.
 *
 * No existing row is read or written by this migration at all. A database that runs it and stops
 * computes exactly the numbers it computed before: with no `sales_return` rows in existence,
 * SalesReturn::receive() has never run, no `returned` row has a return behind it, and the only
 * behavioural change #596 makes to existing data — the restock refusal — is unreachable while every
 * `sales_return_id` is NULL.
 *
 * ## Why credit_memo gains a COLUMN and not a table rebuild
 *
 * `ALTER TABLE ... ADD COLUMN`, which SQLite performs in place. Doctrine emulates a schema change
 * that SQLite cannot do — dropping a column, adding a constraint to an existing one — by rebuilding
 * the table through a temp copy, and Version20260730150000's history is why that is avoided here
 * unless it is unavoidable: a rebuild is a DROP TABLE, `PRAGMA foreign_keys = OFF` is silently
 * ignored inside a transaction, and the first version of that migration reported complete success
 * while cascading away every address snapshot the migration before it had just written.
 *
 * The cost is that the new column carries no FOREIGN KEY constraint on a MIGRATED database, while
 * one built from entity metadata (both test suites, via SchemaTool) does have it. That divergence is
 * deliberate and it is the smaller of the two risks: an unenforced reference on a nullable column
 * that only this application writes, against copying every credit note row through a temp table to
 * gain it. `bin/ci-migration-replay` compares the set of column NAMES the mappings expect against
 * the set the chain produces — which this satisfies — and deliberately does not compare constraints,
 * because 26 tables of pre-existing index-name and ON UPDATE drift already differ.
 *
 * The two NEW tables below declare their foreign keys in full, because a CREATE TABLE can and
 * nothing is being copied to get them.
 */
final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#596: sales returns — sales_return, sales_return_line, and credit_memo.sales_return_id. No backfill.';
    }

    public function up(Schema $schema): void
    {
        // The RMA header.
        //
        // sales_order_id and invoice_id are both NULLABLE, and both nullabilities are cases rather
        // than conveniences: a customer telephoning about a broken item has not told anybody which
        // of four invoices billed it, and refusing to raise the document until somebody finds out is
        // how parcels arrive with no reference on them. SET NULL on both, for the reason
        // credit_memo.invoice_id uses: losing the attribution must never delete the record that
        // goods came back.
        //
        // warehouse_id is NULL until receipt and is set BY receipt — "the goods arrived" and "they
        // arrived here" are one fact, so SalesReturn::receive() takes the warehouse as a parameter
        // and refuses without it. There is no fulfillment_region column: this document does not
        // price anything, and resolving a region NAME to a warehouse can answer null, which is a
        // tolerable outcome for a credit note that must issue anyway and not for a receipt.
        //
        // The three stamps are the history. There is no sales_return_log table, deliberately: each
        // stamp is written by exactly one named transition, and AuditLogSubscriber already picks up
        // the status column with the acting user attached.
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_return (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                document_number VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL,
                requested_at DATETIME NOT NULL,
                authorised_at DATETIME DEFAULT NULL,
                received_at DATETIME DEFAULT NULL,
                reason CLOB DEFAULT NULL,
                notes CLOB DEFAULT NULL,
                company_id INTEGER NOT NULL,
                sales_order_id INTEGER DEFAULT NULL,
                invoice_id INTEGER DEFAULT NULL,
                warehouse_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_856A6D07979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_856A6D07C023F51C FOREIGN KEY (sales_order_id) REFERENCES sales_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_856A6D072989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_856A6D075080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_856A6D0728F2AE32 ON sales_return (document_number)');
        $this->addSql('CREATE INDEX IDX_856A6D07C023F51C ON sales_return (sales_order_id)');
        $this->addSql('CREATE INDEX IDX_856A6D072989F1FD ON sales_return (invoice_id)');
        $this->addSql('CREATE INDEX IDX_856A6D075080ECDE ON sales_return (warehouse_id)');
        $this->addSql('CREATE INDEX idx_sales_return_company ON sales_return (company_id)');
        $this->addSql('CREATE INDEX idx_sales_return_status ON sales_return (status)');

        // The goods. Quantities are POSITIVE — this is a document about things travelling in a box,
        // and every quantity aggregate in this app assumes positive quantities.
        //
        // There is NO bin, lot or serial column here, and that absence is the design. A returning
        // parcel's contents are not known until somebody opens it, and where the units land is a
        // fact about the RECEIPT, not the authorisation: `inventory_detail` records it keyed by
        // (product, warehouse, bin, lot, serial, status) and `inventory_movement` records the act
        // that put it there. Copying those dimensions here would give two answers to "where are the
        // returned units", one of them frozen at authorisation time.
        //
        // product_id is NOT NULL, unlike credit_memo_line.product_id. A credit line may be freight
        // or a restocking fee — money with no SKU behind it — while a row here with no product is a
        // line the receipt could not receive and the ledger could not record.
        //
        // `disposition` moves no stock and nothing acts on it. It is what the receiver SAW, so the
        // person ruling on the units later is not guessing from a photograph. Every unit lands in
        // `returned` whatever it says, because a receiving clerk noting "looks fine" is not the
        // business deciding those goods may be sold to somebody else.
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_return_line (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                quantity NUMERIC(12, 2) NOT NULL,
                name VARCHAR(255) NOT NULL,
                sku VARCHAR(80) DEFAULT NULL,
                reason CLOB DEFAULT NULL,
                disposition VARCHAR(32) DEFAULT NULL,
                sort_order INTEGER DEFAULT 0 NOT NULL,
                sales_return_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                invoice_line_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_F4E915DB1E3A4E9C FOREIGN KEY (sales_return_id) REFERENCES sales_return (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_F4E915DB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_F4E915DBBFA24391 FOREIGN KEY (invoice_line_id) REFERENCES invoice_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_F4E915DB1E3A4E9C ON sales_return_line (sales_return_id)');
        $this->addSql('CREATE INDEX IDX_F4E915DB4584665A ON sales_return_line (product_id)');
        $this->addSql('CREATE INDEX IDX_F4E915DBBFA24391 ON sales_return_line (invoice_line_id)');

        // The one change to an existing table, and the only one #596 makes anywhere.
        //
        // NULL for every credit note that exists, which is what makes this migration inert: a note
        // with no return on it is the #586 note unchanged, may still restock, and
        // CreditMemoRestockSubscriber still does the work for it. A note WITH one has handed the
        // stock half to that return's receipt, and CreditMemo::setRestock() throws rather than
        // letting both fire — the same units would enter the ledger twice and quarantine would read
        // double, with no row saying which half was the mistake.
        $this->addSql('ALTER TABLE credit_memo ADD COLUMN sales_return_id INTEGER DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_AC882F171E3A4E9C ON credit_memo (sales_return_id)');
    }

    public function down(Schema $schema): void
    {
        // The index first, then the tables children-first: SQLite enforces the foreign keys above
        // when they are on.
        //
        // `credit_memo.sales_return_id` is deliberately NOT dropped. SQLite emulates DROP COLUMN by
        // rebuilding the table through a temp copy, which is a DROP TABLE that cascades to
        // credit_memo_line, credit_memo_address, credit_memo_application and credit_memo_refund —
        // the exact failure mode Version20260730150000 shipped once already, reporting success while
        // deleting everything. A nullable orphan integer column costs nothing; four cascaded child
        // tables cost a customer their credit history.
        $this->addSql('DROP INDEX IF EXISTS IDX_AC882F171E3A4E9C');
        $this->addSql('DROP TABLE IF EXISTS sales_return_line');
        $this->addSql('DROP TABLE IF EXISTS sales_return');
    }
}
