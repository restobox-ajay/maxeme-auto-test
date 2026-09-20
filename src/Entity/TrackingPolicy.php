<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackingPolicyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What identity a unit of a product must carry, **declared** rather than inferred from history
 * (#573).
 *
 * ## The problem it replaces
 *
 * Before this row, "is this product lot-tracked" was answered by archaeology: does an
 * `inventory_lot` row exist for it, does any detail row carry a serial. Inference from history gets
 * every interesting case backwards — a lot-tracked product that has never been received reads as
 * not-lot-tracked, one bad row makes a product look serialised forever, and receiving cannot require
 * a lot because nothing knows one is expected. `DimensionalImportProvider` carried two helpers
 * called `carriesLots()` and `carriesSerials()` that did exactly that, and they are what this
 * replaces.
 *
 * ## Why a named policy and not columns on product_core
 *
 * The day a rule changes you edit four rows, not twenty thousand. It is Zoho's semantics (None /
 * Batch / Serial, exclusive, expiry riding on the batch) in Dynamics BC's shape (a named Item
 * Tracking Code with direction flags).
 *
 * ## The rules the fields encode
 *
 * **Serial and lot are exclusive — in the logic, not in the schema.** `inventory_detail` keeps both
 * `lot_id` and `serial`, so allowing both together later (the Dynamics AX case: lot ABC containing
 * serials 001–500) is a policy change and not a migration. That is why `mode` is one value rather
 * than two booleans.
 *
 * **Expiry is its own Yes/No.** `requires_expiry` holds in every mode and needs neither capture
 * direction. It rides on the lot in lot mode (`inventory_lot.expiry`) and on the serial's own stock
 * row in serial mode.
 *
 * **In and out are independent.**
 *
 * | track_in | track_out | |
 * |---|---|---|
 * | ✗ | ✗ | not tracked |
 * | ✓ | ✗ | capture at receipt — warranty lookup, no matching at pick |
 * | ✗ | ✓ | assign at ship — rental, service contracts |
 * | ✓ | ✓ | full traceability — recall |
 *
 * The in-only case is what Dynamics calls Active-but-not-Physical, and it is the common one.
 *
 * **Tracking never blocks.** An unidentified unit takes the configured sentinel and the row goes
 * through, in lot mode and in serial mode alike. A warehouse mid-transition has real stock and no
 * codes for any of it, and refusing those rows makes the system unusable on exactly the day they
 * need it. Control is a worklist — `inventory_detail.expect_resolution` — not a gate.
 *
 * ## Default
 *
 * `None`, and every existing product points at it. A product on the default policy never encounters
 * any of this, which is what keeps an instance that never opts in behaving exactly as it did before
 * this table existed.
 */
#[ORM\Entity(repositoryClass: TrackingPolicyRepository::class)]
#[ORM\Table(name: 'tracking_policy')]
#[ORM\UniqueConstraint(name: 'uniq_tracking_policy_name', fields: ['name'])]
class TrackingPolicy
{
    /** No identity is carried. The default, and what every product had before #573. */
    public const MODE_NONE = 'none';

    /** Units are identified by the batch they came from. Expiry, if any, rides on that batch. */
    public const MODE_LOT = 'lot';

    /** Every unit is identified individually, so a row carrying one never holds more than 1. */
    public const MODE_SERIAL = 'serial';

    /** The policy every product points at until someone opts one in. */
    public const DEFAULT_NAME = 'None';

