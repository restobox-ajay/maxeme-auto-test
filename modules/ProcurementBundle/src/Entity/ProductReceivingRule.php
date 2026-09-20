<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\ProductReceivingRuleRepository;

/**
 * What a receiver must capture for one product before its goods may be booked in (#555, item 67).
 *
 * ## Three of its four answers are DERIVED, not stored
 *
 * This table used to hold four booleans, and the three about identity were duplicates of facts
 * `App\Entity\TrackingPolicy` already held. That is the defect item 67 turned out to be. The
 * guards in ReceivingService were fine and well worded; they never fired, because `ruleFor()` hands
 * back an EMPTY rule when the product has no row, this class documented "no row means nothing is
 * required", and **nothing in the application ever created a row**. A warehouse could put every
 * product on a Serial tracking policy, see the word "serial" on every screen that mentions the
 * product, and book the goods in with the box blank — because receiving asked a different table,
 * and that table was empty.
 *
 * Two tables holding one fact is the shape behind #589, #590 and #591, and the fix is the same one:
 * stop holding it twice.
 *
 *     isLotRequired()    -> policy->tracksLotsInbound()     (mode = lot    && track_in)
 *     isSerialRequired() -> policy->tracksSerialsInbound()  (mode = serial && track_in)
 *     isExpiryRequired() -> policy->requiresExpiry()        (mode-independent — #795)
 *
 * The policy is reached through `ProductCore::getTrackingPolicy()`, and a product pointing at
 * nothing answers through an INERT policy — mode `none`, nothing tracked — so "no policy" is a
 * complete answer and the empty-means-nothing hole is not re-created one level down. A product on
 * the default `None` policy behaves exactly as every product did before #573, which is the property
 * that whole ticket was built to keep.
 *
 * The superseded justification, recorded because it was load-bearing and has expired: this table's
 * original reason was that "#550 gives every dimensional product the ability to carry a lot, an
 * expiry and a serial, and deliberately no way to say that one must". #573 built that way — the
 * tracking policy IS how a product says a unit must carry an identity — and this table went on
 * storing its own copy anyway.
 *
 * ## The fourth answer is still a column, and belongs here
 *
 * `location_required` is procurement's own operational policy and has no counterpart on the
 * tracking policy: a bin is where goods were PUT, not what a unit carries. Ruled and settled.
 *
 * ## Why it is a bundle table and not a column on product_core
 *
 * Because core must be able to read nothing this bundle writes, and because it must be deletable.
 * A row here is procurement's own policy about a product, it goes when procurement goes, and its
 * absence is a complete and correct answer: **no row means no bin is required**, and the identity
 * requirements are the policy's to answer whether this table exists or not.
 *
 * The foreign key here IS real and DOES cascade, unlike the ones on the document lines: this is
 * live configuration about a product that currently exists, not a snapshot of something that
 * happened. When the product goes, the policy about it is meaningless.
 */
