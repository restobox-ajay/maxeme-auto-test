# Building a Template Override bundle

A Template Override bundle replaces an entire template rendered by a core controller — not just an HTML fragment injected into one (that's what [Injection Points](injection-points.md) are for). Use this when a bundle needs to own the whole page, e.g. a fully custom customer login page.

## The contract

Implement `App\Contract\Bundle\TemplateOverrideProviderInterface` (`src/Contract/Bundle/TemplateOverrideProviderInterface.php`):

```php
interface TemplateOverrideProviderInterface
{
    public function getPoint(): string;

    public function getTemplate(): string;

    public function getPriority(): int;

    public function getSource(): string;

    public function getTemplateSource(): ?string;
}
```

- `getPoint()` — which override point this provider targets (see the list below).
- `getTemplate()` — the Twig template path to render instead of the core default, e.g. `@MyBundle/login.html.twig` (a bundle-namespaced template — see below).
- `getPriority()` — when more than one active bundle targets the same point, the highest priority wins (descending, unlike Injection Points' ascending render order — only one override can be "active" per point, there's no concatenation).
- `getSource()` — the bundle's root PHP namespace segment, used to check the bundle-level Active/Inactive kill-switch, same convention as Injection Points.
- `getTemplateSource()` — raw Twig source to render instead of loading `getTemplate()` from disk, or `null` to just use the file. This is how a bundle can offer an admin-editable page: store the source in the database (e.g. via `AppSettings`, same as `EmailTemplate` rows already do for emails in `AuthController`) and return it here; return `null` when no DB override is set so `getTemplate()`'s shipped file is used instead. Most providers that don't need admin editing just `return null;`.

## The tag

```yaml
# modules/MyBundle/config/services.yaml
MyBundle\TemplateOverride\MyLoginOverrideProvider:
    tags: ['app.template_override']
```

## The consumer: TemplateOverrideResolver

`App\Service\TemplateOverrideResolver` (`src/Service/TemplateOverrideResolver.php`) collects every tagged provider and resolves a point to a template:

```php
public function resolve(string $point, string $default): string
{
    $matching = [];
    foreach ($this->providers as $provider) {
        if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
            $matching[] = $provider;
        }
    }

    if ($matching === []) {
        return $default;
    }

    usort($matching, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());

    return $matching[0]->getTemplate();
}
```

A controller calls it in place of a hardcoded template string, checking `resolveSource()` first for a DB-edited override:

```php
$source = $templateOverrideResolver->resolveSource('customer_login');
if ($source !== null) {
    return new Response($twig->createTemplate($source)->render($context));
}

$template = $templateOverrideResolver->resolve('customer_login', 'customer/auth/login.html.twig');

return $this->render($template, $context);
```

If no active bundle targets the point, `$default` (core's own template) is returned — an Inactive overriding bundle falls straight back to core behavior. `$twig->createTemplate($source)->render()` runs on the same Twig environment as normal rendering, so `path()`, `csrf_token()`, `app.flashes()`, `injection_point()`, and `{% extends %}` all work exactly as they would in a file-based template.

## The real override points in use

| Point | Called from | Default template |
|---|---|---|
| `customer_login` | `App\Controller\Customer\AuthController::login()` | `customer/auth/login.html.twig` |

As with Injection Points, there's no registry beyond the `resolve()` call sites themselves — wiring a new controller to call `TemplateOverrideResolver::resolve()` immediately creates a new available point.

## Bundle-namespaced templates

A bundle that extends `Symfony\Component\HttpKernel\Bundle\Bundle` and ships a `templates/` directory automatically gets a Twig namespace derived from its class name (trailing `Bundle` stripped), e.g. `Number1GuestCoverPageBundle` → `@Number1GuestCoverPage`. Point `getTemplate()` at that namespace (`@Number1GuestCoverPage/login.html.twig`) rather than trying to place a template under core's own `templates/` directory — a bundle overriding a page should own its own template file.

## The kill-switch: BundleDescriptorInterface

As with every bundle type, implement `App\Contract\Bundle\BundleDescriptorInterface` (tag `app.bundle_descriptor`) or your bundle won't appear in Bundle Management (`/admin/bundle-management`) and can't be toggled Inactive. Its `getSource()` should return the same string as your `TemplateOverrideProviderInterface::getSource()` implementation — both feed the same `BundleStatusRepository` lookup.

## Example: Number1GuestCoverPageBundle

`CustomerLoginTemplateOverrideProvider` (`modules/Number1GuestCoverPageBundle/src/TemplateOverride/CustomerLoginTemplateOverrideProvider.php`):

```php
final class CustomerLoginTemplateOverrideProvider implements TemplateOverrideProviderInterface
{
    public function __construct(private readonly GuestCoverPageTemplateStore $store) {}

    public function getPoint(): string { return 'customer_login'; }
    public function getTemplate(): string { return '@Number1GuestCoverPage/login.html.twig'; }
    public function getPriority(): int { return 10; }
    public function getSource(): string { return 'Number1GuestCoverPageBundle'; }
    public function getTemplateSource(): ?string { return $this->store->getCustomSource(); }
}
```

Its `templates/login.html.twig` extends the same `customer/_main/layout.html.twig` and keeps the same form (field names, CSRF token) as core's login page, just with a different surrounding layout. `GuestCoverPageTemplateStore` (an `AppSetting` row, same storage the app already uses for `EmailTemplate`-style content) lets an admin edit that source directly from `/admin/bundles/guest-cover-page` — `getTemplateSource()` returns the saved override once one exists, so it wins over the shipped file without any deploy. See [modules/Number1GuestCoverPageBundle/README.md](../../modules/Number1GuestCoverPageBundle/README.md).
