<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use App\Entity\ProductCore;
use App\Service\AppSettings;
use ProcurementBundle\Entity\ShortDatedReceipt;
use ProcurementBundle\Repository\ProductReceivingRuleRepository;

/**
 * What is wrong with a delivery's expiry date: it is dead, or it is too close (items 68 and 69).
 *
 * ## Two findings, one mechanism
 *
 * **Already expired** — `expiry < today`. Unconditional: no minimum has to be set for it to fire,
 * and a per-product exemption of 0 does not silence it. Added by item 69, because until then the
 * expiry date was checked against a minimum or not at all: `AbstractProcurementController::
 * calendarDate()` validates the FORMAT of a posted date and nothing else, and
 * `InventoryLot::isExpired()` is consulted only by `ExpireLotsCommand`, which sweeps stock that is
 * already on the shelf. So with the global minimum at its shipped default of 0, a pallet that
 * arrived dead booked in clean, counted as `available`, and stayed invisible as a problem until
 * that night's sweep took it away again.
 *
 * **Short of the minimum** — item 68's, below.
 *
 * Both are reported through one {@see ShelfLifeFinding}, one refusal, one reason box and one
 * `procurement_short_dated_receipt` row. A receiver takes one decision about one pallet.
 *
 * Many businesses will not accept goods whose expiry is closer than a preset time — a distributor
 * who takes stock with three weeks on it cannot sell it through a channel that takes six. So there
 * is a **global minimum**, and a **per-product override** of it.
 *
 * ## DAYS, not months
 *
 * Trade talks in months ("we need 6 months on it") and every dock enforces it in days, because
 * that is the only form the arithmetic has. Three reasons it is stored that way here:
 *
 *  1. **A month is not a length.** A "2 month" minimum is 59 days in February and 62 in July, so
 *     the same pallet with the same date passes in one month and fails in the next. Nobody can
 *     defend that to a vendor.
 *  2. **The two things being compared are dates.** `inventory_lot.expiry` is a date and the receipt
 *     has a date; their difference is a number of days and nothing else. Converting to months means
 *     picking a rounding rule, and a rounding rule is where "4 days short" becomes "0 months short".
 *  3. **The existing convention is already a day.** `expiry` is documented throughout #550 as the
 *     LAST USABLE DAY — `ExpireLotsCommand` sweeps on it, `InventoryLot::isExpired()` compares on
 *     it. A minimum in a different unit from the column it is measured against is a conversion
 *     waiting to be got wrong.
 *
 * The screens say so: the settings field is labelled in days and suggests 90 for three months.
 *
 * ## Three states, and the difference between two of them matters
 *
 * | where                                             | value  | means                             |
 * |---------------------------------------------------|--------|-----------------------------------|
 * | `app_setting.procurement_minimum_shelf_life_days`  | `0`    | no minimum anywhere — the default |
 * | `app_setting.procurement_minimum_shelf_life_days`  | `N`    | the global minimum                |
 * | `procurement_product_rule.minimum_shelf_life_days`  | `null` | **not set** — use the global      |
 * | `procurement_product_rule.minimum_shelf_life_days`  | `0`    | **no minimum at all** for this one|
 * | `procurement_product_rule.minimum_shelf_life_days`  | `N`    | this product's own, either way    |
 *
 * `null` and `0` on the override are different answers. A product exempted on purpose must STAY
 * exempt when the global changes, and a product nobody has an opinion about must follow it. There
 * is no way to express that in one value, which is why the column is nullable rather than
 * defaulted to zero.
 *
 * The global starts at **0**, for the same reason `SettingsController` starts the match tolerances
 * at zero: a setting that silently begins refusing things nobody asked it to refuse is discovered
 * six months later, on a dock, by somebody who cannot fix it.
 *
 * ## An absent rule row does not mean "nothing is required"
 *
 * That sentence is what item 67 was. It was true then because `procurement_product_rule` was the
 * ONLY place the requirement lived and nothing ever created a row. It is not true here: the answer
 * for a product with no row is the GLOBAL minimum, which is a real setting applying to every
 * product in the database. Nothing reads the override column except {@see self::minimumFor()},
 * which consults the global first — so a missing row is a missing OVERRIDE, never a missing rule.
 *
 * ## It warns; it does not block — both findings alike
 *
 * By the time anybody reads the date the pallet is on the dock. Refusing the RECORD does not refuse
 * the PALLET — it makes real stock invisible, and invisible stock still gets picked. So receiving
 * refuses only until somebody says WHY, and the saying is recorded as a {@see ShortDatedReceipt}.
 *
 * That reasoning applies to an already-expired pallet with at least as much force as to a
 * short-dated one: the dead goods are physically in the building whether or not a receipt says so,
 * and a warehouse that cannot record them is a warehouse that cannot return them either.
 */
