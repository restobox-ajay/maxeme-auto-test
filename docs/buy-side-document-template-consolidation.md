# Buy-side document template consolidation — Purchase Order + Bill

## Status: done (this pass)

Steps 1–6 below are complete and each landed with its own targeted Cest run green, plus a
235-test broad sweep across every screen that shares `_nav.html.twig` (RFQ, receiving, vendor,
debit memo, exceptions, AP aging, vendor returns) at the end, all passing. The one item
deliberately NOT done is listed under "Follow-up" at the bottom — it needs a data-model
decision, not a template one.

## Why this exists

The sell side got caught calling things "shared template" that were separate files that merely
looked alike. The rule going forward, stated plainly: **the default is one shared template file.
A difference is an exception, and every exception needs a stated domain reason.** Not the other
way around.

This file is the plan for forcing the four buy-side screens — PO edit, PO view, Bill edit, Bill
view — onto that rule, the same way the sell side's `commercial_document_edit.html.twig` /
`commercial_document_view.html.twig` frames already do for Order / Estimate / Invoice. It is
written before any of the work below is done, and updated as each step lands.

## Audit findings (verified by reading files, not by name-matching)

**The single biggest fact**: PO edit/view already extend the shared frames
(`commercial_document_edit.html.twig` / `commercial_document_view.html.twig`) — the same base
Invoice/Order/Estimate use. Bill edit/view extend the raw `admin/_main/layout.html.twig` and never
joined. The PO templates' own docblocks call this "Phase 1... PurchaseOrder joining it," with
Bill left for later. Later is now.

**What is genuinely shared today** between PO and Bill (real `include`, confirmed byte-identical,
not just similar): `_purchase_form_styles.html.twig` (CSS only), `_product_field.html.twig` (the
product picker), `_bad_parent_notice.html.twig`. Nothing else.

**What looks shared but isn't** — each of these is 2-4 independent hand-written copies today:
line-item table rows (edit and view), totals footers, the add-line/charge toolbar (the one macro
built for this, `_purchase_charge_row.html.twig`, is only called by PO — Bill hand-rolls its own),
address/vendor panels, flash-message rendering, and the tax-province explanatory paragraph.

**Legitimate domain differences** (not to be forced together): PO has `expectedDate`,
`paymentTerm`, a frozen `vendorAddress` (order-to), a required warehouse, no payments. Bill has
`dueDate`, a frozen `remitToAddress`, an optional PO link, a `payments` collection. Line entities
differ similarly (`quantityOrdered`+`quantityReceived` vs a single `quantity`).

