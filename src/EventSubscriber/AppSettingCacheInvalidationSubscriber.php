<?php

namespace App\EventSubscriber;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Drops the cached app_setting table whenever a setting is written through the ORM (#442).
 *
 * AppSettings caches the entire table under one key. Invalidation used to be entirely manual:
 * every write path was expected to remember AppSettings::clearCache() itself, and about twenty
 * places across ConfigController and the bundles do. That is a rule enforced by memory in a
 * codebase where any bundle can add its own settings screen — and because AppSettings exposes
 * reads only, those bundles write AppSetting rows straight through the EntityManager, where
 * nothing reminds them. Whoever forgets ships a setting that appears saved and does nothing for
 * up to an hour. It has already burned us in the test suite, where the stale entry outlived the
 * test that caused it: the cache pool lives on the filesystem under var/cache/<env>, so the
 * transaction rollback that isolates tests does not touch it, and the poisoned value went on to
 * fail unrelated tests with errors that pointed nowhere near the cause.
 *
 * The flush is the one thing every write path genuinely has in common, so that is where this
 * hooks. onFlush inspects the UnitOfWork for scheduled AppSetting work — the same shape as
 * AuditLogSubscriber, which is the established way to ask "what is this flush about to do?"
 *
 * The delete happens in postFlush rather than postPersist/postUpdate/postRemove on purpose. Those
 * three fire from inside UnitOfWork::commit(), i.e. before the database transaction commits. A
 * concurrent request that read AppSettings::all() in that window would repopulate the cache from
 * pre-commit state and we would be back to serving a stale value, except now with a much narrower
 * and more confusing reproduction. postFlush runs after the commit, so the row a repopulating read
 * finds is the row we just wrote. It also means a flush that throws never invalidates anything,
 * which is right: nothing changed.
 *
 * Deliberately injects the cache pool, not AppSettings. AppSettings depends on the
 * EntityManagerInterface; a Doctrine listener that depends on AppSettings closes that loop. The
 * shared cache key const is the whole coupling instead.
 *
 * Scope this does NOT cover, and why the TTL in AppSettings stays: writes the ORM never sees. The
 * admin SQL console, doctrine migrations, and the sqlite3 CLI all change rows without a
 * UnitOfWork, so they still need their explicit clearCache() (DatabaseConsoleController has one)
 * or the TTL to recover.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AppSettingCacheInvalidationSubscriber
{
    /**
     * Set in onFlush, acted on in postFlush. postFlush is given no changeset of its own — by then
     * the UnitOfWork has been cleaned out — so the two events have to be bridged by hand.
     *
     * If a flush throws between the two, this stays set and the next flush invalidates once for
     * no reason. That is a wasted cache rebuild, not a correctness problem, and it is the safe
     * direction to fail in.
     */
    private bool $settingsWereWritten = false;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $scheduled = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        foreach ($scheduled as $entity) {
            if ($entity instanceof AppSetting) {
                $this->settingsWereWritten = true;

                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->settingsWereWritten) {
            return;
        }

        // Cleared before the delete so that a nested flush — AuditLogSubscriber::postFlush writes
        // its queued rows with one — cannot re-enter this and delete the same key twice.
        $this->settingsWereWritten = false;

        $this->cache->delete(AppSettings::CACHE_KEY_ALL);
    }
}
