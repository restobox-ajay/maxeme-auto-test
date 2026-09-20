<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

/**
 * What one product needs captured when its goods arrive, as one object every screen reads.
 *
 * ## What this is NOT any more
 *
 * The first version of this class merged two models that both claimed to declare receiving
 * requirements — core's `tracking_policy` and this bundle's `procurement_product_rule` — and
 * carried a `contradictions()` method to name the states where the two disagreed. That whole
 * problem was deleted rather than described: `ProductReceivingRule` now DERIVES its batch, expiry
 * and serial answers from the tracking policy, so there is exactly one place each of those facts
 * lives and a disagreement is unrepresentable. Only the destination bin is still a stored column,
 * and the policy has no opinion about bins to disagree with.
 *
 * So this is now a presentation of one answer rather than a reconciliation of two: which boxes a
 * row should offer, what to say above them, and whether a blank may be declared rather than typed.
 * ReceivingService reads the rule directly — it is refusing, not rendering — and the two cannot
 * drift, because this is built from that same rule.
 *
 * ## Escapable and not
 *
 * A blank IDENTITY may be excused by the receiver declaring the units unidentified: that is #573's
 * warehouse-mid-transition case, where there is real stock and no codes for any of it, and the row
 * still takes the policy's `sentinel_in`, still carries `expect_resolution`, and still lands on the
 * tracking worklist. What item 67 changed is that it takes a positive act instead of an unnoticed
 * blank box.
 *
 * A blank BIN may not. Somebody set that column on this product to say these goods may not be put
 * away nowhere, and "somewhere in the warehouse" is not a place a picker can go.
 */
final class CaptureRequirement
{
    public const IDENTITY_NONE = 'none';
    public const IDENTITY_LOT = 'lot';
    public const IDENTITY_SERIAL = 'serial';

    public function __construct(
        /** What a unit of this product is identified by: none, lot or serial. */
        public readonly string $identity,
        /** A batch code is captured on arrival. */
        public readonly bool $lot,
        /** The batch must carry an expiry date. */
        public readonly bool $expiry,
        /** A serial is captured on arrival, one per unit. */
        public readonly bool $serial,
        /** The destination bin must be named. The one requirement that is still a stored column. */
        public readonly bool $location,
        /** The label an unidentified inbound row wears, or null for a blank dimension. */
        public readonly ?string $sentinel,
    ) {
    }

    /** Nothing is captured for this product at all. */
    public static function nothing(): self
    {
        return new self(self::IDENTITY_NONE, false, false, false, false, null);
    }

    public function capturesLot(): bool
    {
        return $this->lot;
    }

    public function capturesSerial(): bool
    {
        return $this->serial;
    }

    /**
     * An expiry box belongs on this row.
     *
     * Read on its own rather than `lot && expiry`, so that the reachable-but-incoherent case — a
     * `lot` policy with both directions unticked and `requires_expiry` set, which captures no batch
     * and still demands a date — at least RENDERS the box that the refusal asks for. Refusing on a
     * field a screen does not offer is the worst of both.
     */
    public function capturesExpiry(): bool
    {
        return $this->expiry;
    }

    /**
     * No identity and no bin is captured for this product, so a batch box and a serial box on its
     * row would both be boxes that mean nothing.
     *
     * The walkthrough's finding (4): the two boxes rendered identically on every line, including for
     * products whose policy captures neither, so a batch and a serial could be typed against a
     * product that tracks nothing at all and both were stored.
     */
    public function capturesNothing(): bool
    {
        return !$this->lot && !$this->serial && !$this->expiry && !$this->location;
    }

    /** Whether a blank may be declared rather than typed. The identity, never the bin. */
    public function unidentifiedMayBeDeclared(): bool
    {
        return $this->lot || $this->serial;
    }

    /** The noun for what a unit of this product is identified by, for a sentence. */
    public function identityNoun(): string
    {
        return match ($this->identity) {
            self::IDENTITY_LOT => 'batch code',
            self::IDENTITY_SERIAL => 'serial',
            default => 'nothing',
        };
    }

    /**
     * What this row needs, in a sentence, for the receiver looking at it.
     *
     * Stated on every row including the ones that need nothing, because "this product tracks
     * nothing" is an answer a receiver wants and the silence that used to be there was read as
     * "nobody has told me".
     */
    public function describe(): string
    {
        if ($this->capturesNothing()) {
            return 'Tracks nothing — no batch code and no serial are recorded for this product.';
        }

        $parts = [];

        if ($this->serial) {
            $parts[] = 'a serial per unit, so one line per unit';
        }

        if ($this->lot) {
            $parts[] = 'a batch code';
        }

        if ($this->expiry) {
            $parts[] = $this->lot
                ? 'the expiry on that batch (the last usable day)'
                : 'an expiry date — though its tracking policy captures no batch inbound for one to belong to';
        }

        if ($this->location) {
            $parts[] = 'a destination bin';
        }

        return 'Needs ' . self::sentence($parts) . '.';
    }

    /** @param list<string> $parts */
    private static function sentence(array $parts): string
    {
        if (\count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }
}
