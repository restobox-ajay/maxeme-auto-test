# Building an Injection Point bundle

An Injection Point bundle renders arbitrary HTML into a named slot somewhere in the app's templates — a banner, a note, a message — without needing to touch the template itself. Multiple bundles can target the same point; they're all rendered in priority order.

## The contract

Implement `App\Contract\Hook\InjectionPointProviderInterface` (`src/Contract/Hook/InjectionPointProviderInterface.php`):

```php
interface InjectionPointProviderInterface
{
    public function getPoint(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /** @param array<string, mixed> $context */
    public function render(array $context): string;
}
```

- `getPoint()` — which named slot this provider targets (see the list below).
- `getPriority()` — sort order among multiple providers targeting the same point (ascending — lower renders first).
- `getSource()` — the bundle's root PHP namespace segment, used to check the bundle-level Active/Inactive kill-switch (see below). This is a separate concern from `getPriority()`/`getPoint()` and exists specifically for that toggle check.
- `render(array $context)` — returns the HTML to inject; `$context` is whatever the call site passed (see each point's `injection_point()` call in the templates for what's available).

## The tag

```yaml
# modules/MyInjectionPointBundle/config/services.yaml
MyInjectionPointBundle\Hook\MyMessageProvider:
    tags: ['app.injection_point']
```

## The consumer: InjectionPointExtension

`App\Twig\InjectionPointExtension` (`src/Twig/InjectionPointExtension.php`) registers the `injection_point(name, context)` Twig function used throughout the templates:

```php
public function render(string $point, array $context = []): string
{
    $matching = [];
    foreach ($this->providers as $provider) {
        if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
            $matching[] = $provider;
        }
    }

    usort($matching, fn ($a, $b) => $a->getPriority() <=> $b->getPriority());

    $html = '';
    foreach ($matching as $provider) {
        $html .= $provider->render($context);
    }

    return $html;
}
```

Note this checks `$this->bundleStatusRepo->isActive($provider->getSource())` directly — not `isActiveForInstance()` like the other resolvers — because `getSource()` is already given explicitly on the interface rather than derived from the class name. An Inactive bundle's providers are filtered out entirely; nothing from them renders at any point.

## The real injection points in use

The full authoritative list of point names actually called from templates today (confirmed via `grep -rn "injection_point(" templates/ modules/*/templates/`):

| Point | Called from | Context passed |
|---|---|---|
| `product_short_description_after` | `templates/customer/catalog/detail.html.twig` | `{product}` |
| `product_description_after` | `templates/customer/catalog/_products.html.twig` | `{product}` |
| `product_long_description_after` | `templates/customer/catalog/detail.html.twig` | `{product}` |
| `product_price_after` | `templates/customer/catalog/detail.html.twig`, `templates/customer/catalog/_products.html.twig` | `{product}` |
| `product_before_add_to_cart` | `templates/customer/catalog/detail.html.twig` | `{product}` |
| `cart_before_checkout` | `templates/customer/cart/index.html.twig` | `{cart, company}` |
| `invoice_note` | `templates/admin/invoice/invoice.html.twig` (the customer's downloaded copy is the same template, rendered with `is_pdf`) | `{order, invoice, isPdf}` — `order` is the invoice's sales order and may be `null` |
| `catalog_before_products` | `templates/customer/catalog/index.html.twig` | `{}` |
| `customer_login_after_card` | `templates/customer/auth/login.html.twig` | `{}` |
| `admin_order_detail_info` | `templates/admin/order/detail.html.twig` | `{order}` |
| `customer_order_detail_info` | `templates/customer/order/detail.html.twig` | `{order}` |
| `customer_head_top` | `templates/customer/_main/layout.html.twig` (overrides `base.html.twig`'s `head_extra` block, near the end of `<head>`) | `{}` |
| `customer_body_end` | `templates/customer/_main/layout.html.twig` (overrides `base.html.twig`'s `body_end` block, right before `</body>`) | `{}` |

`product_price_after` is called from two templates (product detail page and the catalog grid) but is the same point name — a provider targeting it renders in both places. There is no registry of point names beyond the `injection_point()` calls themselves; if you're adding a new call site, that immediately becomes a new available point.

## Optional: an admin sidebar entry

If your bundle has its own admin config/CRUD screen (like NewsBundle's `/admin/bundles/news`), implement `App\Contract\Hook\InjectionPointMenuItemInterface` (`src/Contract/Hook/InjectionPointMenuItemInterface.php`) and tag it `app.injection_point_menu_item`:

```php
interface InjectionPointMenuItemInterface
{
    public function getLabel(): string;
    public function getRoute(): string;
}
```

This is the same mechanism `ShippingMenuItemInterface`/`FeeMenuItemInterface`/`TaxMenuItemInterface`/`PaymentMenuItemInterface` already use for their own categories (see [shipping.md](shipping.md)) — `App\Twig\AdminBundlesNavExtension` collects all tagged instances and exposes them to `templates/admin/_main/layout.html.twig` as `injection_point_menu_items()`, rendered under a "Custom Page" sub-section in the admin sidebar's Bundles group. The section only appears if at least one bundle has registered an item, same as the other categories. Without this, your bundle is still reachable — just via Bundle Management → Edit — this only adds a shortcut.

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. Its `getSource()` should return the same string as your `InjectionPointProviderInterface::getSource()` implementation — both feed the same `BundleStatusRepository` lookup.

## Example: InjectionPointExampleBundle

`CartCheckoutMessageProvider` (`modules/InjectionPointExampleBundle/src/Hook/CartCheckoutMessageProvider.php`):

```php
final class CartCheckoutMessageProvider implements InjectionPointProviderInterface
{
    public function getPoint(): string { return 'cart_before_checkout'; }
    public function getPriority(): int { return 10; }
    public function getSource(): string { return 'InjectionPointExampleBundle'; }

    public function render(array $context): string
    {
        return '<p class="injection-point-example-message">Example bundle content: your order will be reviewed before shipping.</p>';
    }
}
```

It targets `cart_before_checkout`, ignores the passed `$context` entirely (it doesn't need `cart` or `company` for a static message), and returns a fixed paragraph. Its `config/services.yaml` tags this one service `app.injection_point` and the descriptor `app.bundle_descriptor` — there's no admin config screen for this bundle, so `getEditRoute()` returns `null`.
