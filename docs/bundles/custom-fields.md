# Building a Custom Field bundle

Custom Field bundles are a different shape from every other bundle type in this doc: **there's no interface to implement.** A Custom Field bundle just registers a field definition once, and the generic renderer handles displaying it, saving it, and (if configured) showing it in listings and making it searchable — for free, on any of the five supported object types (`product`, `company`, `order`, `company_address`, `product_category`).

## How it works

Call `App\Repository\CustomFieldDefinitionRepository::ensureBySlug()` (`src/Repository/CustomFieldDefinitionRepository.php`) once to register your field:

```php
public function ensureBySlug(string $objectType, string $slug, array $seedData): CustomFieldDefinition
```

`$seedData` accepts: `label` (required), `fieldType` (one of `CustomFieldDefinition::FIELD_TYPE_TEXT` / `_TEXTAREA` / `_NUMBER` / `_CHECKBOX` / `_SELECT`, default `TEXT`), `options` (string[], for `_SELECT`), `visibleOnAdd` / `visibleOnEdit` (default `true`), `visibleOnListing` (default `false`), `searchable` (default `false`), `source` (your bundle's namespace — needed for the Active/Inactive kill-switch, see below), `sortOrder` (default `0`).

It's a lazy upsert keyed on `(objectType, slug)` — call it as many times as you like (e.g. on every request) and it's a no-op after the first successful insert. This is why it's typically called from a kernel request listener rather than some one-time install hook — there's no separate "bundle install" step in this app, so "ensure my field exists" runs opportunistically on request.

## Where to call it from

The shipped pattern is a `kernel.request` event subscriber. See `CustomFieldExampleBundle\EventSubscriber\WarrantyMonthsFieldSubscriber` (`modules/CustomFieldExampleBundle/src/EventSubscriber/WarrantyMonthsFieldSubscriber.php`):

```php
final class WarrantyMonthsFieldSubscriber implements EventSubscriberInterface
{
    public const SOURCE = 'CustomFieldExampleBundle';
    public const SLUG = 'warranty_months';

    public function __construct(private readonly CustomFieldDefinitionRepository $definitionRepo) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->definitionRepo->ensureBySlug(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, self::SLUG, [
            'label' => 'Warranty (months)',
            'fieldType' => CustomFieldDefinition::FIELD_TYPE_NUMBER,
            'visibleOnAdd' => true,
            'visibleOnEdit' => true,
            'visibleOnListing' => true,
            'searchable' => true,
            'source' => self::SOURCE,
        ]);
    }
}
```

No explicit service tag is needed for this — `EventSubscriberInterface` is picked up by Symfony's standard autoconfiguration (`_defaults: autoconfigure: true` in `config/services.yaml`), same as any other event subscriber in the app.

## The renderer: CustomFieldRenderer

`App\Service\CustomFieldRenderer` (`src/Service/CustomFieldRenderer.php`) is the generic service that does all the actual work, for every registered field across every object type — a bundle never renders or persists its own field HTML:

- `renderFields(string $objectType, ?object $entity, string $context)` — renders the HTML for every definition matching `$objectType`, respecting `visibleOnAdd`/`visibleOnEdit` for the given `$context` (`CustomFieldRenderer::CONTEXT_ADD` / `CONTEXT_EDIT`) and skipping definitions from an Inactive bundle.
- `saveFromRequest(string $objectType, object $entity, Request $request)` — reads `custom_field[...]` request keys and persists each value.
- `renderListingFragment(ProductCore $product)` — renders the subset of fields marked `visibleOnListing`.

It already knows how to render each `fieldType` (text input, textarea, number input, checkbox, select) — that's why there's no interface for a bundle to implement here; a bundle only supplies the field's metadata, not its markup.

Consumed generically from `Controller/Admin/ProductController.php`, `Controller/Admin/CompanyController.php`, `Controller/Admin/OrderController.php`, and `Controller/Admin/CategoryController.php` — any bundle registering a field against `product`, `company`, `order`, or `product_category` shows up in all the relevant admin forms without those controllers needing bundle-specific code.

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. The `source` you pass into `ensureBySlug()`'s `$seedData` must match your descriptor's `getSource()` — `CustomFieldRenderer` checks `BundleStatusRepository::isActive($definition->getSource())` per-definition and silently drops fields from an Inactive bundle everywhere (rendering, listing, search) without deleting the stored data.

## Example: CustomFieldExampleBundle

The whole bundle is two files: `WarrantyMonthsFieldSubscriber` (above) and `CustomFieldExampleBundleDescriptor`. Its `config/services.yaml` only tags the descriptor — the subscriber needs no explicit tag:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    CustomFieldExampleBundle\:
        resource: '../src/'

    CustomFieldExampleBundle\Bundle\CustomFieldExampleBundleDescriptor:
        tags: ['app.bundle_descriptor']
```

It registers a single `warranty_months` number field on `product`, visible on add/edit/listing and searchable. There's no admin config screen (`getEditRoute()` returns `null`) — the field's shape is fixed in code, not admin-editable.

## When a bundle owns its own editing screen instead

Everything above assumes `CustomFieldRenderer` renders and saves the field for you inside someone else's form (the product/company/order admin page). That's not the only valid shape: a bundle can instead call `CustomFieldValueRepository::getValue()` / `setValue()` (`src/Repository/CustomFieldValueRepository.php`) directly from its own controller when it has a dedicated config screen — storage goes into whichever of the five `custom_field_value_*` tables matches the definition's object type (each FK'd to its target entity — see `App\Entity\CustomFieldValue*`), `CustomFieldValueRepository` picks the right one for you, only the editing surface is bundle-owned, so `CustomFieldRenderer` is never wired into `CompanyController`'s forms for that field.

`ShippingArrangementBundle` (`modules/ShippingArrangementBundle/`) is the example: its `arrangement_shipping_eligible` checkbox field is registered on both `company` and `company_address` the normal `ensureBySlug()` way (`visibleOnAdd`/`visibleOnEdit`/`visibleOnListing` are irrelevant since `CustomFieldRenderer` never touches this field — set them `false`), but `ArrangementShippingConfigController`'s own page — one central table listing every company and its addresses with checkboxes — reads and writes the value directly via `CustomFieldValueRepository`, following the same CSRF-protected form-post pattern as `FeeUserDefinedBundle\Controller\UserDefinedFeeController::edit()`. Reach for this shape when eligibility (or any per-record flag) needs a single admin screen surveying *every* record at once, rather than being edited one record at a time from each record's own form.
