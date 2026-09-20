<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\BundleStatus;
use App\Entity\ProductInventory;
use App\Repository\BundleStatusRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Decides which bundle-owned buckets on `product_inventory` count toward availability.
 *
 * The columns are core; the logic that fills them lives in bundles. So each term has to gate on the
 * bundle that WRITES it being Active — and they are different bundles, which is why this is one
 * listener with several questions rather than one flag:
 *
 * | term           | written by          | sign |
 * |----------------|---------------------|------|
 * | `received`     | ProcurementBundle   | +    |
 * | `transfer_out` | WarehouseOpsBundle  | −    |
 * | `transfer_in`  | WarehouseOpsBundle  | +    |
 *
 * For `received` (#564):
 *
 *   bundle on:   available = quantity + received − cart_hold − sales_hold − pending − approved
 *   bundle off:  available = quantity            − cart_hold − sales_hold − pending − approved
 *
 * For the transfer pair (#574, redefined by #584), everything that has ever left this warehouse on
 * an internal transfer and everything that has ever arrived on one: both are in the sum while
 * WarehouseOpsBundle is Active and both are absent from it when it is not. Without the bundle no
 * transfer can be raised, so the columns are permanently 0 and the terms change nothing — but a
 * build that HAD transfers and then switched the bundle off must not keep withholding stock at the
 * warehouses that sent it, nor keep crediting the ones that got it.
 *
 * ONE flag for the pair, not two. They are the two halves of one document written by one service,
 * and the answer to "is this instance tracking transfers" cannot differ between them. Two flags
 * that must always agree is a bug waiting to happen — and a half-gated transfer is the nastiest
 * shape it could take, because each column still reads correctly on its own while the warehouse
 * totals are wrong by the difference.
 *
 * A listener rather than a service the call sites ask, because getAvailableQuantity() has
 * twenty-seven callers across core and four bundles. Rewriting all of them to thread a flag would
 * be a far larger change than the one being made, and every one of them would be a place to forget
 * it later. Stamping the entity once on load puts the decision in exactly one place and leaves
 * every existing caller correct without knowing this exists.
 *
 * Entities that are constructed rather than loaded never reach postLoad, which is harmless: a new
 * row has `received = 0`, so the flag cannot change its availability. The flag defaults to true on
 * the entity for the same reason — an instance without the bundle installed at all is correct with
 * this listener doing nothing.
 *
 * NOT zeroing on deactivation is the whole point. Off means the terms are excluded from the sum;
 * the rows sit untouched in between, so switching back on needs no recount, no re-import and no
 * manual step. See the toggle Cest, which asserts the column directly for exactly this reason —
 * a zeroed column and an excluded term give identical availability, so only reading the column
 * separates "not counted" from "destroyed".
 */
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class BundleBucketAvailabilityGate
{
    /**
     * The bundle that writes `received` and `incoming`. Matches BundleStatusRepository's source
     * convention — the first namespace segment of the bundle's classes.
     */
    public const WRITING_BUNDLE = 'ProcurementBundle';

    /** The bundle that writes `transfer_out` and `transfer_in`, by the same source convention. */
    public const TRANSFER_BUNDLE = 'WarehouseOpsBundle';

    /** The bundle that owns the detail rows `write_off` and `quarantine` are summed from. */
    public const DEPTH_BUNDLE = 'InventoryDepthBundle';

    private ?bool $active = null;

    private ?bool $transfersActive = null;

    private ?bool $depthActive = null;

    public function __construct(private readonly BundleStatusRepository $bundleStatuses)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof ProductInventory) {
            return;
        }

        $entity->setPositiveBucketsCount($this->isActive());
        $entity->setTransferBucketsCount($this->transfersActive());
        // `write_off` and `quarantine` are written by the depth layer's movements, which
        // WarehouseOpsBundle and ProcurementBundle both drive — but the detail-row statuses they
        // sum belong to InventoryDepthBundle, so that is what they gate on. Without the depth
        // bundle no detail row exists at all and both columns are permanently 0.
        $entity->setDepthBucketsCount($this->depthActive());
    }

    /**
     * Toggling the bundle drops the memo, so the very next load re-reads it.
     *
     * Without this the memo is only correct for the life of one HTTP request. That is fine for a
     * web request and wrong everywhere else that outlives one: a Messenger consumer processing an
     * import would hold whatever the status was when it booted, and a functional test that flips
     * the bundle would go on seeing the old answer. The toggle Cest caught exactly that on its
     * first run — availability stayed at 150 after deactivation — which is the whole reason the
     * plan insists that test gets written before the implementation.
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->forgetIfBundleStatus($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->forgetIfBundleStatus($args->getObject());
    }

    private function forgetIfBundleStatus(object $entity): void
    {
        if ($entity instanceof BundleStatus) {
            $this->active = null;
            $this->transfersActive = null;
            $this->depthActive = null;
        }
    }

    /**
     * Memoized because this runs on every ProductInventory load and a catalogue page loads
     * hundreds. BundleStatusRepository::isActive() goes through findOneBy(), which queries every
     * call — Doctrine's identity map does not spare it — so an unmemoized gate would turn one
     * catalogue page into hundreds of extra queries.
     *
     * Any write to any BundleStatus clears it, which is cheap and does not require knowing whether
     * the row that changed was ours.
     */
    private function isActive(): bool
    {
        return $this->active ??= $this->bundleStatuses->isActive(self::WRITING_BUNDLE);
    }

    /** Memoized for the same reason and cleared by the same invalidation. */
    private function transfersActive(): bool
    {
        return $this->transfersActive ??= $this->bundleStatuses->isActive(self::TRANSFER_BUNDLE);
    }

    /** Memoized for the same reason and cleared by the same invalidation. */
    private function depthActive(): bool
    {
        return $this->depthActive ??= $this->bundleStatuses->isActive(self::DEPTH_BUNDLE);
    }
}
