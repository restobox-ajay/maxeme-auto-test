<?php

declare(strict_types=1);

namespace App\Status;

use App\Contract\Status\StatusVocabularyLoaderInterface;

/**
 * The one static call an entity makes to reach the loader.
 *
 * The handoff settles that `HasStatus::loadStatusVocab()` is static, and records why every
 * alternative fails (see that interface). What it does not say is how a static method on an entity
 * reaches a container service, because nothing in this codebase had needed to before — there is no
 * existing static service bridge in `src/Entity` or anywhere else. This is that plumbing, and it is
 * deliberately the smallest possible amount of it: one class, one static property, primed once.
 *
 * The handoff's own words are the design constraint: *"the service-locator call is confined to one
 * well-named method per class"*. This is the thing that method calls.
 *
 * ## It is primed, and it is loud when it is not
 *
 * {@see \App\EventSubscriber\StatusVocabularyRegistrySubscriber} primes it at the start of every
 * request and every console command — the two ways this application does anything. If something
 * reaches a status before either has happened, {@see self::loader()} throws and says so by name.
 *
 * It does NOT fall back to building a loader of its own. A fallback would mean a status seam that
 * works with whatever providers it could find, differing from the real one in ways nobody would
 * notice until a bundle's statuses were quietly missing — the fail-silently the owner has banned,
 * in the one place that decides what a status is.
 */
final class StatusVocabularyRegistry
{
    private static ?StatusVocabularyLoaderInterface $loader = null;

    /** Not instantiable: this is a bridge, not a service. The service is the loader it holds. */
    private function __construct()
    {
    }

    /** Called once per request or command by the subscriber. Idempotent. */
    public static function use(StatusVocabularyLoaderInterface $loader): void
    {
        self::$loader = $loader;
    }

    /**
     * Forgets the loader.
     *
     * For tests that swap one in and must not leak it into the next case — Codeception reuses one
     * Cest instance across methods, and static state outlives a test either way.
     */
    public static function reset(): void
    {
        self::$loader = null;
    }

    public static function isPrimed(): bool
    {
        return self::$loader !== null;
    }

    /** The whole point: one named call, resolving one vocabulary. */
    public static function get(string $key): StatusVocab
    {
        return self::loader()->getVocabulary($key);
    }

    public static function loader(): StatusVocabularyLoaderInterface
    {
        if (self::$loader === null) {
            throw new \LogicException(
                'The status vocabulary registry was never primed, so nothing can say what statuses exist.'
                    . ' App\EventSubscriber\StatusVocabularyRegistrySubscriber primes it on kernel.request and'
                    . ' console.command; code reaching a status outside both — a unit test constructing an entity'
                    . ' with no kernel, say — must call StatusVocabularyRegistry::use() itself and'
                    . ' StatusVocabularyRegistry::reset() afterwards.',
            );
        }

        return self::$loader;
    }
}