**A deeper divergence found while planning the totals/charges unification, flagged rather than
silently changed**: PO's line save matches existing rows by `lines[i][id]` and updates in place;
`VendorBillController::save()` deletes every line and rebuilds fresh on every save. PO's manual
charges are one unified `charge_lines[]` (freight/fee/tax together, via
`_purchase_charge_row.html.twig`'s macro); Bill's are a separate `charges[]` (freight/fee only)
plus one single manual-tax box (`vendor_tax_label`/`vendor_tax`). This is a save-logic /
data-model difference, not a template one — unifying it means changing how a bill is saved, which
is out of scope for a template-consolidation pass and needs its own decision. It is called out
below as a follow-up, not merged.

## Target architecture

Both `purchase_order_edit.html.twig` and `bill_edit.html.twig` extend
`commercial_document_edit.html.twig` and fill its blocks — matching the pattern already proven by
`order/form.html.twig`, `estimate/form.html.twig`, `invoice/create.html.twig`. Both
`purchase_order_detail.html.twig` and `bill_detail.html.twig` extend
`commercial_document_view.html.twig` the same way.

New shared partials (one file, included by both PO and Bill, parameters/slots for the real
differences — never `{% if document == 'bill' %}`):

| File | Used by | Parameters / slots |
|---|---|---|
| `_purchase_flash_messages.html.twig` | PO edit, PO view (via action-bar slot), Bill edit, Bill view (via `_nav.html.twig`) | none — reads `app.flashes()` directly, exactly as the three hand copies did |
| `_purchase_tax_province_note.html.twig` | PO edit, PO view, Bill edit, Bill view | `documentNoun` ('order'/'bill'), `isDraft`, `freezeVerb` ('issued'/'approved'), `taxProvince`, `warehouse` (name+province, nullable), `idAttr`. Bill's extra Disputed-state remedy stays in `bill_detail.html.twig` itself — PO has no Disputed status, so that branch is a real exception, not folded into the shared file. |
| `_purchase_line_row_edit.html.twig` | PO edit, Bill edit | index/name, sku/vendorSku fields, qty field (name + value + optional floor-badge), unit-cost field (name + value + optional readonly-badge), `taxClasses` map (defaults to bare E/G/S, so PO's rendered options are byte-identical to today; Bill passes its labelled map), perLineTax/subtotal display cells, `leadingCellsTemplate` slot (Bill's "Against PO line" column), `actionsTemplate` slot (Bill's drop button; PO has none) |
| `_purchase_line_row_view.html.twig` | PO view, Bill view | a `columns` list, the same mechanism `sales_line_row.html.twig` uses, since PO view's columns (Ordered/Received/Outstanding) and Bill view's (single Quantity) are a real, not cosmetic, difference |

Left **not** merged, with the reason stated in each file's own docblock: the charge-entry table
and its totals math (PO's `charge_lines[]`+macro vs Bill's `charges[]`+manual-tax-box), because
unifying it is a save-logic change, not a template one (see "deeper divergence" above). Follow-up
item, not this pass.

## Execution order (each step ends with targeted Cests, not the full suite)

1. Migrate `bill_edit.html.twig` onto `commercial_document_edit.html.twig` — move existing markup
   into blocks byte-for-byte, exactly as `purchase_order_edit.html.twig` already did. No behavior
   change.
   Tests: `VendorBillFormParityCest`, `DisputedBillIsNotADeadEndCest`,
   `BillEditProvinceSentenceReEvaluatesCest`, `PurchaseCreateScreensSayWhenAParentIdIsBadCest`,
   `WarehouseAddressDerivesBillTaxProvinceCest`.
2. Migrate `bill_detail.html.twig` onto `commercial_document_view.html.twig`, same way.
   Tests: `VendorBillParityCest`, `BillApproveGuardIsRealCest`, `VendorBillOverBillingCest`,
   `VendorBillPaymentsCest`, `DisputedBillIsNotADeadEndCest`.
3. Extract `_purchase_flash_messages.html.twig`; point `_nav.html.twig`, PO edit's `form_head`, and
   `_document_action_bar.html.twig` at it. Byte-identical output.
   Tests: run 1-2's list again plus `ProcurementPurchaseOrderSendCest`.
4. Extract `_purchase_tax_province_note.html.twig`; point all four screens at it.
   Tests: `PurchaseOrderEditProvinceSentenceReEvaluatesCest`,
   `PurchaseOrderProvinceSentenceReEvaluatesCest`, `BillEditProvinceSentenceReEvaluatesCest`,
   `WarehouseAddressDerivesPurchaseOrderTaxProvinceCest`, `WarehouseAddressDerivesBillTaxProvinceCest`.
5. Extract `_purchase_line_row_edit.html.twig`; point PO edit and Bill edit at it.
   Tests: `PurchaseOrderEditableUntilTerminalCest`, `PurchaseProductPickerCest`,
   `VendorBillFormParityCest`, `VendorBillOverBillingCest`.
6. Extract `_purchase_line_row_view.html.twig`; point PO view and Bill view at it.
   Tests: `PurchaseOrderChargesAndTaxCest`, `VendorBillParityCest`.
7. Full targeted-suite re-run of every Cest listed above in one pass, plus
   `PurchaseOrderListFitsCest` and `VendorBillPaymentMoveCest` as a broader sanity check (still not
   the full suite).

## Follow-up (explicitly not done in this pass, and why)

- Unify Bill's charge/tax entry onto PO's `charge_lines[]` model, or vice versa — a save-logic and
  data-model decision, not a template one. Doing it inside a template pass would risk the one
  category of bug this exercise exists to prevent: a silent change to how money is computed.
- Unify Bill's delete-and-rebuild line save with PO's match-by-id — same reasoning.
