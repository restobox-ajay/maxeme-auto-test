<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\InventoryAdjustmentReasonRepository;

/**
 * What happened to the stock — a stored, configurable row rather than a sentence somebody typed
 * (#585).
 *
 * ## The problem this replaces
 *
 * `inventory_movement_group.reason` is free text and stays free text. On dev it holds
 * "Opening balance on switching to dimensional inventory" (84 rows), "Received without a purchase
 * order" (45) and `E2E <img src=x onerror=alert(1)>` (2). Nothing can be reported on that, nothing
 * groups, and nothing maps to a G/L account — which is the thing a finance department eventually
 * asks of a write-off. Zoho Inventory hangs an *adjustment account* off the reason; Dynamics 365 BC
 * hangs a *reason code* off the item ledger entry. Both make the reason a row.
 *
 * So the reason becomes a row here too, and the free text keeps its column beside it as the note.
 * The two answer different questions: the code says WHAT CLASS OF THING happened, the note says
 * what the operator wants the next reader to know about this particular one. Collapsing them —
 * either by classifying the free text or by dropping it — loses one of those. **No existing
 * `reason` value is touched by this issue**; the code column simply starts empty for every group
 * written before it existed.
 *
 * ## The reason derives the movement; the operator never picks a status
 *
 * This is the whole shape of #585. The old adjustment screen was a movement editor: it asked for a
 * from-status and a to-status out of the full nine-value enum and left the operator to infer which
 * pair meant "this pallet is broken". You could label a write-off a `receipt`. Spoilage could not be
 * recorded at all, because `expired` was not on the withdraw dropdown, so anyone finding spoiled
 * stock before the nightly lot sweep had to mis-file it as `scrapped`.
 *
 * A reason row carries the pair instead:
 *
 * | reason              | from                | to          | bucket effect |
 * |---------------------|---------------------|-------------|---------------|
 * | Stock found         | — (enters)          | available   | received  +   |
 * | Reverse a write-off | the original's `to` | available   | write_off −   |
 * | Damaged             | available           | damaged     | write_off +   |
 * | Spoiled / expired   | available           | expired     | write_off +   |
 * | Scrapped            | available           | scrapped    | write_off +   |
 * | Lost / shrinkage    | available           | lost        | write_off +   |
 * | Put on hold         | available           | quarantine  | quarantine +  |
 * | Release from hold   | quarantine          | available   | quarantine −  |
 *
 * The third column is NOT stored and never will be. A bucket is recomputed from the detail rows by
 * StockMovementService::syncCoreTotal() as a consequence of the movement; a reason that could name
 * one would be a second writer of a derived number, which is exactly the class of bug #572, #581
 * and #584 each spent an issue removing.
 *
 * ## Why no reason names a destination the operator chooses
 *
 * Because that is what makes the from/to structure removable. Every row above has a fixed
 * destination, or one that comes from the entry being reversed. If a reason ever needed the
 * operator to pick where the stock lands, the screen would be back to being a movement editor with
 * extra steps.
 *
 * ## What is deliberately NOT a reason
 *
 * The rule the adjustment screen now follows: **if a document should exist, it is not an
 * adjustment.**
 *
 *  - a transfer has `/admin/bundles/warehouse-ops/transfers` — draft, dispatch, receive, partial
 *    receipt, stock lost in transit, 27 orders on dev;
 *  - a bin move has `/admin/bundles/warehouse-ops/scan`, and changes no bucket at all;
 *  - a customer return needs a credit memo linked to the invoice (#586, not built). Until that
 *    exists `returned` has no producer, which is the same state `sold` is in while there is no
 *    dispatch layer. EveryAdjustmentReasonIsDerivableTest asserts that gap explicitly rather than
 *    leaving it to be noticed.
 *
 * ## Configurable, and therefore re-checked
 *
 * These are ordinary rows an admin can deactivate, relabel or add to, which is the point of storing
 * them. It also means the pair of statuses on a row is UNTRUSTED INPUT by the time
 * AdjustmentController reads it: somebody could set `to_status = 'sold'` and hand this screen the
 * power #581 took away from it. So the controller re-checks both derived sides against
 * InventoryDetail::documentBackedStatuses() before applying anything, exactly as it used to
 * re-check the dropdown. The list is a courtesy; the refusal is the rule.
 */