final class MinimumShelfLife
{
    /**
     * The global minimum, in days, in `app_setting` — the same table and the same shape the match
     * tolerances use, written through ProcurementBundle's own settings screen.
     */
    public const SETTING_MINIMUM_DAYS = 'procurement_minimum_shelf_life_days';

    /** Off until somebody turns it on. See the class docblock. */
    public const DEFAULT_MINIMUM_DAYS = 0;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly ProductReceivingRuleRepository $rules,
    ) {
    }

    /** The global minimum in days. Zero, and anything unparseable, means no minimum. */
    public function globalMinimumDays(): int
    {
        $raw = trim((string) $this->settings->get(self::SETTING_MINIMUM_DAYS, (string) self::DEFAULT_MINIMUM_DAYS));

        return ctype_digit($raw) ? (int) $raw : self::DEFAULT_MINIMUM_DAYS;
    }

    /**
     * The minimum that applies to one product, and where it came from.
     *
     * @return array{days: int, source: string}
     */
    public function minimumFor(ProductCore $product): array
    {
        $override = $this->rules->ruleFor($product)->getMinimumShelfLifeDays();

        return $override === null
            ? ['days' => $this->globalMinimumDays(), 'source' => ShortDatedReceipt::SOURCE_GLOBAL]
            : ['days' => $override, 'source' => ShortDatedReceipt::SOURCE_PRODUCT];
    }

    /**
     * What is wrong with this line's date, or null when nothing is.
     *
     * ## Two triggers, and the second one is not a special case of the first (item 69)
     *
     *  - **already expired** — `expiry < today`. Fires whatever the global minimum says, whatever
     *    the per-product override says, **including when the override is 0**. That case is the whole
     *    reason this trigger exists separately: with the global at 0, which is the shipped default,
     *    an expired pallet used to produce no finding at all. It booked in clean, went into
     *    `available`, and stayed invisible as a problem until `ExpireLotsCommand` swept it out that
     *    night — so the warehouse read as holding sellable stock it did not hold.
     *
     *    A per-product 0 is an exemption from a THRESHOLD — how much life this buyer insists on —
     *    and cannot mean "accepts goods that are already dead". See
     *    {@see ShelfLifeFinding::isAlreadyExpired()}.
     *
     *  - **short of the minimum** — a minimum is set and the date is inside it. Unchanged.
     *
     * Both can be true at once and produce ONE finding, deliberately: one pallet, one decision, one
     * reason, one recorded row. {@see ShelfLifeFinding::describe()} says why the message leads with
     * the expiry when it does.
     *
     * ## The three ways there is no finding
     *
     *  - **no expiry on the line.** Nothing to measure. A product whose policy captures no expiry
     *    never reaches this at all, and one that does is refused earlier by the guard that demands
     *    the date — so a null here means the date genuinely does not apply.
     *  - **the date is today or later AND no minimum is set.** Nothing has been asked for and the
     *    goods are usable. `expiry` is the LAST USABLE DAY, so a delivery dated today has zero days
     *    left and is not expired — the same strict comparison `InventoryLot::isExpired()` and
     *    `ExpireLotsCommand` make, reached here through `daysBetween()` rather than restated.
     *  - **the date is comfortably out.** The whole point.
     */
    public function findingFor(
        ProductCore $product,
        ?\DateTimeImmutable $expiry,
        \DateTimeImmutable $receivedAt,
    ): ?ShelfLifeFinding {
        if (!$expiry instanceof \DateTimeImmutable) {
            return null;
        }

        // The minimum is resolved even when it turns out to be zero, so that a finding raised by the
        // expiry trigger alone still RECORDS which minimum was in force when somebody accepted it.
        // A row saying "no minimum applied" is a different and more useful fact than a row that
        // never asked.
        $minimum = $this->minimumFor($product);
        $remaining = self::daysBetween($receivedAt, $expiry);

        $expired = $remaining < 0;
        $short = $minimum['days'] > 0 && $remaining < $minimum['days'];

        if (!$expired && !$short) {
            return null;
        }

        return new ShelfLifeFinding($expiry, $remaining, $minimum['days'], $minimum['source']);
    }

    /**
     * Whole days from the day $from falls on to the day $to falls on.
     *
     * Both ends are flattened to midnight first, so a receipt booked at 16:00 against a date does
     * not lose a day to the clock — the comparison is between two CALENDAR days, which is what both
     * values mean. `expiry` is the last usable day, so a delivery expiring today has zero days left
     * and is still usable today; one expiring yesterday returns -1 and arrived expired.
     */
    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $start = new \DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00');
        $end = new \DateTimeImmutable($to->format('Y-m-d') . ' 00:00:00');

        return (int) $start->diff($end)->format('%r%a');
    }
}
