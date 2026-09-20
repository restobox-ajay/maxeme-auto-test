<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether a product may be sold and counted (queue item 9).
 *
 * Before this enum the legal values of `product_core.status` existed only as whatever code
 * happened to compare against: eighteen comparisons with the literal 'Active' across src/,
 * modules/ and templates/, two places listing ['Active', 'Inactive'] as the choices, and a bare
 * setStatus(string) on the entity that would accept anything at all. "Draft" could not be added
 * to that safely, because nothing said what the set was — so this enum is the set, and every
 * write now goes through a named verb on ProductCore rather than a string setter.
 *
 * ProductCore.status stays a plain string column, NOT enum-typed at the database level, for the
 * same reason SalesOrder.status does: rows carrying a value this enum does not know must still
 * hydrate rather than blow up on load. `product_core` is deployed and holds real rows written
 * before anything constrained them — the CSV import wrote the file's own `status` cell straight
 * into the column until now, so a production row may say anything the spreadsheet said.
 * getStatusEnum() absorbs that by returning null, and the screens say so out loud instead of
 * quietly redisplaying such a row as Active.
 *
 * @see \App\Entity\ProductCore for the verbs, and why there is no setStatus().
 */
enum ProductStatus: string
{
    /**
     * Sellable and countable. The only status a customer may buy at, the only one the catalogue
     * and the dashboard's product count include, and what every pre-existing `= 'Active'`
     * comparison in the codebase was already asking for.
     */
    case Active = 'Active';

    /**
     * Withdrawn from the catalogue. A deliberate judgement — an admin retiring a line, or an
     * import's deactivate-missing sweep finding the SKU gone from the file. The product and its
     * history stay; it is simply not for sale.
     */
    case Inactive = 'Inactive';

    /**
     * Not yet fit to transact, and waiting on a person (queue item 9).
     *
     * The state a product lands in when something the app needs in order to be sure what a
     * quantity of it MEANS could not be resolved — the case that prompted this enum being an
     * import whose unit column held a pack size ('12/Case', 'bag of 50', 'BAG') where a unit of
     * measure was expected, leaving `unit_id` NULL.
     *
     * The reason it is a status and not a flag is that a flag is inert. Business Central will not
     * let an item's base unit change once a ledger entry has posted against it; the workaround for
     * a wrong base unit there is to block the item and create a new one. So stock moving at an
     * unknown unit converts a pending problem into a permanent one, and the product has to be
     * un-sellable — not merely marked — until somebody settles it.
     *
     * Draft is deliberately NOT Inactive. Inactive means "we decided not to sell this"; Draft
     * means "nobody has decided anything yet and there is work outstanding". Collapsing them
     * would put the escalation backlog inside a bucket nobody reads.
     */
    case Draft = 'Draft';

    /**
     * May a customer buy this, and does it count as stock we have?
     *
     * Only Active. Written as an identity test rather than "not Inactive" on purpose: the whole
     * defect class this enum exists to prevent is code that treats "not Active" as meaning
     * "Inactive", which stopped being true the moment a third value existed.
     */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    /**
     * The statuses a person may choose — on the product form, and in the list screen's status
     * filter. All three: a human both sends a product to Draft and takes it out again, and a
     * Draft product whose form offered only Active/Inactive would be silently activated by
     * anybody who saved an unrelated edit to it.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Active, self::Inactive, self::Draft];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