#[ORM\Entity(repositoryClass: InventoryAdjustmentReasonRepository::class)]
#[ORM\Table(name: 'inventory_adjustment_reason')]
#[ORM\UniqueConstraint(name: 'uniq_adjustment_reason_code', fields: ['code'])]
class InventoryAdjustmentReason
{
    public const CODE_STOCK_FOUND = 'stock_found';
    public const CODE_REVERSE_WRITE_OFF = 'reverse_write_off';
    public const CODE_DAMAGED = 'damaged';
    public const CODE_SPOILED = 'spoiled';
    public const CODE_SCRAPPED = 'scrapped';
    public const CODE_LOST = 'lost';
    public const CODE_HOLD = 'hold';
    public const CODE_RELEASE_HOLD = 'release_hold';

    /**
     * The eight reasons the screen ships with, and the ONE place the (from_status, to_status) pair
     * for each is written down.
     *
     * The migration seeds from this list, InventoryAdjustmentReasonRepository::ensureCatalogue()
     * lazily recreates a missing row by code on a metadata-built schema (both test suites), and
     * EveryAdjustmentReasonIsDerivableTest asserts the partition over it. Three readers, one
     * source — which is the property that stops the list and the mapping drifting apart, the way
     * EveryStatusIsAllocatedTest stops the status groups drifting from InventoryDetail::statuses().
     *
     * `from` is NULL in two quite different cases and the `reversal` flag is what separates them:
     *
     *  - Stock found genuinely has no source. The units enter the ledger; `received` absorbs them.
     *  - Reverse a write-off has a source that is not knowable until an operator picks the entry
     *    being reversed — it is that movement's `to` row, which is `damaged` on one entry and
     *    `lost` on the next. Storing one of the four here would be storing a lie about the other
     *    three.
     *
     * @return list<array{code: string, label: string, from: ?string, to: ?string, reversal: bool, help: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'code' => self::CODE_STOCK_FOUND,
                'label' => 'Stock found',
                'from' => null,
                'to' => InventoryDetail::STATUS_AVAILABLE,
                'reversal' => false,
                'help' => 'Units on the shelf the system has never counted. They enter the ledger here, so use this only when nothing was previously written off — otherwise the loss stays on the books and the same goods are invented a second time.',
            ],
            [
                'code' => self::CODE_REVERSE_WRITE_OFF,
                'label' => 'Reverse a write-off',
                'from' => null,
                'to' => InventoryDetail::STATUS_AVAILABLE,
                'reversal' => true,
                'help' => 'Stock that was written off and has turned up. Pick the entry that wrote it off; the bin, lot and serial come back from that entry, so nothing is retyped and the loss comes off the books.',
            ],
            [
                'code' => self::CODE_DAMAGED,
                'label' => 'Damaged',
                'from' => InventoryDetail::STATUS_AVAILABLE,
                'to' => InventoryDetail::STATUS_DAMAGED,
                'reversal' => false,
                'help' => 'Physically broken and not sellable. Still on the premises and still countable.',
            ],
            [
                'code' => self::CODE_SPOILED,
                'label' => 'Spoiled / expired',
                'from' => InventoryDetail::STATUS_AVAILABLE,
                'to' => InventoryDetail::STATUS_EXPIRED,
                'reversal' => false,
                'help' => 'Past its date, found by a person rather than by the nightly lot sweep. The lot list defaults to batches at or past expiry, which is what this reason is for.',
            ],
            [
                'code' => self::CODE_SCRAPPED,
                'label' => 'Scrapped',
                'from' => InventoryDetail::STATUS_AVAILABLE,
                'to' => InventoryDetail::STATUS_SCRAPPED,
                'reversal' => false,
                'help' => 'Deliberately destroyed or disposed of. Keeps its warehouse and drops its bin, so which site scrapped it stays answerable.',
            ],
            [
                'code' => self::CODE_LOST,
                'label' => 'Lost / shrinkage',
                'from' => InventoryDetail::STATUS_AVAILABLE,
                'to' => InventoryDetail::STATUS_LOST,
                'reversal' => false,
                'help' => 'Missing and not explained. If it turns up later, reverse this entry rather than recording it as stock found.',
            ],
            [
                'code' => self::CODE_HOLD,
                'label' => 'Put on hold',
                'from' => InventoryDetail::STATUS_AVAILABLE,
                'to' => InventoryDetail::STATUS_QUARANTINE,
                'reversal' => false,
                'help' => 'Held back pending somebody\'s decision. Physically present and countable, and it may well come back.',
            ],
            [
                'code' => self::CODE_RELEASE_HOLD,
                'label' => 'Release from hold',
                'from' => InventoryDetail::STATUS_QUARANTINE,
                'to' => InventoryDetail::STATUS_AVAILABLE,
                'reversal' => false,
                'help' => 'Ruled fit to sell again. Pick the held rows it comes off, because a hold is always against particular goods.',
            ],
        ];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The stable identifier a URL, a POST body and a later G/L mapping all name it by.
     *
     * Separate from $label precisely so the label can be reworded — "Lost / shrinkage" is a phrase a
     * warehouse manager will want to change — without invalidating every bookmark and every stored
     * group.
     */
    #[ORM\Column(length: 48)]
    private string $code = '';

