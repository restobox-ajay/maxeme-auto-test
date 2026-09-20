<?php

declare(strict_types=1);

namespace App\Command\Demo;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps a volume run's memory flat: flush, clear the identity map, and drop the debug query log.
 *
 * ## Why the query log as well
 *
 * `profiling_collect_backtrace` is on whenever the kernel is in debug mode (config/packages/
 * doctrine.yaml), and the debug data holder then keeps every query, its parameters and a backtrace
 * for the life of the process. A web request never notices. A seeder issuing tens of thousands of
 * queries does: it is the difference between a flat ~100 MB and an out-of-memory kill part-way
 * through a run that is otherwise fine. The holder does not exist at all without debug (prod, or
 * `--no-debug`), which is why it is optional here.
 *
 * ## The contract a caller keeps
 *
 * After checkpoint() every entity the caller was holding is DETACHED. Callers therefore hold ids,
 * not objects, across a checkpoint and re-find what they need afterwards — which is cheap, because
 * the identity map answers repeat finds within a batch without a query.
 */
final class DemoVolumeCheckpoint
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly ?DebugDataHolder $debugData = null,
    ) {
    }

    public function __invoke(): void
    {
        $this->em->flush();
        $this->em->clear();
        $this->debugData?->reset();
        gc_collect_cycles();
    }
}
