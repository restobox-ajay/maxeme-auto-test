# Inventory calculation (design spec)

**Status:** the original design spec, kept as the record of what was asked for. It has since been
built, and #539 then added a fourth hold bucket on top of it, so the arithmetic below is one term
short of what the code does:

```
Available = Starting Inventory − Cart Hold − Sales Hold − Pending − Approved
```

`Sales Hold` is a sales order's uninvoiced remainder; `Pending` and `Approved` moved onto the
invoice. The authoritative status→bucket table for both documents is in
`docs/plans/2026-08-20-invoice-as-separate-entity.md`, under "Inventory: the Sales Hold bucket" —
read that rather than the triggers below, which describe the pre-#539 order-only model.

## Inventory columns

Stock is counted per WAREHOUSE, not per fulfillment region (#546). A customer picks a region and
the region resolves to the single warehouse serving it, so the two read the same today — but the
row is keyed by the building, which is where stock actually is.

For each warehouse, every product carries four sub-balances:

| Column | Meaning |
|---|---|
| Starting Inventory | The base stock count for that product/warehouse |
| Cart Hold | Quantity currently held by shoppers who have it in their cart (temporary) |
| Pending Orders | Quantity committed to orders that exist but aren't yet approved |
| Approved Orders | Quantity committed to approved orders |

**Available = Starting Inventory − Cart Hold − Pending Orders − Approved Orders**

- Customers only ever see **Available**.
- Admins see all four sub-balances plus **Available** on the Inventory admin page.

## Triggers and actions

1. **Order created** (not yet approved) → add its quantities to **Pending Orders**.
2. **Order approved** → add its quantities to **Approved Orders**; if the order was previously
   pending, deduct the same quantities from **Pending Orders** (the inventory is moving buckets, not
   being added twice).
3. **Order cancelled** → remove its quantities from whichever bucket currently holds them (Pending or
   Approved).
4. **Quote converted to order** → add its quantities to **Pending Orders**.
5. **Order edited**:
   - If the order is still Pending, adjust **Pending Orders** by the quantity delta.
   - If the order is Approved, adjust **Approved Orders** by the quantity delta.
6. The "adjusted amount" in every case above is the **difference** between the old and new quantity,
   not the full new quantity.
7. **Cart hold** — when a customer adds an item to their cart, hold that quantity in **Cart Hold** for
   a configurable duration (X seconds).
8. Any of the following actions **reset the hold timer**: adding another item to the cart, removing an
   item from the cart, visiting the cart page, or visiting the checkout page.
9. **Hold expiry** — once the timer runs out, the held item(s) are removed from the cart (the customer
   sees a notice on their next page load) and the corresponding quantity is deducted from
   **Cart Hold**.

## Cron recalculation

Because the running totals above are maintained incrementally across many trigger points, they can
drift out of sync. An hourly cron job recomputes the two time-sensitive sub-balances from source data
rather than trusting the running counters:

- **Cart Hold** = sum of all non-expired cart-hold rows for that product/warehouse.
- **Pending Orders** = sum of order-line quantities across every order currently in a pending state,
  for that product/warehouse.

(Approved Orders and Starting Inventory are not part of this reconciliation — they only change via the
explicit triggers above and CSV import.)

Every change to any inventory sub-balance — whether from a trigger or from the cron reconciliation —
is logged for audit purposes.

## Inventory CSV import

The importer has a checkbox: **"Clear all Approved Order column balance"**. It defaults to **checked
(YES)** — importing a fresh inventory count normally means starting the Approved Orders bucket over.

## Cart hold UI

- While a customer holds cart inventory, show a live countdown timer on the product and cart pages so
  the hold is obvious (client-side JS ticks it down in real time).
- Cart hold can be turned on/off globally via config.

## Maintaining cart hold without a long-running process

There's no long-lived background worker, so hold expiry can't be pushed from the server. Instead,
expired holds are swept **lazily, on read**: on every admin product-page load and every customer
product/cart/checkout page load, look up all cart-hold rows and reverse any that have expired. This
runs as one of the early request-lifecycle steps — after models/tables are loaded and the user is
authenticated, but early enough that every inventory number shown afterward on that page is already
correct.
