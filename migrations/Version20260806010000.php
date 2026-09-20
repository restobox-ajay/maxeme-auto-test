<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add audit_log.impersonator_admin_id and audit_log.impersonator_name.
 *
 * Schema only. There is no impersonation feature yet, nothing writes these columns, and nothing
 * reads them — this migration exists so the columns are already in production by the time there is.
 *
 * The reason to land them early is the table's size. audit_log only ever grows (22,232 rows on prod,
 * 686,636 on dev as this is written) and it is the last table anyone wants to rewrite. Adding a
 * nullable column with no default is metadata-only in SQLite: it appends to the stored table header
 * and does not touch a single row, so this ALTER is instant now and stays instant no matter how far
 * the table has grown. The alternative — introducing the columns alongside the feature, in a change
 * already carrying security-sensitive session work — buys nothing and costs the same ALTER later,
 * at a worse moment.
 *
 * What they will mean: audit_log currently records exactly one actor (actor_type / actor_id /
 * actor_name). The moment an admin can act as somebody else, that one slot is ambiguous — it names
 * the person being acted as, and the row silently loses the only fact accountability depends on,
 * which is who was actually at the keyboard. These two columns are that second identity.
 *
 * Nullable with no backfill, deliberately. Every row already in the table genuinely was not
 * impersonated, so NULL is the honest value rather than a placeholder standing in for one. A
 * backfill would also invent history and turn a metadata-only header change into a full write of
 * 700k rows for no gain.
 *
 * No foreign key to admin_user. actor_id already has none — it is polymorphic on actor_type, so it
 * cannot have one — and an FK here would be actively wrong in either direction it could be
 * configured: ON DELETE CASCADE would erase audit rows when the admin they incriminate is removed,
 * and RESTRICT would block ever removing an admin at all. An audit trail has to outlive its actors.
 *
 * No index either. Nothing queries these columns yet, so any index now would be a guess at an access
 * pattern that does not exist — "every action taken as someone else", "everything this admin did
 * while impersonating", or nothing at all — and index choices are far easier to add later than to
 * take back. It is added when there is a query to justify it.
 *
 * impersonator_name is denormalised on purpose, exactly as actor_name already is. It is a snapshot
 * taken at write time, not a join: the trail must still read correctly after the admin record it
 * refers to is deleted, which is precisely the situation in which anyone goes looking.
 *
 * Finishing this later is small. Every audit row in the system is built behind
 * AuditLogger::resolveActor() — both log() and queueEntityChange() call it, and no other code path
 * constructs an AuditLog — so populating these columns is a change to that one method and no
 * further schema work at all.
 *
 * The matching Doctrine properties land on src/Entity/AuditLog.php in the same commit, and have to:
 * bin/ci-migration-replay step 6 runs doctrine:schema:validate against the schema the chain
 * produced, so columns without mappings fail CI.
 */
final class Version20260806010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable audit_log.impersonator_admin_id and impersonator_name (schema only; no feature yet).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('audit_log')) {
            return;
        }

        $auditLog = $schema->getTable('audit_log');

        // Guarded per column rather than as a pair, in the style of the other additive migrations
        // (Version20260803190000, Version20260805030000), so a database that somehow has one of the
        // two already gets the other rather than skipping both.
        if (!$auditLog->hasColumn('impersonator_admin_id')) {
            $this->addSql('ALTER TABLE audit_log ADD COLUMN impersonator_admin_id INTEGER DEFAULT NULL');
        }

        if (!$auditLog->hasColumn('impersonator_name')) {
            $this->addSql('ALTER TABLE audit_log ADD COLUMN impersonator_name VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // No-op, as with the other additive migrations. Dropping a column needs SQLite 3.35+ and a
        // full table rebuild below that, and a rebuild of audit_log is the single most expensive and
        // most destructive thing this chain could be asked to do — for two columns that are NULL on
        // every row and that no code reads. Leaving them in place is already the pre-migration
        // behaviour.
        $this->addSql('SELECT 1');
    }
}