    /**
     * What an unknown batch code is recorded as, unless a policy names something else.
     *
     * The value does not matter; that it is consistent and greppable does, because the admin's
     * worklist is one filter over everything still carrying one. It matches the sentinel bin
     * `DimensionalImportProvider` has written since #565 on purpose — the two read as one thing on
     * screen.
     */
    public const DEFAULT_SENTINEL = '[PENDING]';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_NONE, self::MODE_LOT, self::MODE_SERIAL];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** What an admin picks it by — 'None', 'Lot', 'Lot + expiry', 'Serial', or anything they name. */
    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 16, options: ['default' => self::MODE_NONE])]
    private string $mode = self::MODE_NONE;

    /**
     * The batch must carry an expiry date.
     *
     * Only meaningful while `mode = lot`: expiry is an attribute of the lot and there is nowhere
     * else for it to live. A hardware batch code identifies a production run and never expires,
     * which is why this is separate from the mode rather than implied by it.
     */
    #[ORM\Column(name: 'requires_expiry', options: ['default' => false])]
    private bool $requiresExpiry = false;

    /** Capture the identity when stock arrives. */
    #[ORM\Column(name: 'track_in', options: ['default' => false])]
    private bool $trackIn = false;

    /**
     * Capture the identity when stock leaves.
     *
     * Independent of $trackIn, and the two are genuinely different jobs — see the table on the class
     * docblock. Nothing on the sell side reads this yet (#573 is explicit that picking and shipping
     * are out of scope); it is declared here so the declaration exists in one place when they do.
     */
    #[ORM\Column(name: 'track_out', options: ['default' => false])]
    private bool $trackOut = false;

    /**
     * The label put on the unidentified inbound row, in place of a real batch code or serial.
     *
     * **A label, not an identity.** It marks one row as "these units are not identified yet"; it is
     * never a real lot code and never a real serial number. N unidentified units arriving together
     * are therefore ONE row of quantity N wearing this label, never N rows and never a suffixed
     * value like `PENDING-1`.
     *
     * **The same in both modes.** Whatever this reads, a lot-mode row and a serial-mode row record
     * it identically — see `isSentinelIn()` and InventoryDetail::setQuantity().
     *
     * **Nullable and blank-allowed.** Blank means the row simply carries NULL for that dimension,
     * which is already how `uniq_inventory_detail` COALESCEs it. NULL and a string are the same
     * shape of row: same quantity, same `expect_resolution`, only the column reads differently. A
     * value like `PENDING202608` is a cohort marker some clients will want; most leave it empty.
     */
    #[ORM\Column(name: 'sentinel_in', length: 64, nullable: true)]
    private ?string $sentinelIn = null;

    /** The same, on the way out. */
    #[ORM\Column(name: 'sentinel_out', length: 64, nullable: true)]
    private ?string $sentinelOut = null;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getMode(): string { return $this->mode; }

    /** Anything unrecognised falls back to `none` — the mode that needs nothing to mean something. */
    public function setMode(string $mode): self
    {
        $this->mode = \in_array($mode, self::modes(), true) ? $mode : self::MODE_NONE;

        return $this;
    }

    public function isRequiresExpiry(): bool { return $this->requiresExpiry; }
    public function setRequiresExpiry(bool $requiresExpiry): self { $this->requiresExpiry = $requiresExpiry; return $this; }
    public function isTrackIn(): bool { return $this->trackIn; }
    public function setTrackIn(bool $trackIn): self { $this->trackIn = $trackIn; return $this; }
    public function isTrackOut(): bool { return $this->trackOut; }
    public function setTrackOut(bool $trackOut): self { $this->trackOut = $trackOut; return $this; }
    public function getSentinelIn(): ?string { return $this->sentinelIn; }
    public function setSentinelIn(?string $sentinelIn): self { $this->sentinelIn = self::blankToNull($sentinelIn); return $this; }
    public function getSentinelOut(): ?string { return $this->sentinelOut; }
    public function setSentinelOut(?string $sentinelOut): self { $this->sentinelOut = self::blankToNull($sentinelOut); return $this; }

    /**
     * Whether $value is this policy's inbound sentinel — the label on the unidentified row rather
     * than an identity somebody captured.
     *
     * A blank sentinel names no value at all, so nothing matches it: the unidentified row carries
     * NULL in that case and is recognised by its NULL dimension plus `expect_resolution`, not by
     * this.
     */
    public function isSentinelIn(?string $value): bool
    {
        return $this->sentinelIn !== null && $value === $this->sentinelIn;
    }

    /** Nothing is tracked in either direction — the answer for the default policy. */
    public function isInert(): bool
    {
        return $this->mode === self::MODE_NONE || (!$this->trackIn && !$this->trackOut);
    }

    /** A batch code is expected on arrival. */
    public function tracksLotsInbound(): bool
    {
        return $this->mode === self::MODE_LOT && $this->trackIn;
    }

    /** A batch code is expected on the way out. */
    public function tracksLotsOutbound(): bool
    {
        return $this->mode === self::MODE_LOT && $this->trackOut;
    }

    /** A serial is expected on arrival. */
    public function tracksSerialsInbound(): bool
    {
        return $this->mode === self::MODE_SERIAL && $this->trackIn;
    }

    /** A serial is expected on the way out. */
    public function tracksSerialsOutbound(): bool
    {
        return $this->mode === self::MODE_SERIAL && $this->trackOut;
    }

    /**
     * A product on this policy carries an expiry date. Independent of the identity mode and of
     * both capture directions: it rides on the lot in lot mode and on the serial's own stock row
     * otherwise.
     */
    public function requiresExpiry(): bool
    {
        return $this->requiresExpiry;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->name !== '' ? $this->name : ('Tracking policy #' . (string) ($this->id ?? 0));
    }

    /** A human-readable summary of what this policy actually does, for the admin list. */
    public function describe(): string
    {
        if ($this->mode === self::MODE_NONE) {
            return 'No identity captured.';
        }

        $noun = $this->mode === self::MODE_LOT ? 'Batch' : 'Serial';
        $directions = match (true) {
            $this->trackIn && $this->trackOut => 'in and out — full traceability',
            $this->trackIn => 'in only — captured at receipt, not matched at pick',
            $this->trackOut => 'out only — assigned at ship',
            default => 'neither in nor out, so nothing is captured',
        };

        return sprintf('%s tracked %s.%s', $noun, $directions, $this->requiresExpiry ? ' Expiry date required.' : '');
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
