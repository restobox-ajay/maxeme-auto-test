# Number1GuestCoverPageBundle

**Type:** Template Override · **Name:** Guest Cover Page · **Edit route:** `admin_bundle_guest_cover_page_index`

## What it does

Replaces the customer login page (`customer_login` route, rendered by `App\Controller\Customer\AuthController::login()`) with this bundle's own split-panel template, `templates/login.html.twig` (exposed as `@Number1GuestCoverPage/login.html.twig` via Symfony's automatic bundle Twig namespace). It's the reference implementation for the app's new **Template Override** mechanism — see [docs/bundles/template-overrides.md](../../docs/bundles/template-overrides.md) for how to build one of these for a different page.

The login form itself (field names, CSRF token, `_remember_me`) is unchanged from core's `templates/customer/auth/login.html.twig`, so `form_login` authentication keeps working exactly as before — only the surrounding page markup/styling differs. The `customer_login_after_card` injection point is preserved, so bundles like `NewsBundle` that hook into it (e.g. `LoginScrollerProvider`) still render correctly under this cover page.

## How it's configured

**Admin editing**: `/admin/bundles/guest-cover-page` (also reachable from the "Number1 Integration" sidebar section, or Bundle Management's Edit link) lets an admin edit the page's Twig source directly and save — no deploy needed. `GuestCoverPageTemplateStore` persists the override as an `AppSetting` row; `CustomerLoginTemplateOverrideProvider::getTemplateSource()` returns it when present, which `TemplateOverrideResolver::resolveSource()` prefers over the shipped `templates/login.html.twig` file. "Reset to bundle default" on that screen deletes the row and falls back to the file again.

Deactivating this bundle from Bundle Management (`/admin/bundle-management`) makes `AuthController::login()` fall back to core's own `customer/auth/login.html.twig` regardless of any saved override.

## Data

One `AppSetting` row (`guest_cover_page_template_source`) when an admin has saved a custom template; otherwise none.

## External dependencies

None — the cover panel ships its own inline `<style>` in `templates/login.html.twig` rather than touching the shared `app.css`.

## See also

[docs/bundles/template-overrides.md](../../docs/bundles/template-overrides.md) — the `TemplateOverrideProviderInterface` contract and the `customer_login` override point this bundle implements.
