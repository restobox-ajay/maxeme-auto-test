# Test loop bug log

Append-only record of genuine `src/` bugs the headless test-writing loop
(`scripts/test-loop/run-test-loop.sh`) found and fixed while writing tests. Unlike
`tests/TEST_LOOP_LOG.md` (routine coverage notes, gitignored), this file is git-tracked and is
part of the commit that fixes the bug — it's the audit trail for review.

### CustomerNavExtension — category product counts doubled for products with multiple private companies
Symptom: `countProductsForCategoryTree()` built its count query with an unconditional
`leftJoin('p.privateCompanies', 'pc')` that was never referenced in any WITH/filter clause —
just a fan-out join. `COUNT(p.id)` over that join counted one row per product-company pairing,
so a product restricted to 2 private companies was counted twice (3 companies -> 3x, etc.) in
the customer storefront nav's per-category counts.
Fix: removed the unused join — nothing in the method ever filtered or selected via `pc`, so
dropping it restores one row per product with no behavior change other than the count being
correct.

### Customer\AuthController — registration crashes when optional address fields are omitted from the POST body
Symptom: `register()` read several optional form fields (`ship_address_name`, `ship_first_name`,
`ship_last_name`, `ship_address2`, `bill_first_name`, `bill_last_name`, `bill_address2`) via
direct `$data['key']` array access combined with the `?:` operator instead of `??`. Any POST
that omits one of these truly-optional keys (legitimate for any client that doesn't send blank
fields for unfilled optional inputs) threw an "Undefined array key" error, which the
catch-all `\Throwable` handler turned into a generic "Registration failed" message — silently
breaking company registration instead of applying the intended fallback (e.g. `'Shipping'` or
the user's name).
Fix: changed each of the six accesses to `($data['key'] ?? '') ?: fallback` so a missing key
falls through to the same fallback as a present-but-blank one.

### ProductImportService — grid-mode price columns silently ignored on every real CSV import
Symptom: `normalizeHeaderKey()` collapses runs of underscores (`/_+/` -> `_`) as a general
header-cleanup step, then only restores the double-underscore for `fulfillment_region__`
columns — there was no equivalent restoration for `price__` columns. So any CSV header written
as `price__<id>` (exactly what `templateCsv()`/`templateGuideWorkbook()` themselves generate)
got normalized down to `price_<id>` before reaching `applyPricing()`, whose grid-mode branch
only matches keys starting with `price__`. Every grid-mode price column was silently dropped
on import — no error, no warning, just no pricing rows written.
Fix: added the same `price_` -> `price__` restoration used for `fulfillment_region_`, with an
explicit exclusion for the two legitimate single-underscore `price_list`/`price_list_id`
columns (simple mode) so they aren't corrupted into `price__list`/`price__list_id`.

### ProfileController — blank company name on /company-profile crashes with a 500 instead of showing the validation error
Symptom: `companyProfile()` unconditionally built and `persist()`-ed a replacement billing
`CompanyAddress` (the "split off from a shipping/billing combo address" logic) — and attached
it to `$company`'s addresses collection — *before* checking whether the submitted company name
was blank. When the name was blank, the method never called `flush()`, so that new address was
never given a real id, yet the very same request still re-rendered `customer/profile/company.html.twig`,
which iterates `company.addresses` and builds an edit-link URL from each address's id. Building
a route with a null/blank id throws (`"id" must match "\d+"`), turning what should have been a
simple "Company name is required." message into an uncaught 500. This reproduces even on a
company that starts with zero addresses (the addAddress() split-off path runs whenever there's
no existing non-combo billing address, i.e. on nearly every first submission).
Fix: moved the blank-name check to the top of the POST branch, before any entity mutation, so
no address (or any other company field) is touched at all unless the submitted name is valid.

### Admin\PriceListController — invalid currency codes accepted because validation ran after truncation
Symptom: `validatePriceList()` checked `$priceList->getCurrency()`, but
`applyPriceListRequest()` had already called `PriceList::setCurrency()`, which silently
truncates its argument to 3 characters (`strtoupper(substr($currency, 0, 3))`). So submitting
`currency=DOLLARS` stored/validated as `DOL` — a clearly-garbage input passed the "3-letter
code" check and was persisted, instead of surfacing the "Currency must be a 3-letter code"
validation error.
Fix: `validatePriceList()` now takes the `Request` and validates the raw submitted `currency`
value directly, before truncation, so non-3-letter input is rejected as intended.

### Admin\UserController — creating any staff user as a plain Admin crashes with a 500
Symptom: `validateUserRequest()`'s staff-role guard chain has an `elseif` branch
(`$actorRole === self::ROLE_ADMIN && $existingUser->getId() === $actor->getId() && $role ===
self::ROLE_SUPERADMIN`) that calls `$existingUser->getId()` unconditionally once
`$actorRole === self::ROLE_ADMIN` is true — but `$existingUser` is always `null` on the create
path (`staffCreate()` calls `validateUserRequest($request, $entityManager, null, ...)`). Since
PHP evaluates `&&` operands left-to-right, this threw `Error: Call to a member function
getId() on null` for *any* staff-user create request submitted by a plain `Admin` actor
(the default, non-Superadmin role), regardless of which role was being assigned — turning
what should be routine "create a new admin/plant-staff user" flows into an uncaught 500.
The branch is also dead code independent of the crash: the preceding `elseif` (actorRole
ADMIN + role SUPERADMIN) already catches the only case this one could ever match, so even
with a null-safe check it could never fire its own "cannot upgrade themselves" message.
Fix: added `$existingUser instanceof AdminUser &&` before the `getId()` call, matching the
null-check pattern already used one branch above it.