#[ORM\Entity(repositoryClass: ProductReceivingRuleRepository::class)]
#[ORM\Table(name: 'procurement_product_rule')]
#[ORM\UniqueConstraint(name: 'uniq_procurement_rule_product', fields: ['product'])]
class ProductReceivingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /** Goods may not be booked in without saying which bin they went into. */
    #[ORM\Column(name: 'location_required', options: ['default' => false])]
    private bool $locationRequired = false;

    /**
     * This product's own minimum shelf life at receiving, in DAYS, overriding the global one.
     *
     * ## Three states, and they are three different answers
     *
     * | value  | means                                                                     |
     * |--------|---------------------------------------------------------------------------|
     * | `null` | **Not set.** Use the global minimum. The default, and what no row means.   |
     * | `0`    | **No minimum at all** for this product. A deliberate exemption.            |
     * | `N`    | This product's own minimum, whether that is stricter or looser than global.|
     *
     * `null` and `0` are deliberately not the same value. A buyer exempting a product whose supplier
     * always ships two weeks out has said something; a product nobody has an opinion about has not,
     * and must keep following the global when the global changes. Collapsing them would make the
     * exemption evaporate the next time somebody edits the global setting.
     *
     * ## This does NOT re-open the hole item 67 closed
     *
     * That hole was `procurement_product_rule` being the ONLY place a requirement lived while
     * nothing ever created a row, so "no row" answered "nothing is required" for every product in
     * the database. Here the answer for a product with no row is **the global minimum**, which is a
     * real setting that applies to every product whether or not it has a row here. Nothing asks this
     * column on its own: {@see \ProcurementBundle\Receiving\MinimumShelfLife::minimumFor()} is the
     * only reader, it consults the global first, and an absent override is an absent OVERRIDE rather
     * than an absent requirement.
     *
     * ## Days, not months
     *
     * See MinimumShelfLife's docblock. In short: the shortfall is a difference between two dates,
     * `inventory_lot.expiry` is a date, and a month is not a length — a "2 month" minimum is 59 days
     * in February and 62 in July, so the same pallet passes or fails depending on when it lands.
     *
     * ## Why here rather than on product_core
     *
     * Same reason `location_required` is here. This is procurement's policy about receiving a
     * product, not a physical fact about the goods: the shelf life is the manufacturer's, and what
     * this business refuses to accept at its own dock is a buying decision. Core must be able to
     * read nothing this bundle writes, and the bundle must stay deletable — delete it and the
     * override goes with it, which is correct, because nothing else was ever enforcing it.
     */
    #[ORM\Column(name: 'minimum_shelf_life_days', type: 'integer', nullable: true)]
    private ?int $minimumShelfLifeDays = null;

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function isLocationRequired(): bool { return $this->locationRequired; }
    public function setLocationRequired(bool $locationRequired): self { $this->locationRequired = $locationRequired; return $this; }
    public function getMinimumShelfLifeDays(): ?int { return $this->minimumShelfLifeDays; }

    /** Null clears the override and hands the product back to the global; a negative figure is not a length. */
    public function setMinimumShelfLifeDays(?int $days): self
    {
        $this->minimumShelfLifeDays = $days === null ? null : max(0, $days);

        return $this;
    }

    /**
     * Goods may not be booked in without naming a batch.
     *
     * Derived from the tracking policy, which is the one place a product says what a unit carries.
     * There is no setter: this is not a thing procurement decides separately, and a setter is how it
     * would go back to being decided in two places.
     */
    public function isLotRequired(): bool
    {
        return $this->policy()->tracksLotsInbound();
    }

    /**
     * Every unit must be booked in under its own serial.
     *
     * Which also means every receipt line for this product is for exactly one unit — #550's
     * `uniq_live_serial` allows a serial only one row holding stock at a time, so two of anything
     * under one serial is a contradiction rather than a quantity.
     */
    public function isSerialRequired(): bool
    {
        return $this->policy()->tracksSerialsInbound();
    }

    /**
     * The batch must also carry an expiry date.
     *
     * Separate from the batch requirement because they are separate facts: a hardware batch code
     * identifies a production run and never expires, and requiring a date for it would get a made-up
     * one.
     *
     * **Deliberately `requiresExpiry()` and not `tracksLotsInbound() && requiresExpiry()`,
     * as ruled.** The consequence, stated because it is reachable: a policy in `lot` mode with both
     * directions unticked captures nothing inbound — `isInert()` is true and `isLotRequired()` is
     * false — and if it also carries `requires_expiry` it demands an expiry with no batch to hang
     * it on. That combination is the "None wearing another name" one the walkthrough flagged on the
     * policy form, and the narrower expression above is the one-line change if it should not be
     * reachable at all.
     */
    public function isExpiryRequired(): bool
    {
        return $this->policy()->requiresExpiry();
    }

    /**
     * Nothing is required that this ROW decides — so the row carries nothing and is deleted.
     *
     * Deliberately about the STORED columns only — the bin and the shelf-life override. A row
     * holding a shelf-life override of `0` is NOT empty: zero means "no minimum at all for this
     * product", which is a thing somebody said, and deleting the row would turn it back into
     * "not set" and hand the product to the global. That is the one place these two columns differ
     * in kind: `location_required = false` carries no information, `minimum_shelf_life_days = 0`
     * carries all of it.
     *
     * The identity requirements are the policy's and
     * survive this row's deletion, which is the whole point of deriving them: a rule row is no
     * longer something anybody has to remember to create.
     */
    public function isEmpty(): bool
    {
        return !$this->locationRequired && $this->minimumShelfLifeDays === null;
    }

    /**
     * The product's declared tracking policy, or an inert one.
     *
     * `TrackingPolicyRepository::policyFor()` does the same thing and cannot be used here: an entity
     * has no container. A fresh `TrackingPolicy` is mode `none` with both directions false, so every
     * derived answer above is false for a product that points at nothing — which is exactly how
     * receiving behaved before any of this existed.
     *
     * `$product` is a typed property with no default, so it is read through `isset()` — the same
     * treatment `InventoryDetail::isPlaceholderSerial()` gives its own. A rule being assembled field
     * by field has no product to ask, and no product means no policy, which means nothing required.
     */
    private function policy(): TrackingPolicy
    {
        if (!isset($this->product)) {
            return new TrackingPolicy();
        }

        return $this->product->getTrackingPolicy() ?? new TrackingPolicy();
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('Receiving rule for %s', $this->product->getSku() ?: ('#' . (string) $this->product->getId()));
    }
}
