# NewsBundle

**Type:** Injection Point · **Name:** News Scroller · **Edit route:** `admin_bundle_news_index`

## What it does

Adds an admin-managed News Posts feature (title, excerpt, HTML content, published date) with its own `news_post` table, and renders the latest 3 published posts as a small rotating scroller. Where the scroller shows up is admin-configurable, not fixed: two independent checkboxes on the News admin screen ("Show on Login Page" / "Show on Catalog Page") control two separate `InjectionPointProviderInterface` services — `CatalogScrollerProvider` (targets `catalog_before_products`, top of `templates/customer/catalog/index.html.twig`) and `LoginScrollerProvider` (targets `customer_login_after_card`, bottom of `templates/customer/auth/login.html.twig`). Both share one rendering helper, `NewsScrollerRenderer`. See [docs/bundles/injection-points.md](../../docs/bundles/injection-points.md) for the full list of injection points available in the app.

Posts with a future `Published At` are excluded from the scroller until that date passes (`NewsPostRepository::findLatestPublished()`), but still show up in the admin list and are directly reachable at `/news/{id}` if linked to.

## How it's configured

Full admin CRUD at `admin_bundle_news_index` (`/admin/bundles/news`) — create, edit, and delete posts. The same screen has a Display Settings panel with the two placement checkboxes, persisted as `AppSetting` rows (`news_show_on_login`, `news_show_on_catalog`) via the app's existing generic settings store.

Deactivating the bundle from Bundle Management (`/admin/bundle-management`) hides the scroller everywhere regardless of the checkboxes — that's a second, independent kill switch on top of the per-location toggles.

## Data

Owns its own Doctrine entity, `NewsBundle\Entity\NewsPost`, auto-mapped via `doctrine.orm.auto_mapping` (no changes needed to `config/packages/doctrine.yaml` — every registered bundle's `src/Entity/` is picked up automatically). Table created by `migrations/Version20260724100000.php`.

## External dependencies

None — the content field reuses the existing `.email-editor-shell` / `.email-editor-toolbar` textarea toolbar already used by `admin/config/email_template_form.html.twig` (behavior delegated globally in `public/assets/js/app.js`), and the scroller ships its own inline `<style>`/`<script>` rather than touching the shared `app.css`/`app.js` files.

## See also

[docs/bundles/injection-points.md](../../docs/bundles/injection-points.md) — the `catalog_before_products` and `customer_login_after_card` points this bundle introduced.