    #[ORM\Column(length: 120)]
    private string $label = '';

    /**
     * The `inventory_detail.status` the stock comes OUT of.
     *
     * NULL means the units enter the ledger from outside it, or — when $reversal is set — that the
     * source is whatever the reversed entry landed in. See defaults() for why those two cases share
     * a NULL rather than being given separate columns.
     */
    #[ORM\Column(name: 'from_status', length: 16, nullable: true)]
    private ?string $fromStatus = null;

    /** The `inventory_detail.status` the stock goes INTO. NULL would mean it leaves the ledger. */
    #[ORM\Column(name: 'to_status', length: 16, nullable: true)]
    private ?string $toStatus = null;

    /**
     * This reason is applied by pointing at an earlier movement and undoing it, rather than by
     * naming stock directly (#585).
     *
     * A flag rather than a fifth status value, because it changes WHERE THE SIDES COME FROM and not
     * what they are: the reversal is the original movement with its sides swapped, bounded by
     * `original.quantity − SUM(already reversed)`. Dynamics ties a reversal to the original Item
     * Ledger Entry for the same reason — the cost has to reverse to the account it came out of.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $reversal = false;

    /**
     * The G/L account this class of adjustment posts to. Nothing reads it yet.
     *
     * Carried from the start because it is the reason the reason had to become a row at all: a
     * free-text sentence can never be mapped to an account, and adding the column later would mean
     * classifying whatever text had accumulated in the meantime. Empty here costs nothing; the
     * alternative costs a data migration over somebody's production ledger.
     */
    #[ORM\Column(name: 'gl_account', length: 64, nullable: true)]
    private ?string $glAccount = null;

    /** What the operator reads under the reason on the screen. Editable, like the label. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $help = null;

    /**
     * The order the list is offered in — NOT alphabetical.
     *
     * The two reasons that put stock BACK are first, because they are the ones a hurried operator
     * gets wrong: "stock found" and "reverse a write-off" have the same physical trigger (a box
     * turned up) and opposite effects on the books, and the screen has to put the choice in front
     * of somebody rather than bury it between "Lost" and "Scrapped".
     */
    #[ORM\Column(name: 'sort_key', options: ['default' => 0])]
    private int $sortKey = 0;

    /**
     * Deactivated rather than deleted, always.
     *
     * A group written last year points at this row, and a reason that vanishes turns those groups
     * into unlabelled history. Deactivating takes it off the form and leaves every reference
     * readable — the same argument that keeps a detail row at quantity 0 rather than deleting it.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = trim($code); return $this; }
    public function getLabel(): string { return $this->label !== '' ? $this->label : $this->code; }
    public function setLabel(string $label): self { $this->label = trim($label); return $this; }
    public function getFromStatus(): ?string { return $this->fromStatus; }
    public function setFromStatus(?string $fromStatus): self { $this->fromStatus = $fromStatus; return $this; }
    public function getToStatus(): ?string { return $this->toStatus; }
    public function setToStatus(?string $toStatus): self { $this->toStatus = $toStatus; return $this; }
    public function isReversal(): bool { return $this->reversal; }
    public function setReversal(bool $reversal): self { $this->reversal = $reversal; return $this; }
    public function getGlAccount(): ?string { return $this->glAccount; }
    public function setGlAccount(?string $glAccount): self { $this->glAccount = $glAccount; return $this; }
    public function getHelp(): ?string { return $this->help; }
    public function setHelp(?string $help): self { $this->help = $help; return $this; }
    public function getSortKey(): int { return $this->sortKey; }
    public function setSortKey(int $sortKey): self { $this->sortKey = $sortKey; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    /**
     * Stock arriving in the ledger with no source row of its own.
     *
     * `$reversal` is excluded explicitly and that exclusion is load-bearing: a reversal also carries
     * a NULL `from_status`, but its source is a real row that an operator is about to name by
     * pointing at the entry that created it. Treating it as inbound would receive the units a second
     * time — inventing the very stock the reversal exists to give back — and would leave the
     * write-off standing.
     */
    public function isInbound(): bool
    {
        return !$this->reversal && $this->fromStatus === null;
    }

