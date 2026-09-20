<?php

declare(strict_types=1);

namespace ProcurementBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use ProcurementBundle\Entity\DebitMemoApplication;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPaymentApplication;
use ProcurementBundle\Status\VendorBillStatusDeriver;

/**
 * Keeps every vendor bill's derived status true, whatever wrote the bill or its payment claims
 * (#708, queue item 34 — "listener on both side").
 *
 * The buy-side twin of `App\EventSubscriber\InvoicePaymentStatusSubscriber`, built the same way for
 * the same reason: a derived value has to be recomputed on every write, and "every" cannot mean "at
 * the call sites we remembered" — which is exactly what happened here before this class existed.
 * `VendorBillStatusDeriver::recalculate()` used to be called explicitly, by hand, at every call site
 * that moved money against a bill; `VendorBillPaymentMover`'s own docblock documents three in
 * `VendorBillController` that existed before that class did, each one a place forgetting the call
 * would have gone unnoticed. This subscriber is what makes remembering unnecessary.
 *
 * `VendorBillPaymentApplication` is watched rather than `VendorBillPayment` (#708: a payment pool
 * can span several bills, so the pool itself has no single bill to queue — the CLAIM against one
 * does).
 *
 * ## Why this is a second class and not one shared with the sell side
 *
 * `App\Payment\PaymentApplicationGuard` is shared because its rules are genuinely about the
 * interfaces and nothing else. This is not that: `recalculate()` and `applyDerivedStatus()` are
 * typed to `VendorBill` specifically, VendorBillStatus's Draft/Void/Disputed refuse derivation in a
 * way `InvoicePaymentStatus` has no equivalent of, and `VendorBillLog`/`VendorBill::addLog()` are
 * this side's own timeline — mirroring the CALL SHAPE while keeping the IMPLEMENTATION separate is
 * the design this feature settled on throughout (#708): "force the shape, implementation let them
 * be".
 *
 * ## Both ends of a move, not just the destination
 *
 * A claim re-pointed at another bill (`VendorBill::moveApplication()`, withdraw-then-reapply on the
 * same underlying payment) is one row change with two consequences: the bill that gained it may
 * become Paid, and the bill that LOST it may cease to be — see
 * `InvoicePaymentStatusSubscriber`'s own docblock for why this needs the UNIT OF WORK's change set
 * rather than the entity, and why that has to live here rather than at the call site that performs
 * the move. `VendorBill::moveApplication()` does not call the deriver itself; this subscriber is the
 * only thing that does, on both sides of a move, on every write.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class VendorBillPaymentStatusSubscriber
{
    /** @var array<int, VendorBill> keyed by spl_object_id() — an inserted bill has no db id at onFlush time */
    private array $queued = [];

    public function __construct(
        private readonly VendorBillStatusDeriver $deriver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->queueFromEntity($entity);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->queueFromEntity($entity);
            $this->queueBillThePaymentLeft($entity, $uow);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->queueFromEntity($entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queued === []) {
            return;
        }

        // Cleared before recalculating, for the reason the sibling subscribers clear first: the
        // flush below opens its own onFlush/postFlush pass, and that inner pass must find an empty
        // queue rather than recalculating the same bills again.
        $bills = $this->queued;
        $this->queued = [];

        $changed = false;
        foreach ($bills as $bill) {
            $changed = $this->deriver->recalculate($bill) || $changed;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    private function queueFromEntity(object $entity): void
    {
        if ($entity instanceof VendorBill) {
            $this->queued[spl_object_id($entity)] = $entity;

            return;
        }

        if ($entity instanceof VendorBillPaymentApplication) {
            $bill = $entity->getBill();
            $this->queued[spl_object_id($bill)] = $bill;

            return;
        }

        // #771: applied debit is now part of the balance/status this subscriber keeps true, so a
        // bill that gained or lost a DebitMemoApplication needs recalculating exactly as one that
        // gained or lost a payment claim does. Mirrors InvoicePaymentStatusSubscriber (#603).
        if ($entity instanceof DebitMemoApplication) {
            $bill = $entity->getVendorBill();
            $this->queued[spl_object_id($bill)] = $bill;
        }
    }

    /**
     * Queues the bill a claim has just been moved OFF, when that is what the update was.
     *
     * `getEntityChangeSet()` gives [old, new] per changed field, and 'bill' is only present when the
     * association actually changed — so an amended amount or a corrected date passes straight through
     * here, and an insertion's old value is null rather than a bill.
     */
    private function queueBillThePaymentLeft(object $entity, UnitOfWork $uow): void
    {
        if (!$entity instanceof VendorBillPaymentApplication) {
            return;
        }

        $previous = $uow->getEntityChangeSet($entity)['bill'][0] ?? null;
        if ($previous instanceof VendorBill) {
            $this->queued[spl_object_id($previous)] = $previous;
        }
    }
}
