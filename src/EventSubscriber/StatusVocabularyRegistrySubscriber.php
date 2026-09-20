<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Contract\Status\StatusVocabularyLoaderInterface;
use App\Status\StatusVocabularyRegistry;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hands the loader to {@see StatusVocabularyRegistry} before anything can ask for a status.
 *
 * THREE hooks, and the third is the one that was learned the hard way.
 *
 *  - **`kernel.request`**, at a high priority and for sub-requests as well as master ones. Priming
 *    twice is free, and the alternative is reasoning about which of a page's fragments is allowed
 *    to render a status.
 *  - **`console.command`**, because a command is the application's other entry point.
 *  - **Doctrine's `preFlush`**, because a flush is not always inside either of those. The
 *    Codeception Symfony module's `haveInRepository()` persists and flushes with no request in
 *    flight at all, and `SalesOrderDerivedStatusSubscriber` then reaches a document's status from
 *    inside the UnitOfWork — with the registry unprimed, and a `LogicException` where a fixture
 *    should have been. `preFlush` fires at the very start of `flush()`, before changesets are
 *    computed and before `onFlush`, so anything an entity listener does to a status is covered.
 *
 * Hooking the flush rather than patching the test harness is deliberate: a harness fix would have
 * to be repeated in every harness, and it would leave the real hole — any flush from anywhere that
 * is not a request or a command — open and unproven.
 *
 * ## Why not a cache warmer, or the kernel itself
 *
 * A cache warmer runs at build time and primes nothing at runtime. `Kernel::boot()` would cover
 * every case in one line and is the obvious place, but core is frozen per-change and the owner
 * named what this work may take; these three hooks are additive, are the house patterns for what
 * they each do, and need no core file to be reopened.
 */
#[AsDoctrineListener(event: Events::preFlush)]
final class StatusVocabularyRegistrySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly StatusVocabularyLoaderInterface $loader)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Above Symfony's own routing (32) and firewall (8): a listener earlier than this one
            // that touched a document would otherwise find the registry empty.
            KernelEvents::REQUEST => ['prime', 1024],
            ConsoleEvents::COMMAND => ['prime', 1024],
        ];
    }

    /** $event is a RequestEvent or a ConsoleCommandEvent; neither is read. */
    public function prime(object $event): void
    {
        StatusVocabularyRegistry::use($this->loader);
    }

    /** Doctrine's own hook, named as that library expects rather than as a Symfony listener. */
    public function preFlush(object $event): void
    {
        StatusVocabularyRegistry::use($this->loader);
    }
}