    /**
     * The operator has to name which existing rows the stock comes off.
     *
     * True for every reason with a real `from_status`. Whether they name them one by one or let
     * AutomaticSourcePicker choose is a separate question answered by the product's TrackingPolicy,
     * not by the reason — see AdjustmentController::namesItsSource().
     */
    public function isOutbound(): bool
    {
        return !$this->reversal && $this->fromStatus !== null;
    }

    /**
     * The movement type this reason writes, derived rather than chosen (#585).
     *
     * The old form offered all eight types in a dropdown that had nothing to do with what the form
     * did, so a write-off could be filed as a `receipt` and the ledger's type filter meant nothing.
     * There is exactly one right answer per reason and it follows from the sides:
     *
     *  - nothing on the from side and something on the to side is a RECEIPT — stock entering;
     *  - nothing on the to side would be a PICK — stock leaving the ledger outright. None of the
     *    eight does that (every one has a destination), and the branch is kept because the mapping
     *    should answer for any row an admin configures, not only for the eight;
     *  - anything else is a STATUS_CHANGE, which is what all six of the physical-state reasons are.
     *
     * A reversal is a STATUS_CHANGE even though its stored `from_status` is NULL, because its real
     * source is the reversed entry's destination row. Calling it a receipt would be the same
     * mistake isInbound() guards against, one layer down.
     */
    public function movementType(): string
    {
        if ($this->reversal) {
            return InventoryMovementGroup::TYPE_STATUS_CHANGE;
        }

        if ($this->toStatus === null) {
            return InventoryMovementGroup::TYPE_PICK;
        }

        if ($this->fromStatus === null) {
            return InventoryMovementGroup::TYPE_RECEIPT;
        }

        return InventoryMovementGroup::TYPE_STATUS_CHANGE;
    }

    /**
     * Whether the lot list should start at batches that are already at or past their date.
     *
     * Derived from the destination rather than stored as a flag, so a reason someone adds that
     * writes `expired` gets the behaviour without anybody remembering to tick a box. "That is what
     * the reason is for" is the argument for deriving it: a spoilage entry that made the operator
     * scroll past thirty fresh batches to find the one that went off would be the movement editor
     * again in miniature.
     */
    public function prefersExpiredLots(): bool
    {
        return $this->toStatus === InventoryDetail::STATUS_EXPIRED;
    }

    /**
     * Which recomputed bucket moves, and which way — for the screen, and for nothing else.
     *
     * Stated as a sentence the operator reads BEFORE submitting, because the two reasons that
     * differ only here ("stock found" and "reverse a write-off") are otherwise indistinguishable
     * from where they are standing. Recomputed by StockMovementService from the detail rows either
     * way; this never feeds a write.
     */
    public function bucketEffect(): string
    {
        if ($this->reversal) {
            return 'write_off −';
        }

        $effect = static function (?string $status, string $sign): ?string {
            if ($status === null || $status === InventoryDetail::STATUS_AVAILABLE) {
                return null;
            }

            return match (true) {
                in_array($status, InventoryDetail::writeOffStatuses(), true) => 'write_off ' . $sign,
                in_array($status, InventoryDetail::quarantineStatuses(), true) => 'quarantine ' . $sign,
                default => null,
            };
        };

        return $effect($this->toStatus, '+')
            ?? $effect($this->fromStatus, '−')
            ?? ($this->fromStatus === null ? 'received +' : 'received −');
    }
}
