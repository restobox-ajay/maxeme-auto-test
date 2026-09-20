# ShippingArrangementBundle

**Type:** Shipping · **Name:** Shipping Upon Existing Arrangement · **Edit route:** `admin_bundle_shipping_arrangement_index`

## What it does

Offers a single, free "Shipping Upon Existing Arrangement" option for companies or shipping
addresses that have been marked eligible from this bundle's own admin config screen.
`ArrangementShippingCalculator::supports()` returns `true` if *either* the cart's company or its
specific shipping address is flagged eligible (OR-semantics) — see
`modules/ShippingArrangementBundle/src/Shipping/ArrangementShippingCalculator.php`. It is tagged
`app.shipping_option` (normal tier), so it competes/coexists with other normal shipping bundles
exactly like any other calculator.

## How it's configured

Eligibility is managed centrally from **Bundles › Shipping › Existing Arrangement**
(`admin_bundle_shipping_arrangement_index`), which lists every company and its shipping addresses
with a checkbox each. Checking either a company or one of its addresses marks that scope eligible.

Storage goes through the generic `CustomFieldValue` table via a new `company_address` custom-field
object type (alongside the existing `product`/`company`/`order` types) — see
`CustomFieldDefinition::OBJECT_TYPE_COMPANY_ADDRESS`. Unlike most custom fields, this one is never
rendered through `CustomFieldRenderer`'s generic per-record form; this bundle owns its own editing
screen and reads/writes `CustomFieldValue` rows directly via `CustomFieldValueRepository`. The
`arrangement_shipping_eligible` field definition itself is lazily registered on both the `company`
and `company_address` object types by `ArrangementEligibilityFieldSubscriber` on `kernel.request`,
the same lazy-upsert pattern `CustomFieldExampleBundle\EventSubscriber\WarrantyMonthsFieldSubscriber`
uses.

## External dependencies

None.

## See also

[docs/bundles/shipping.md](../../docs/bundles/shipping.md) — the "how to build a Shipping bundle"
guide.

[docs/bundles/custom-fields.md](../../docs/bundles/custom-fields.md) — custom-field object types,
including `company_address` and the bundle-owned-editing-screen pattern this bundle uses instead of
`CustomFieldRenderer`.
