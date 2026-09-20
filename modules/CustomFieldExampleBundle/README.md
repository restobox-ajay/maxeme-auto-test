# CustomFieldExampleBundle

**Type:** Custom Field · **Name:** Custom Field Example · **Edit route:** none (no admin config)

## What it does

Registers a single custom field on `product`: `warranty_months`, a number field, visible on add, edit, and the product listing, and searchable. `WarrantyMonthsFieldSubscriber` (a `kernel.request` event subscriber) calls `App\Repository\CustomFieldDefinitionRepository::ensureBySlug()` on every main request to lazily register the field if it doesn't already exist — see [docs/bundles/custom-fields.md](../../docs/bundles/custom-fields.md).

All rendering, saving, and listing display for this field is handled generically by `App\Service\CustomFieldRenderer` — this bundle contains no rendering code of its own.

## How it's configured

No admin config screen — the field's slug, label, type, and visibility flags are fixed in code (`modules/CustomFieldExampleBundle/src/EventSubscriber/WarrantyMonthsFieldSubscriber.php`).

## External dependencies

None.

## See also

[docs/bundles/custom-fields.md](../../docs/bundles/custom-fields.md) — the "how to build a Custom Field bundle" guide, which uses this bundle as its worked example.
