# Security review — full repository

**Date:** 2026-07-28
**Scope:** Full repository (`wholesale-b2b-core`), branch `fix/checkout-billing-duplicate-and-text` — entire codebase, not a diff
**Method:** Workflow-backed review. 12 subsystem finders raised 72 candidates; 20 adversarial verifiers (prompted to refute, and to downgrade overstated severity) confirmed 66 and refuted 6. Split into 34 security findings and 32 correctness/quality findings, then synthesized.

> Findings marked PLAUSIBLE have a confirmed code-level defect but an exploitation or failure path that depends on deployment specifics not observable from the repository. Confirm those in the target environment before acting on the stated severity.

---

## Executive summary

This is a full-repository security review of `wholesale-b2b-core` (PHP 8.2 / Symfony 7.4 / Doctrine 3.6, 35 in-tree bundles) as it stands on branch `fix/checkout-billing-duplicate-and-text`. It is not a diff review; every area listed under "Checked and clean" was read.

34 adversarially-verified findings were merged by root cause into **21 distinct defects: 6 high, 11 medium, 4 low**.

The dominant themes:

1. **The admin tier is flat.** `src/Entity/AdminUser.php:83-89` appends `ROLE_ADMIN` to every admin row, and the only gate on the entire panel is `- { path: ^/admin, roles: ROLE_ADMIN }` (`config/packages/security.yaml:89`). Across `src/Controller/Admin/` and `modules/*/src/Controller/` there are exactly four `denyAccessUnlessGranted` calls, all of them *additive* `ROLE_TECH_SUPPORT` checks. So "Plant Staff" and "Tech Support" are labels, not boundaries — several findings below escalate from the lowest admin tier to full host compromise.
2. **Admin-authored Twig is compiled unsandboxed.** Five call sites feed operator-supplied strings to `Twig\Environment::createTemplate()` with no `SandboxExtension`. Verified locally against the vendored twig/twig 3.28.0 that this yields OS command execution.
3. **CSRF is inconsistently applied.** The three `modules/` importers check tokens; most core admin controllers — including user delete, company delete, product delete and the catalog-wide product import — check nothing. The REMEMBERME cookie, issued with no `SameSite` and `secure: false`, is what turns that from a paper cut into a remote vector.
4. **Payment amount is never bound to the order.** The checkout Stripe path validates intent *status* only, then records a payment row for the recomputed cart total.

Cross-company isolation itself — the core invariant for this app — held up: every customer-facing repository query I traced pins on the actor's company. The one cross-tenant defect found (email case-collision) is denial of service, not data disclosure.

Two findings are labelled **PLAUSIBLE**: the code defect is confirmed, but the exploitation chain depends on deployment configuration I could not observe from the repository (specifically, whether the front-end web server forwards unmatched `Host` headers to PHP). They need environment confirmation before being treated as proven.

## Remediation status (2026-07-28)

Fixed findings are struck through in the table below. Each links to the branch that fixes it;
all branches are pushed to `origin` and based on `origin/main`.

| Finding | Branch |
| --- | --- |
| Sec 1 — hardcoded super-admin credentials | `security/twig-sandbox-and-admin-credentials` (partial — see caveat) |
| Sec 2 — unsandboxed `createTemplate()` RCE | `security/twig-sandbox-and-admin-credentials` |
| Sec 3 — plaintext reset tokens in `email_log` | `security/redact-reset-tokens-in-email-log` |
| Sec 21 — git-tracked SQLite database | `security/untrack-committed-sqlite-database` |
| Sec 4 — Stripe captured amount never verified | `security/verify-stripe-captured-amount` |
| Code F2 — `clear_approved_balance` default | `fix/clear-approved-balance-default-false` |

**Caveat on Sec 1 / Sec 21:** the credential defaults are gone from the source and the database is
no longer tracked, but the blob remains in git history. That super-admin password must be treated
as disclosed — rotate it, and purge the blob from history if this repository is or becomes public.


### Findings table

| # | Severity | Location | Issue |
|---|---|---|---|
| 1 | ~~High~~ | ~~`src/Command/CreateAdminCommand.php:27-28`, `README.md:92`~~ | ~~Hardcoded default ROLE_SUPER_ADMIN email + password; verifies against the committed DB~~ **— FIXED** |
| 2 | ~~High~~ | ~~`src/Controller/Admin/ConfigController.php:806` (+4 sites)~~ | ~~Unsandboxed `createTemplate()` on admin-authored Twig → RCE from any admin tier~~ **— FIXED** |
| 3 | ~~High~~ | ~~`src/EventSubscriber/MailerLogSubscriber.php:53`, `src/Entity/EmailLog.php:27`~~ | ~~Plaintext password-reset tokens persisted to `email_log` and readable in the admin UI~~ **— FIXED** |
| 4 | ~~High~~ | ~~`modules/PaymentStripeBundle/src/Payment/StripePaymentMethod.php:101-117`~~ | ~~Checkout never compares Stripe's captured amount to the order total~~ **— FIXED** |
| 5 | High | `modules/Number1RimImportBundle/src/Service/RimImageSyncService.php:139` | Feed-controlled file extension written into the PHP-executable webroot |
| 6 | High | `src/Controller/Admin/UserController.php:495` (+9 files) | No CSRF token on destructive admin POST routes |
| 7 | Medium | `src/Controller/Customer/CompanyUserController.php:197-286` | No actor-role check: any company staff can seize a colleague's account |
| 8 | Medium | `src/Repository/CustomerUserRepository.php:37-51`, `src/Entity/CustomerUser.php:12` | BINARY-unique email vs `LOWER()` lookup → cross-tenant login/reset lockout |
| 9 | Medium | `config/packages/security.yaml:36-39, 75-78` | REMEMBERME issued with `secure: false` and no `SameSite`, 14-day lifetime |
| 10 | Medium | `modules/Number1RimImportBundle/src/Service/RimImageSyncService.php:115` | SSRF with response exfiltration via the image downloader |
| 11 | Medium | `templates/customer/order/_list_rows.html.twig:56` | Stored XSS: `\|join('<br>')\|raw` over customer-supplied address fields |
| 12 | Medium | `src/Service/AppSettings.php:128-146` | `envForKey()` bypasses the class's own sensitive-env filter |
| 13 | Medium | `src/Controller/Customer/CompanyAddressController.php:65` | Unvalidated province ⇒ silent $0.00 tax on real orders |
| 14 | Medium | `src/Controller/Customer/AuthController.php:51` | `/auth/register` has no rate limit; fans out mail to every active admin |
| 15 | Medium | `src/EventSubscriber/ErrorLogSubscriber.php:24` | Every 404 writes an unbounded 20 KB log row with its own flush |
| 16 | Medium (PLAUSIBLE) | `src/EventSubscriber/AdminHostSubscriber.php:63`, `src/Controller/Admin/AuthController.php:129` | Host header trusted; reset links built from it instead of `AdminUrlGenerator` |
| 17 | Medium | `src/Controller/Admin/UserController.php:849-856` | "Tech Support" creates a `CustomerUser`, not an `AdminUser` |
| 18 | Low | `src/Controller/Customer/AbstractCustomerController.php:67` | Open redirect via backslash authority (`/\host`) |
| 19 | Low | `src/Security/CustomerUserChecker.php:22` | Account lifecycle state disclosed before the password is checked |
| 20 | Low | `src/Entity/ProductCore.php:133` | `is_private` is a write-only column; the CSV `private` column is a no-op |
| 21 | ~~Low (PLAUSIBLE)~~ | ~~`var/data/wheelmart.sqlite`~~ | ~~Dev SQLite DB is git-tracked despite two `.gitignore` entries~~ **— FIXED** |

---

## 1. Hardcoded default super-admin credentials — HIGH

**Location:** `src/Command/CreateAdminCommand.php:27-28`, `src/Command/CreateAdminCommand.php:67`, `README.md:92`
*(merged from findings [6] and [7])*

**Flaw.** The admin provisioning command ships a real email and password as *option defaults*, and grants the resulting account `ROLE_SUPER_ADMIN` with status `Active`. Because both options are `VALUE_REQUIRED` **with a default**, a bare `php bin/console app:create-admin --no-interaction` silently provisions that exact account. There is no password-strength check and no rejection of the default value.

```php
// src/Command/CreateAdminCommand.php:27-28
->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email', 'ken@restobox.com')
->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password', 'M38nB5!f%eYa')
```
```php
// src/Command/CreateAdminCommand.php:66-67
$user->setStatus('Active');
$user->setRoles(['ROLE_SUPER_ADMIN']);
```
`README.md:92` documents that literal invocation as provisioning step 6. The credential is not theoretical: `var/data/wheelmart.sqlite` is git-tracked, and `password_verify('M38nB5!f%eYa', $hash)` returns `true` for row `id=1 ken@restobox.com | Active | ["ROLE_SUPER_ADMIN"]`.

**Attacker and gain.** Anyone with read access to the repository — contractor, ex-employee, any fork or leak — starting from zero application privilege. They POST those credentials to `/admin/login` on any deployment provisioned via the documented path and obtain full cross-tenant control: every company, customer, order, price list and import screen. `login_throttling` (5 / 15 min, `security.yaml:33-35`) is irrelevant against a known password. `src/Security/AdminUserChecker.php` blocks only non-`Active` accounts. Nothing anywhere rotates or disables it (`grep -rn 'M38nB5'` hits only the command and the README).

**Confidence caveat.** The verified hash is in the **dev** database (`.env:18 APP_ENV=dev`). Production impact is inferred from the README being the documented install path, which is likely but not directly observed — hence high rather than critical.

**Remediation.** In `CreateAdminCommand::configure()`, drop both defaults (`VALUE_REQUIRED` with no default) and make `execute()` fail when either is empty; prompt for the password interactively or read it from an env var. Replace `README.md:92` with a placeholder. Immediately rotate the `ken@restobox.com` password on every existing deployment and purge/rewrite the credential out of the tracked database (see finding 21).

---

## 2. Unsandboxed Twig compilation of operator-supplied source → RCE — HIGH

**Locations** *(merged from findings [1], [8], [9], [16], [30] — one root cause, five sinks)*:
- `src/Controller/Admin/ConfigController.php:806-807` — POST body rendered and returned in the response (fastest trigger)
- `src/Service/EmailNotifier.php:50, 56` — stored `EmailTemplate` subject/body on every transactional send
- `src/Service/CustomerInviteMailer.php:51, 57`
- `modules/Number1GuestCoverPageBundle/src/Controller/Admin/GuestCoverPageController.php:188` (via `validate()` → `renderAsGuest()`, executes *before* save)
- `src/Controller/Customer/AuthController.php:41-43` — renders the stored override on the **unauthenticated** `/auth/login` page

**Flaw.** Arbitrary operator-supplied text is compiled by the main application `Twig\Environment`. No sandbox exists anywhere: `grep -rn 'Sandbox|SecurityPolicy|sandbox' src/ modules/ config/` returns nothing, and `config/packages/twig.yaml` sets only `file_name_pattern`.

```php
// src/Controller/Admin/ConfigController.php:794-807
$body = $template->getBody();
if ($request->isMethod('POST')) {
    $body = (string) $request->request->get('body', $body);
}
...
$twigTemplate = $twig->createTemplate($body);
$rendered = $twigTemplate->render($mockData);
return new Response($rendered);
```

The auto-wrap at lines 799-804 only prepends `{% extends %}{% block body %}` — it neutralizes nothing. `EmailTemplate::$body` defaults to `''` (`src/Entity/EmailTemplate.php:29`) so the id-less variant does not fatal.

Execution was **reproduced against this repo's vendored twig/twig 3.28.0**:
```
$ php -r 'require "vendor/autoload.php"; echo (new \Twig\Environment(new \Twig\Loader\ArrayLoader([])))
  ->createTemplate("{{ [\"id\"]|map(\"system\")|join }}")->render([]);'
uid=1000(vboxuser) gid=1000(vboxuser) ...
```
The mechanism is `CoreExtension::checkArrow()` (`vendor/twig/twig/src/Extension/CoreExtension.php:2118-2129`), which rejects non-Closure callables **only when `$isSandboxed`**; outside the sandbox it emits a deprecation and `map` then invokes the string callable at line 2044. `/etc/php/8.3/fpm/php.ini:333` has an empty `disable_functions`, so the FPM worker is unrestricted.

**Attacker and gain.** Any authenticated admin-tier account, including the lowest ones. `src/Controller/Admin/UserController.php:865/869` persists `setRoles(['ROLE_TECH_SUPPORT'])` / `setRoles(['ROLE_PLANT_STAFF'])`, but `src/Entity/AdminUser.php:83-89` appends `ROLE_ADMIN` unconditionally and `ConfigController` has zero authorization calls of its own. Result: shell execution as the PHP-FPM user — read `var/data/wheelmart.sqlite` (all tenants' orders, pricing, PII), `.env`/`APP_SECRET`/Stripe keys, and install persistence. Via the guest-cover-page store the payload is persisted to an `AppSetting` row and re-executed for **anonymous** visitors to `/auth/login` whenever that bundle is active (`src/Service/TemplateOverrideResolver.php:33` gates on `isActive()`), which converts it into a credential-harvesting login page that survives the admin account being disabled.

**Not exploitable as CSRF.** One candidate claimed a zero-privilege CSRF chain into this sink; it was refuted. `$request->request` is the POST bag only (no query fallback), so a GET navigation injects nothing, and `cookie_samesite: lax` means a cross-origin form POST carries no session cookie.

**Remediation.** Build a dedicated sandboxed environment for user-authored templates: a second `Twig\Environment` with `new SandboxExtension(new SecurityPolicy($allowedTags, $allowedFilters, $allowedMethods, $allowedProperties, $allowedFunctions), true)`, and route **all five** call sites through it — not just the preview. Separately, add `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')` on the email-template and guest-cover-page controllers so template authoring is not available to every admin row.

---

## 3. Plaintext password-reset tokens persisted to `email_log` and exposed in the admin UI — HIGH

**Locations:** `src/EventSubscriber/MailerLogSubscriber.php:53-59`, `src/Entity/EmailLog.php:26-27`, `src/Controller/Admin/SystemController.php:50-52, 118-119`
*(merged from findings [22] and [27])*

**Flaw.** Every outgoing mail's fully rendered HTML body is stored verbatim, including the reset URL that carries the **raw** token.

```php
// src/EventSubscriber/MailerLogSubscriber.php:53-59
$body = $email->getHtmlBody() ?: $email->getTextBody();
$log = (new EmailLog())
    ->setTemplateCode($templateCode)
    ->setRecipient($recipient)
    ->setStatus($status)
    ->setBody($body);
```
```php
// src/Controller/Admin/AuthController.php:124-129
$resetToken = $resetTokenService->generate();
$user->setResetToken($resetTokenService->hash($resetToken));   // DB copy hashed
$user->setResetTokenExpiresAt((new \DateTimeImmutable())->modify('+1 hour'));
...
$resetUrl = $this->generateUrl('admin_password_reset', ['token' => $resetToken], ABSOLUTE_URL); // raw
```

This directly negates the invariant the codebase states for itself:
```php
// src/Service/ResetTokenService.php:8-13
 * Password-reset / account-setup tokens must never be stored or looked up in plaintext —
 * a DB read (backup leak, injection, etc.) would otherwise hand over live account-takeover
 * tokens directly.
```
`templates/emails/forgot_password.html.twig:11` renders `<a href="{{ reset_url }}">`, and the same applies to `invite.html.twig` fed by seven other `generate()` call sites. The log is rendered in full in the admin UI (`SystemController.php:119 'body' => $log->getBody() ?? ''`, `templates/admin/system/_email_log_rows.html.twig` iframe `srcdoc`) with a free-text body `LIKE` filter (`SystemController.php:50-52`) and a purpose-built `'password' => '%Password%'` preset (`SystemController.php:64`).

**Attacker and gain.** A "Plant Staff" or "Tech Support" admin — explicitly barred from managing super admins by `src/Controller/Admin/UserController.php:789-805` ("Admins cannot edit super admin users.") — triggers the **public** `/admin/password-reset` form (`security.yaml:83 PUBLIC_ACCESS`) for the super admin's address, opens `/admin/email-log`, filters on the password module, reads the live 64-hex token inside the 1-hour window, and completes the reset. Full super-admin takeover, defeating exactly the guard `UserController` implements. `src/EventSubscriber/AuditLogSubscriber.php:31` excludes `EmailLog`, so the read leaves no trail. The same path works against any customer account in any tenant, and any DB backup or read-only injection yields the same live tokens.

`SystemController` declares no `#[IsGranted]`; `config/packages/mailer.yaml` forces no null transport; no command prunes `email_log`.

**Remediation.** In `MailerLogSubscriber::persistLog()`, stop storing bodies for credential-bearing templates — either drop `setBody()` entirely (recipient/status/template_code are sufficient for delivery triage) or scrub URLs before storage. Add `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')` to `SystemController::emailLog()` and remove the body column from the response payload and the body `LIKE` filter. Add a retention command that prunes `email_log` rows older than N days.

---

## 4. Stripe checkout never verifies the captured amount against the order — HIGH

**Locations:** `modules/PaymentStripeBundle/src/Payment/StripePaymentMethod.php:101-117`, `src/Controller/Customer/CheckoutController.php:422-428`, `src/Controller/Customer/CheckoutController.php:642-651`
*(merged from findings [4] and [5])*

**Flaw.** Checkout-time validation checks intent *status* and *company metadata* only. The order total is recomputed from a **later** read of the mutable session cart, and the recorded payment amount is that recomputed total — never what Stripe actually captured.

```php
// modules/PaymentStripeBundle/src/Payment/StripePaymentMethod.php:100-117
if ((string) ($intent->status ?? '') !== 'succeeded') { return new PaymentValidationResult(success: false, ...); }

// The intent's amount was fixed server-side, from the checkout total, at creation
// time by StripeCheckoutIntentController -- ... a succeeded status is the whole check
$company = $context['company'] ?? null;
if ($company instanceof Company) { /* metadata company_id comparison only */ }
return new PaymentValidationResult(success: true);
```
The in-code comment is the mistaken premise: the intent amount is fixed at *intent-creation* time (`StripeCheckoutIntentController.php:131`), the order total at *submit* time (`CheckoutController.php:394`), and metadata carries only `company_id` (`StripeCheckoutIntentController.php:142-144`) — no `order_id`, no cart fingerprint.

```php
// src/Controller/Customer/CheckoutController.php:642-650
if ($stripePaymentIntentId !== null) {
    $payment = (new AdminOrderPayment())
        ->setOrder($order)
        ->setAmount($this->decimal($totals['total']))          // recomputed order total
        ->setStripePaymentIntentId($stripePaymentIntentId);
```
```php
// src/Controller/Customer/OrderController.php:737-742 — the order then reads as paid
foreach ($order->getPayments() as $payment) { $paymentTotal += (float) $payment->getAmount(); }
return $paymentTotal > 0 && $paymentTotal >= (float) $order->getTotal();
```
The post-order payment path proves the check was known to be necessary — `src/Controller/Customer/OrderController.php:946-950` compares `amount_received` against the expected total and rejects mismatches. That comparison is absent from the checkout path.

**Attacker and gain.** Any authenticated customer, no special role. Create a $10 intent, confirm the card, then load the cart to $10,000 in a separate `/cart/add` request, then submit checkout with the $10 intent id. `validate()` passes; a $10,000 order is created with a $10,000 "paid" payment row; `isOrderPaid()` reports fully paid so `canMakePayment()` and `canCustomerCancelOrder()` (lines 710-727) turn off. Goods shipped for a fraction of the price, with the discrepancy invisible in the application's own records.

**Worse than filed:** `CheckoutController.php:642` gates only on the field being **non-null**, not on the selected method being Stripe, and `ManualPaymentMethod.php:50-53` / `PayUponDeliveryPaymentMethod.php:36-39` both `return new PaymentValidationResult(success: true)` unconditionally. So *any* posted string in `stripe_payment_intent_id` writes a full-total "paid" row with no Stripe call at all.

**Bounded by:** one order per intent — `migrations/Version20260708093000.php:25` creates `UNIQUE INDEX UNIQ_4590F221FC72F97E ON admin_order_payment (stripe_payment_intent_id)` (present in the live DB), and the violation is caught at `CheckoutController.php:656`. Orders are also created `Pending` / `Unpaid` (lines 528, 539) pending human fulfillment. Hence high, not critical.

**Remediation.** Pass the computed totals into `PaymentMethodInterface::validate()` and, in `StripePaymentMethod::validate()`, compare `(int) round($expectedTotal * 100)` against `$intent->amount_received` exactly as `OrderController::recordStripePayment()` already does. Additionally stamp a cart fingerprint into the intent metadata at `StripeCheckoutIntentController.php:142` and re-verify it at submit. Separately, gate `CheckoutController.php:642` on `$paymentMethod instanceof StripePaymentMethod` so non-Stripe methods cannot mint payment rows, and set the recorded `amount` from `amount_received`, not from `$totals['total']`.

---

## 5. Rim import writes remote content into the webroot with a feed-controlled extension — HIGH

**Location:** `modules/Number1RimImportBundle/src/Service/RimImageSyncService.php:123-147` *(finding [2])*

**Flaw.** The extension comes straight from the remote URL and the body is written with no content-type, magic-byte or size check.

```php
// RimImageSyncService.php:137-141
$ext = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
$ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) === 1 ? $ext : 'jpg';
return self::PREFIX . sha1($url) . '.' . $ext;
```
```php
// RimImageSyncService.php:123-127
if ($status < 200 || $status >= 300 || $content === '') { return; }
@file_put_contents($path, $content);
```
`php`, `phtml`, `html`, `svg` all pass that regex. The target is `$this->projectDir . '/public/uploads/products'` (line 146). The URL is unfiltered feed input — `RimApiTransformer.php:181-194` only trims — and reaches the writer via `RimApiImportService.php:135`.

**Execution confirmed on this deployment:** `/etc/caddy/conf.d/wholesale-b2b-core.caddy` sets `root * .../public` with `php_fastcgi unix//run/php/php8.4-fpm.sock` and `file_server`, with no path restriction, and there is no `.htaccess` or per-directory config anywhere under `public/`. Caddy's `php_fastcgi` proxies any existing `*.php` path to FPM.

**Attacker and gain.** Whoever controls the configured feed body — the third-party vendor, anyone who compromises them, or an admin who repoints `RimImportConfig::KEY_API_URL` (`RimImportController.php:48`). Because the filename is `sha1($url)`, the attacker computes the retrieval path in advance. Requesting `/uploads/products/rim-api-<sha1>.php` executes it as the web user: remote code execution. Even with PHP disabled in that directory, `.html`/`.svg` gives stored XSS on the storefront origin, and `CatalogQueryService.php:218` publishes the URL to every API consumer.

This is a supply-chain/insider precondition, not an anonymous-internet one — but it is a precondition, not a mitigation.

**Remediation.** In `RimImageSyncService::filenameForUrl()`, replace the URL-derived extension with one derived from the validated response: check the `Content-Type` against an allowlist (`image/jpeg|png|webp|gif`), run `finfo`/`getimagesize()` on the downloaded bytes before writing, cap the size, and map the detected MIME type to a fixed extension. Write to a directory outside `public/` and serve through a controller, or add an explicit deny rule for non-image extensions under `public/uploads` in the Caddy site config.

---

## 6. Missing CSRF protection on destructive admin endpoints — HIGH

**Locations** *(merged from findings [10], [11], [12])*:
- `src/Controller/Admin/UserController.php:439, 495, 532, 590` — status / **delete** / reset-password / resend-invite (`grep -c isCsrfTokenValid` = **0** across 10 POST routes)
- `src/Controller/Admin/CompanyController.php:365` — **company delete**, 0 checks across 14 POST routes
- `src/Controller/Admin/ProductImportController.php:18-34` — catalog-wide CSV import
- `src/Controller/Admin/ProductController.php:1268`, `CategoryController.php:186`, `PriceListController.php:157`, `EstimateController.php:421`, `InventoryController.php:93`
- `modules/ShippingAmazonFBABundle/src/Controller/AmazonFBAShippingConfigController.php:25`

**Flaw.** These routes mutate state with no token check. Several do not even accept a `Request`, so no token *could* be read:

```php
// src/Controller/Admin/UserController.php:495-497
#[Route('/delete/{type}/{id}', name: 'admin_user_delete', methods: ['POST'])]
public function delete(string $type, int $id, EntityManagerInterface $entityManager): JsonResponse
```
```php
// src/Controller/Admin/CompanyController.php:365-367, 378-387
#[Route('/delete/{id}', name: 'admin_company_delete', methods: ['POST'])]
public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
...
$company->setStatus('Inactive');
foreach ($users as $user) { $user->setStatus('Inactive'); }   // whole tenant, one request
```

There is no global mitigation: `debug:config framework csrf_protection` returns `enabled: null`, `AbstractAdminController` has no kernel hook, none of the eight subscribers in `src/EventSubscriber/` reference CSRF, and `grep -rni "HTTP_ORIGIN|'Referer'|Sec-Fetch" src/ modules/ config/` returns zero hits.

The product-import case is the most destructive single request. `templates/admin/product/import.html.twig:23` has only `<input type="hidden" name="import_token" ...>`, which `ProductImportService::normalizeProgressToken()` (lines 928-933) accepts as **any** `^[A-Za-z0-9_-]{8,64}$` string and never compares to a session token — it is a progress-polling id, not a defence. With `missing_rows=inactive_missing`, `ProductImportService.php:734-761` sets every SKU absent from the uploaded CSV to `Inactive` — a one-row file takes the entire storefront offline.

The contrast inside the same repo is stark: `modules/Number1ProductImportBundle/.../ProductImportController.php:62`, `modules/Number1CustomerImportBundle/.../CustomerImportController.php:22` and `modules/Number1RimImportBundle/.../RimImportController.php:43` all call `isCsrfTokenValid()` and throw `createAccessDeniedException()`.

**Attacker and gain.** An unauthenticated attacker who gets a logged-in admin to load a page. The session cookie is `SameSite=lax` and does **not** ride along on a cross-site POST — the load-bearing vector is the REMEMBERME cookie, issued with **no `SameSite` attribute** (finding 9). In browsers that do not apply Lax-by-default to attribute-less cookies (Firefox default profile, WebKit) or in Chromium's 2-minute Lax-allowing-unsafe window, an auto-submitted cross-site form authenticates via the remember-me handler and the controller executes with no token check. The destructive routes need no request body at all, and the import's `multipart/form-data` body is CORS-safelisted, so no preflight occurs. Gain: delete admin accounts, deactivate an entire tenant and all its users, delete products/categories/price lists, or blank the catalog.

`denyIfCannotManageStaffTarget()` (`UserController.php:1080-1118`) does not help — a remembered Super Admin victim passes every check.

**Remediation.** Add `isCsrfTokenValid()` to every POST route in the files listed (mirroring `modules/Number1ProductImportBundle`), and emit `csrf_token()` fields in the corresponding templates and `fetch()` bodies (`templates/admin/user/_list_rows.html.twig:42`, `templates/admin/product/import.html.twig`). Better: add a kernel `RequestEvent` subscriber that rejects any unsafe-method request under `^/admin` lacking a valid token or a same-origin `Sec-Fetch-Site`. Fix the cookie attributes per finding 9 in the same change.

---

## 7. `/company-users` authorizes on company membership only, never on the actor's role — MEDIUM

**Locations:** `src/Controller/Customer/CompanyUserController.php:197-213`, `:278-286`, `:301-377`, `:406-416`, `:90-106`
*(merged from findings [18] and [20])*

**Flaw.** The only gate is `- { path: ^/company-users, roles: ROLE_CUSTOMER }` (`config/packages/security.yaml:91`), and `CustomerUser::getRoles()` appends `ROLE_CUSTOMER` to every account. Inside the controller, authorization is company membership and nothing else:

```php
// src/Controller/Customer/CompanyUserController.php:203-213
$companyId = $this->currentCompanyId();
...
$user = $entityManager->getRepository(CustomerUser::class)->find($id);
if (!$user instanceof CustomerUser || $user->getCompany()?->getId() !== $companyId) { ... }
```
```php
// src/Controller/Customer/CompanyUserController.php:280-286
$user->setEmail($values['email']);
$user->setRoles($values['role'] === 'Owner' ? ['ROLE_COMPANY_OWNER'] : ['ROLE_COMPANY_STAFF']);
if ($passwordProvided) { $user->setPassword($passwordHasher->hashPassword($user, $values['password'])); }
```
No current-password proof is required — contrast `src/Controller/Customer/ProfileController.php:57-61`, which correctly demands it for a self-service change. `sendResetLink()` (lines 301-377) mails the token to `$user->getEmail()` (line 365), i.e. to whatever address the attacker just wrote. And the owner-protection guard reads the target's *current* role, which `edit()` just made mutable:

```php
// src/Controller/Customer/CompanyUserController.php:411
if (in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true)) { /* refuse delete */ }
```

**Attacker and gain.** A junior `ROLE_COMPANY_STAFF` account. Rewrite the owner's password (or their email, then trigger the "PW Reset Link" button and receive the token) — permanent lockout and full impersonation of the owner. Then demote the owner to Staff and delete the account outright, since line 411 no longer fires. `create()` (lines 96-106) likewise lets any staff account mint new users including new Owners (line 173).

**Scope honesty.** This is strictly intra-tenant (line 210 pins the target to the actor's own company), and `ROLE_COMPANY_OWNER` is enforced *nowhere* — `grep -rn ROLE_COMPANY_OWNER src/ config/ templates/ modules/` shows it is only a display label plus the delete guard, and there is no `role_hierarchy`. So the gain is peer-account takeover and destruction of a business rule, not a jump to a more capable role. Hence medium, ranked above the other mediums.

**Remediation.** Add an explicit owner check at the top of `edit()`, `create()`, `delete()` and `sendResetLink()` — e.g. `if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true) && $user->getId() !== $actor->getId()) { throw $this->createAccessDeniedException(); }` — or add a `CompanyUserVoter` and `#[IsGranted]`. Require the current password (as `ProfileController` does) for any password change on another account, and move the owner-delete guard to check the role as it was *before* the request.

---

## 8. Case-sensitive email uniqueness vs case-insensitive lookup — cross-tenant lockout — MEDIUM

**Locations:** `src/Entity/CustomerUser.php:12`, `src/Repository/CustomerUserRepository.php:21-51`, `src/Repository/AdminUserRepository.php:21-33`, `src/Controller/Customer/CompanyUserController.php:129, 160`, `src/Controller/Admin/UserController.php:754`
*(merged from findings [19] and [21])*

**Flaw.** The unique index is byte-exact while every auth lookup matches on `LOWER()` and ends in `getOneOrNullResult()`, which throws on two rows.

```sql
-- live schema, var/data/wheelmart.sqlite
email VARCHAR(180) NOT NULL
CREATE UNIQUE INDEX uniq_customer_user_email ON customer_user (email);   -- no COLLATE NOCASE
```
```php
// src/Repository/CustomerUserRepository.php:21-34
$email = trim(strtolower($identifier));
... ->andWhere('LOWER(user.email) = :email') ... ->getOneOrNullResult();
```
The application-level duplicate check is exact-match and the write path does not normalize (registration at `AuthController.php:100` *does* lowercase; `CompanyUserController.php:129` only trims):
```php
// src/Controller/Customer/CompanyUserController.php:160
$existing = $entityManager->getRepository(CustomerUser::class)->findOneBy(['email' => $values['email']]);
```
`setEmail()` is a bare assignment (`src/Entity/CustomerUser.php:75-79`), there are no lifecycle callbacks, and `grep -rn UniqueEntity src/` returns nothing. The security provider's `property: email` is bypassed because `CustomerUserRepository` implements `UserLoaderInterface`, so the `LOWER()` query is what actually runs.

Verified empirically against a replica of the real index in a temp DB (repo DB untouched): inserting `bob@acme.com` then `Bob@Acme.com` **succeeds**, and `SELECT COUNT(*) WHERE LOWER(email)='bob@acme.com'` returns 2.

**Attacker and gain.** Any authenticated customer of **any** company POSTs `/company-users/create` with a case variant of a victim's address in another tenant. From then on, the victim's login (`loadUserByIdentifier`) and password reset (`findOneByEmailInsensitive`, uncaught at `AuthController.php:486`, `:184`, `CompanyController.php:474`, `CustomerImportService.php:93`) throw `NonUniqueResultException` → HTTP 500. Cross-tenant denial of service with no data access; recovery requires an admin to find and delete the planted row (it is visible in the admin user list, so this is disruption, not permanent destruction).

**Remediation.** Normalize on write — `strtolower(trim(...))` inside `CustomerUser::setEmail()` and `AdminUser::setEmail()` — then backfill existing rows and add a migration recreating the index as `COLLATE NOCASE` (or on a generated lowercase column for MySQL/Postgres parity). Change the duplicate checks at `CompanyUserController.php:160` and `UserController.php:754` to `findOneByEmailInsensitive()`, and wrap the repository lookups in `try/catch (NonUniqueResultException)` with `setMaxResults(1)` as a belt-and-braces fix.

---

## 9. REMEMBERME cookie issued without `Secure` and without `SameSite` — MEDIUM

**Location:** `config/packages/security.yaml:36-39` (admin firewall), `:75-78` (main/customer firewall) *(finding [0]; also the enabler for finding 6)*

**Flaw.** Both firewalls declare `remember_me:` with only `secret`, `lifetime: 1209600` and `path` — no `secure`, no `samesite`.

```yaml
remember_me:
    secret: '%kernel.secret%'
    lifetime: 1209600
    path: /admin
```
`php bin/console debug:config security firewalls.admin.remember_me` → `secure: false`, `httponly: true`, `samesite: null`. The compiled container confirms a hard `false`, not a null: `getSecurity_Authenticator_RememberMeHandler_MainService.php:26` contains `'secure' => false`. That matters because `vendor/symfony/security-http/RememberMe/AbstractRememberMeHandler.php:94` builds the cookie with `$this->options['secure'] ?? $request->isSecure()` — with `false` stored, the null-coalesce never fires, so the cookie omits `Secure` even on an HTTPS response.

The non-obvious reason Symfony's safe default does not rescue it: `RememberMeFactory::prepend()` (`vendor/symfony/security-bundle/.../RememberMeFactory.php:222-236`) copies the session's secure/samesite defaults **only** `if (isset($config['session']) && \is_array($config['session']))`. `config/packages/framework.yaml:6` is `session: true` — a boolean — so the guard is skipped and the class default `false` (line 40) stands. The session cookie meanwhile correctly gets `cookie_secure: auto`, `cookie_samesite: lax`.

Exposure is broad: `templates/customer/auth/login.html.twig:45` and `modules/Number1GuestCoverPageBundle/templates/login.html.twig:43` both ship `<input type="checkbox" name="_remember_me" value="on" checked>` — remember-me is **on by default** for customers; `templates/admin/auth/login.html.twig:48` offers it unchecked to admins.

**Attacker and gain.** *Missing `Secure`:* a network-adjacent attacker on shared Wi-Fi induces one plain-`http://` request to the host (typed URL, mail link, injected `<img src="http://...">`) and captures the cookie in cleartext, then replays it for a password-free login for up to 14 days — `SignatureHasher::acceptSignatureHash` needs no password and no second factor. *Missing `SameSite`:* this is the cookie that authenticates the cross-site forged requests in finding 6. Nothing forces HTTPS either — no `requires_channel` in `security.yaml`, no HSTS (`grep -rnE 'Strict-Transport' src/ config/ public/ modules/` is empty).

**Remediation.** Add `secure: true` and `samesite: strict` (or at minimum `lax`) under both `remember_me:` blocks in `config/packages/security.yaml`, reduce `lifetime` well below 1209600, and uncheck the customer login template's default. Add `requires_channel: https` to `access_control` and set an HSTS header at the front end.

---

## 10. SSRF with response exfiltration via the rim image downloader — MEDIUM

**Location:** `modules/Number1RimImportBundle/src/Service/RimImageSyncService.php:112-127`; feed URL itself at `modules/Number1RimImportBundle/src/Service/RimApiClient.php:32-40` *(finding [3])*

**Flaw.** Feed-supplied URLs are fetched with no host allowlist, redirects followed, and the body written to a publicly-served, attacker-predictable path.

```php
// RimImageSyncService.php:112-127
$response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
$status = $response->getStatusCode();
$content = $response->getContent(false);
...
@file_put_contents($path, $content);
```
`config/packages/framework.yaml` declares **no** `http_client` section (I read the whole file — only `secret` and `session`), so the contract default `'max_redirects' => 20` applies, and `grep` for `NoPrivateNetwork` across `config/`, `src/`, `modules/` finds nothing outside vendor. The filename is `sha1($url)` (line 141), so the attacker computes the retrieval URL themselves.

**Attacker and gain.** Whoever controls the feed body. Point an image at `http://169.254.169.254/latest/meta-data/...` or an internal `10.x` service; the hourly cron fetches it from inside the server's network and writes the response into `public/uploads/products/`, which the attacker then reads over the public web. Because 20 redirects are followed, a benign-looking `https://evil.tld/img.jpg` that 302s internally defeats literal-URL inspection.

**Correction to the original report:** `file://` and other non-HTTP schemes are **not** reachable — `vendor/symfony/http-client/HttpClientTrait.php:676-677` throws `Unsupported scheme`, and that exception implements `TransportExceptionInterface`, so it is swallowed by the catch at line 118. Only http/https SSRF works.

**Ranked below finding 5** because the precondition is identical and finding 5 already grants direct RCE from that same position — this is a strictly weaker primitive available only to an attacker who already holds the stronger one.

**Remediation.** Wrap the injected client in `NoPrivateNetworkHttpClient` for this service (register it in `modules/Number1RimImportBundle/config/services.yaml`), set `'max_redirects' => 0` or a small bound in the `request()` options at line 115, and validate the resolved host against an allowlist derived from the configured feed domain before fetching. Apply the same to `RimApiClient::fetch()`.

---

## 11. Stored XSS in the customer orders list — MEDIUM

**Location:** `templates/customer/order/_list_rows.html.twig:56` *(merged from findings [31] and [32])*

**Flaw.** `|raw` is applied to the **joined** string, so no member value is ever escaped.

```twig
{# templates/customer/order/_list_rows.html.twig:15-25, 56 #}
{% set ship = order.effectiveShippingAddress %}
{% if ship and ship.addressLine1 %}{% set addressParts = addressParts|merge([ship.addressLine1]) %}{% endif %}
...
{{ addressParts|join('<br>')|raw }}
```
The values are customer input with no sanitization: `src/Controller/Customer/CompanyAddressController.php:58-60` only trims, `:89-95` stores verbatim, and the entity setters are bare assignments (`src/Entity/CompanyAddress.php:110-122`). `grep -rn "strip_tags|HTMLPurifier|sanitiz" src/ modules/` finds nothing, and there is no CSP anywhere (`grep -rn "Content-Security-Policy" src/ config/ public/` is empty). `src/Entity/AbstractSalesDocument.php:182-185` returns the **live** `CompanyAddress` entity (checkout stores a reference, not a snapshot), so edits after the fact still take effect on historical orders.

This is the sole outlier: `grep -rn "|raw" templates/customer/` returns four hits and this is the only one over user-supplied text; every other render of the same fields escapes normally (e.g. `templates/customer/order/detail.html.twig:112`).

**Attacker and gain.** Any `ROLE_CUSTOMER` of a company (`security.yaml:96` gates `^/company-addresses` on `ROLE_CUSTOMER`, and `CompanyAddressController::edit()` only checks company match, so any member can edit the default shipping address). The payload runs for every colleague who opens `/orders` — `OrderController.php:66-71` selects by `o.company = :company`, not by user. In-session actions as the victim: read the company's full order history and negotiated pricing, place orders, add a company user via the CSRF-token-bearing forms already on the page.

**Impact bounded:** the session cookie is HttpOnly by default, so `document.cookie` yields nothing; and there is **no** Staff→Owner escalation, because `ROLE_COMPANY_OWNER` is enforced nowhere (see finding 7).

**Remediation.** Delete the `|raw` at line 56 and build the markup safely: `{{ addressParts|join('<br>')|raw }}` → iterate with `{% for part in addressParts %}{{ part }}{% if not loop.last %}<br>{% endif %}{% endfor %}`. Add a `Content-Security-Policy` response header as defence in depth.

---

## 12. `AppSettings::envForKey()` bypasses the class's own sensitive-env filter — MEDIUM

**Location:** `src/Service/AppSettings.php:128-146` (and the same gap in `resolveValue()` at `:94-98`) *(finding [29])*

**Flaw.** The class documents and implements a filter to prevent env exfiltration, then routes around it.

```php
// src/Service/AppSettings.php:20 (the intended protection)
private const SENSITIVE_ENV_NAME_PATTERN = '/SECRET|PASSWORD|PASS|TOKEN|KEY|DSN|CREDENTIAL|PRIVATE/i';
```
It is applied only in `resolveEnvPlaceholder()` (`:111-114`). `get()` falls through to `envForKey()`, which has no such check and — note the **second, unprefixed** entry — resolves a setting key straight to the same-named environment variable:
```php
// src/Service/AppSettings.php:130-137
$normalized = strtoupper(preg_replace('/[^a-z0-9_]+/i', '_', $key) ?? '');
$normalized = trim($normalized, '_');
foreach ([self::ENV_PREFIX . $normalized, $normalized] as $name) {
    $value = getenv($name);
```
Exposed to any rendered template via `src/Twig/AppSettingsExtension.php:26`. Reproduced by booting the kernel: `app_setting('app_secret')` → the real `APP_SECRET`; `database_url` and `mailer_dsn` likewise. (`stripe_secret_key` returned null only because `.env` leaves it empty.)

**Attacker and gain.** An admin who can author template or setting content reads `DATABASE_URL` (DB user + password on a MySQL/Postgres deployment), `MAILER_DSN` (SMTP password) and `APP_SECRET` (which lets them forge remember-me cookies and CSRF tokens for any account) — credentials outside the application's own authorization model.

**Severity honesty.** Medium, not high. I grepped every `app_setting()`/`->get()` call site in `src/`, `modules/` and `templates/`: none passes a non-literal key, so nothing customer-facing can be steered here. And the same actor already has a strictly stronger primitive on the same route — the unsandboxed `createTemplate()` of finding 2, or even plain `{{ app.request.server.get('DATABASE_URL') }}`, since Dotenv populates `$_SERVER`. This is a real bypass of a stated invariant, but it grants an admin nothing they cannot already obtain.

**Remediation.** Call `self::isSensitiveEnvVarName($name)` inside the `foreach` in `envForKey()` (and in `resolveValue()`) before returning, and drop the unprefixed fallback entirely so only `ENV_PREFIX`-scoped variables are reachable from setting keys.

---

## 13. Unvalidated province produces silent $0.00 tax on real orders — MEDIUM

**Locations:** `src/Controller/Customer/CompanyAddressController.php:65, 92`, `modules/TaxBundle/src/Tax/TaxCalculatorResolver.php:47-60`, `src/Service/OrderTaxBreakdownService.php:213-228` *(finding [17])*

**Flaw.** Province is free text with only a non-empty check:
```php
// src/Controller/Customer/CompanyAddressController.php:65, 92
if ($values['province'] === '') { $errors['province'] = 'Province is required.'; }
...
$address->setProvince($values['province'] ?: null);
```
`templates/customer/company_address/form.html.twig:66` is a plain `<input ... name="province" required>` and `src/Entity/CompanyAddress.php:55` has no validation constraints. Only two calculators exist repo-wide, both with closed `supports()`: `BCTaxCalculator.php:20` (`=== 'BC'`) and `CanadaSimpleTaxCalculator.php:22-40` (ON/NB/NL/NS/PE/AB/SK/MB/QC only). **YT, NT and NU are mapped by `src/Contract/Tax/TaxContext.php:24-26` but supported by nobody**, and there is no fallback calculator. The resolver throws, and the service swallows it:
```php
// src/Service/OrderTaxBreakdownService.php:213-228
try { return $this->taxResolver->calculate($context); }
catch (\RuntimeException $e) {
    $this->logger->warning('Order tax calculation failed, treating as $0 tax.', [...]);
    return [];
}
```
Checkout still completes — the only province guard on the submission path is emptiness (`CheckoutController.php:404-406`), and shipping resolves for any string (`PickupShippingCalculator.php:13` `return true;`), so `CheckoutController.php:481-497` takes the real `buildAndPersistOrder()` branch and `:633` stores `tax = 0.00`.

**Attacker and gain.** Any authenticated customer enters `"Yukon"` or a typo like `"B.C."` and permanently avoids 5-15% GST/PST/HST on every order; the merchant under-remits. **The unconditional case needs no attacker at all:** `templates/customer/auth/register.html.twig:8`, `templates/admin/company/address_form.html.twig:103` and `templates/admin/order/form.html.twig:7` all offer Yukon / Northwest Territories / Nunavut in their own dropdowns, and all three yield $0 tax for honest customers.

This is a revenue/tax-correctness defect, not an isolation break, and the $0.00 line is visible on every order screen — detectable, not silent forever.

**Remediation.** Constrain the field to a fixed enum: validate `$values['province']` in `CompanyAddressController::extractValues()` against the `TaxContext` province map and reject anything else; change the templates to `<select>`. Register a `TaxCalculatorInterface` covering YT/NT/NU (5% GST). Change `OrderTaxBreakdownService::safeCalculateTax()` to fail the checkout rather than returning `[]` when no calculator matches.

---

## 14. `/auth/register` has no rate limit and fans out mail to every admin — MEDIUM

**Location:** `src/Controller/Customer/AuthController.php:51-61`, `:220-273`, `:318-350` *(finding [15])*

**Flaw.** The public registration action injects no rate limiter — unlike `passwordReset()` in the same file, which injects one at line 404 and consumes it at line 467:
```php
// src/Controller/Customer/AuthController.php:467
if (!$passwordResetRequestLimiter->create($request->getClientIp())->consume()->isAccepted())
```
`config/packages/rate_limiter.yaml` defines only `password_reset_request` and `api_manager_catalog`. `login_throttling` covers only the login `check_path`. `grep` for captcha/recaptcha/turnstile/honeypot across `src/`, `modules/`, `templates/`, `config/` returns nothing. The only guard is a reusable session CSRF token (`:75-76`).

Each accepted POST persists a `Company` (`:220`), `CustomerUser` (`:242`) and one or two `CompanyAddress` rows (`:258`, `:273`), then loads every `['status' => 'Active']` `AdminUser` and mails them (`:318-350`). `config/packages/mailer.yaml` configures no async transport, so sends are **inline**.

**Attacker and gain.** Unauthenticated. Fetch `/auth/register` once for a token (Symfony CSRF tokens are session-scoped and reusable), then loop. A few thousand requests mail-bomb the entire admin team, bury genuine pending-approval registrations, exhaust the outbound mail quota so real password-reset and order-confirmation mail is dropped, and flood the company-approval screens. The response at `:186` (`'An account with this email already exists.'`) is additionally a clean unthrottled enumeration oracle over the customer base — though that is the common registration UX and the weaker half of this finding.

Accounts land as company `Review` / user `Inactive` (`:195-216`, `:239`) unless auto-approve is on, so this is abuse/DoS, not takeover.

**Remediation.** Add a `registration_request` limiter to `config/packages/rate_limiter.yaml`, inject `RateLimiterFactoryInterface $registrationLimiter` into `AuthController::register()` and consume it on `$request->getClientIp()` exactly as `passwordReset()` does. Move the admin notification to a Messenger async transport, and batch/digest it rather than one mail per registration.

---

## 15. Every 404 writes an unbounded error-log row — MEDIUM

**Location:** `src/EventSubscriber/ErrorLogSubscriber.php:24-55` *(finding [26])*

**Flaw.** The subscriber has exactly one early return, `if (!$event->isMainRequest())` (line 26). There is no `instanceof HttpExceptionInterface` check, no status-code filter, no sampling, no rate limit — and each event does its own `persist()` + `flush()` (lines 54-55) with a payload carrying `substr($exception->getTraceAsString(), 0, 20000)` (line 45).

A guaranteed unauthenticated trigger sits at REQUEST priority 100, before the firewall:
```php
// src/EventSubscriber/AdminHostSubscriber.php:45-47
if ($this->isAdminPath($path) && !$this->isAdminHost($host)) {
    throw new NotFoundHttpException();
```
Any unrouted path 404s from the router equally.

**Attacker and gain.** Any unauthenticated user loops `curl https://shop.example.com/admin/$RANDOM`; each request INSERTs a multi-kilobyte row plus a flush into a single-file SQLite DB on the app server. A few hundred thousand cheap requests bloat it by gigabytes, degrade every other query, and can exhaust the volume. Confirmed empirically against the dev DB (read-only): `error_log` holds 75 rows of which **56 (75%) are incidental browser 404s** — 31× `GET /favicon.ico`, 25× `GET /.well-known/appspecific/com.chrome.devtools.json` — so the triage signal is already buried by accident, before any malice.

No retention exists: `src/Command/` has no pruning command, and the only deletion path is the manual per-row admin action at `SystemController.php:233`.

**Remediation.** In `ErrorLogSubscriber::onException()`, return early for `$exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500`. Batch or drop the per-event `flush()`. Add a console command (and cron) that prunes `error_log` beyond a retention window and a row cap.

---

## 16. Host header trusted for admin routing and for password-reset link generation — MEDIUM (PLAUSIBLE)

**Locations:** `src/EventSubscriber/AdminHostSubscriber.php:55-64`, `src/Controller/Admin/AuthController.php:129`, `src/Controller/Admin/UserController.php:556, 614`
*(merged from findings [24] and [25]; both marked PLAUSIBLE)*

**Flaw.** The admin/customer host decision is made from the unvalidated `Host` header, and any host beginning `admin.` is accepted even when `ADMIN_HOST` is correctly set:
```php
// src/EventSubscriber/AdminHostSubscriber.php:55-64
$configured = strtolower(trim($this->adminHost));
if ($configured !== '' && $host === $configured) { return true; }
// Defensive fallback: treat any "admin." host as admin if env config is missing/mis-set.
return str_starts_with($host, 'admin.');
```
No allowlist constrains it: `grep -rn "trusted_hosts|trusted_proxies" config/ public/ src/` matches only the vendored typehint stub `config/reference.php:139-140`; `public/index.php` calls no `setTrustedHosts`.

Compounding this, the emailed reset/invite links are built from the request context rather than the host-pinning generator the codebase already provides:
```php
// src/Controller/Admin/AuthController.php:129
$resetUrl = $this->generateUrl('admin_password_reset', ['token' => $resetToken], ...ABSOLUTE_URL);
```
`src/Service/AdminUrlGenerator.php:10-47` exists precisely for this and its docblock states the rationale, yet `grep -rn AdminUrlGenerator src/ modules/` shows its only consumer is `SalesDocumentNotifier.php:25`. The customer branch on the very same lines correctly calls `$customerUrlGenerator->generate(...)`. The endpoint is `PUBLIC_ACCESS` (`security.yaml:84`) and the token is the raw single-use secret with a 1-hour life (`AuthController.php:124-127`), placed in the query string.

**Attacker and gain (if reachable).** An unauthenticated user who knows an admin's email POSTs `/admin/password-reset` with `Host: admin.attacker.com`. `isAdminHost()` returns true on the prefix, so the request is not 404'd; the reset mail sent to the real admin contains a link to the attacker's host. When the admin clicks it, the token lands in the attacker's access log; they replay it against the real host inside the hour and take over the account.

**Why PLAUSIBLE, not CONFIRMED.** The shipped front end refuses forged hosts before PHP is invoked. `/etc/caddy/conf.d/wholesale-b2b-core.caddy:1` binds only `http://wholesale-b2b-core.localhost, http://admin.wholesale-b2b-core.localhost`, with no catch-all anywhere in `/etc/caddy`. Verified:
```
curl -H 'Host: admin.evil.com'                     http://127.0.0.1/admin/login -> 308 from Caddy, PHP never invoked
curl -H 'Host: wholesale-b2b-core.localhost'       http://127.0.0.1/admin/login -> 404 (subscriber invariant holds)
curl -H 'Host: admin.wholesale-b2b-core.localhost' http://127.0.0.1/admin/login -> 200
```
`X-Forwarded-Host` is inert because no `trusted_proxies` is configured. So the chain requires a deployment whose web server has a default/catch-all vhost — plausible in production, **not demonstrated here**. This needs confirmation against the real production front end before being treated as exploitable. The code-level defect (Host trusted for a security decision, no `trusted_hosts`, reset links not host-pinned) is confirmed regardless.

**Remediation.** Add `framework.trusted_hosts: ['^admin\.example\.com$', '^shop\.example\.com$']` in `config/packages/framework.yaml`. Delete the `str_starts_with($host, 'admin.')` fallback at `AdminHostSubscriber.php:62-63` and fail closed when `ADMIN_HOST` is unset. Route `AuthController.php:129` and `UserController.php:556`/`:614` through the existing `App\Service\AdminUrlGenerator`.

---

## 17. "Tech Support" role creates a `CustomerUser` instead of an `AdminUser` — MEDIUM (correctness)

**Location:** `src/Controller/Admin/UserController.php:849-856`, `:872-878`, `:30` *(finding [13])*

**Flaw.** `ROLE_TECH_SUPPORT` is offered as a staff role but omitted from the entity-selection list:
```php
// src/Controller/Admin/UserController.php:849-856
if (in_array($role, [self::ROLE_ADMIN, self::ROLE_SUPERADMIN, self::ROLE_PLANT_STAFF], true)) {
    return new AdminUser();
}
return new CustomerUser();
```
`applySelectedRole()` then takes the `CustomerUser` branch and assigns `['ROLE_COMPANY_STAFF']` (`:878`) — the `AdminUser`/`ROLE_TECH_SUPPORT` branch at `:861-862` is unreachable for a new user. `validateUserRequest` skips the company check because `$normalizedType === 'admin'` (`:810-812`), so the row is created with `company_id = NULL`. `src/Service/CustomerInviteMailer.php:38-40` then mails a **storefront** `customer_account_setup` link.

**Impact — and what the original claim got wrong.** This is a broken feature with a misleading invite, **not** a privilege escalation. The resulting account cannot log in at all: `src/Security/AccountStatusResolver.php:23-26` returns "Your account is not assigned to a company" for any `CustomerUser` with a null company, and `CustomerUserChecker` (wired on the `main` firewall) throws on `checkPreAuth`. The claimed downstream null-dereference at `CompanyUserController.php:401` is also wrong — that line is `$user->getCompany()?->getId()`, null-safe, and unreachable. The account created has strictly *fewer* privileges than intended.

**Remediation.** Add `self::ROLE_TECH_SUPPORT` to the `in_array` list in `createUserEntityForRole()`. Add a regression test asserting that each entry in `STAFF_ROLE_OPTIONS` yields an `AdminUser`.

---

## 18. Open redirect via backslash authority — LOW

**Location:** `src/Controller/Customer/AbstractCustomerController.php:66-69` *(finding [14])*

**Flaw.** The allow-list rejects only a leading `//`:
```php
$target = trim((string) $request->request->get('redirect_to', ''));
if ($target !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//')) {
    return $this->redirect($target);
}
```
`/\evil.com` passes. `RedirectResponse::setTargetUrl()` (`vendor/symfony/http-foundation/RedirectResponse.php:65-71`) rejects only the empty string and emits the value verbatim; per the WHATWG URL spec's relative-slash state, a `\` after the first `/` is handled identically to a second `/` for special schemes, so the browser resolves it off-origin — the exact scenario the docblock says it prevents.

The field is a plain client-controlled hidden input (`templates/customer/catalog/_cart_qty_modal.html.twig:40`, `detail.html.twig:99`, `_products.html.twig:66`, `_main/home.html.twig:81`). Reach is broader than first filed: `CartController.php:178-181` calls `redirectBackOrTo()` on the **CSRF-failure** branch, so a cross-site auto-submitting form with no valid token still reaches the redirect. The victim must be a logged-in customer (`security.yaml:91` gates `^/cart` on `ROLE_CUSTOMER`).

**Remediation.** In `redirectBackOrTo()`, reject any target containing `\` and parse it before use: accept only when `parse_url($target, PHP_URL_HOST) === null` and the path starts with a single `/`. Better, validate against the route collection rather than accepting a free-form path.

---

## 19. Account lifecycle state disclosed before the password is checked — LOW

**Location:** `src/Security/CustomerUserChecker.php:21-28`, `src/Security/AdminUserChecker.php:17-27`, `src/Service/AccountStatusResolver.php:27-40`, `src/Controller/Customer/AuthController.php:674-687` *(finding [28])*

**Flaw.** `checkPreAuth` fires at priority **256** (`vendor/symfony/security-http/EventListener/UserCheckerListener.php:54-58`), while `CheckCredentialsListener` subscribes at priority 0 — so the status exception is thrown before the password hash is ever compared. `AuthController` then surfaces the four distinct messages verbatim rather than collapsing to a generic failure:
```php
if (str_contains($messageKey, 'pending approval') ...) { return 'Your company registration is pending approval...'; }
if (str_contains($messageKey, 'disabled') ...) { return 'Your account is disabled. Please contact support.'; }
```

**Attacker and gain.** With zero valid credentials, an attacker learns whether a target address is a registered customer and what state its tenant is in — useful for timing a "your account was reactivated" lure at companies known to be awaiting approval.

**Severity honesty.** Low. The *existence* oracle is already public without any login attempt (`AuthController.php:186`, registration form), so the only incremental leak is the lifecycle state. `login_throttling` (5 / 15 min) slows bulk use. No bypass, no data access.

**Remediation.** Move the status check to `checkPostAuth()` in both `CustomerUserChecker` and `AdminUserChecker` so it runs only after the password verifies, and collapse the pre-auth branches in `AuthController::loginErrorMessage()` to the generic "Email or password is incorrect."

---

## 20. `is_private` is a write-only column; the CSV `private` import column is a no-op — LOW

**Location:** `src/Entity/ProductCore.php:33-34, 133-134`, `src/Service/ProductImport/ProductImportService.php:497-499` *(finding [23])*

**Flaw.** The getter ignores the column entirely:
```php
public function isPrivate(): bool { return !$this->privateCompanies->isEmpty(); }
public function setPrivate(bool $private): self { $this->private = $private; return $this; }
```
A repo-wide grep for `is_private` outside `vendor/`/`var/` hits only the entity and old migration DDL — nothing reads it. The importer sets the flag without ever calling `addPrivateCompany()`, so `private=Yes` in a CSV does nothing, even though `private` is an advertised import column with a template example value (`ProductImportService.php:265, 290`).

**Impact honesty — the original framing was wrong.** This is **not** broken access control. The enforcement path (`AbstractCustomerController::applyCustomerProductVisibilityRule()` at `:483-494`, and `CatalogQueryService.php:92`) evaluates `SIZE(p.privateCompanies)` and is intact; nothing previously restricted becomes visible. And the admin grid (`ProductController.php:1343`) showing such a product as "Public" with an empty company list is precisely the signal that **surfaces** the misconfiguration, not one that hides it. There is also no export path writing this column, so re-import cannot strip existing `privateCompanies`.

**Remediation.** Either make the importer resolve the `private` column to actual `addPrivateCompany()` calls (requires a companion company-list column), or remove `private` from the advertised column list at `ProductImportService.php:265`/`:290`. Drop the dead `ProductCore::$private` column and `setPrivate()` in a migration, or make `isPrivate()` read it and keep it in sync.

---

## 21. Dev SQLite database is git-tracked — LOW (PLAUSIBLE)

**Location:** `var/data/wheelmart.sqlite`, `.gitignore:7, 17` *(finding [33])*

**Flaw.** `git ls-files | grep -E '\.sqlite|^var/'` returns `var/data/wheelmart.sqlite`; `git ls-tree HEAD var/data/` shows a 1,294,336-byte blob. `.gitignore:7` (`/var/`) and `:17` (`/var/data/*.sqlite`) are inert against an already-tracked path — the file was committed in `ef0a868` (Initial Commit) and updated in `bcb361e`, `0553872`. It is currently dirty (`git status --porcelain` → ` M var/data/wheelmart.sqlite`), so a routine `git commit -am` publishes the working copy.

**Impact honesty — most of the original claim is refuted by the bytes.** I extracted every historical blob and read them via PDO (repo untouched):
- The **HEAD blob is data-empty**: 27 tables, and `admin_user`, `customer_user`, `company`, `admin_order`, `email_log`, `price_list`, `product_pricing`, `product_core` all have **0 rows**.
- The **working-tree copy** that a commit would publish has only 12 non-empty tables of 47: `admin_user` 1, `app_setting` 28, `audit_log` 67, `bundle_status` 31, `email_template` 24, `error_log` 75, etc. `company`, `customer_user`, `admin_order`, `price_list`, `product_pricing`, `product_core`, `api_credential`, `email_log` are all **0**.
- The "raw reset tokens in `email_log`" claim is refuted by schema, not just row count: the committed `email_log` has **no body or token column at all**; the worktree adds `body CLOB` but holds 0 rows. The 11 historical rows are metadata only (`template_code | recipient | status`).
- `api_credential` does not exist in any committed version.
- The only data ever committed (in `ef0a868`) is transparently synthetic (`Demo Test`, `TEsting1@mail.com`, …).

**What is real:** the single `admin_user` row `ken@restobox.com | $2y$13$AP8u5… | ["ROLE_SUPER_ADMIN"]` — the developer's local dev login at bcrypt cost 13, which is not a practical offline-cracking target on its own, but which is the *same password* as finding 1 and therefore already publicly known from the README. The tracked `.env` has empty `APP_SECRET`, `STRIPE_SECRET_KEY` and `MAILER_DSN: null://null`, so no adjacent secret amplifies this. Repository visibility could not be checked (no `gh` binary), so the "public/OSS push" leg is unverified — hence PLAUSIBLE.

**Remediation.** `git rm --cached var/data/wheelmart.sqlite`, commit, and confirm `.gitignore:17` now takes effect. Since git history is immutable in practice, treat the credential in finding 1 as burned and rotate it. Add a CI check that fails on any tracked file under `var/`.

---

## Checked and clean

The following areas were read and found sound. This is coverage information, not a guarantee of absence.

- **Cross-company data isolation in customer controllers.** Every customer-facing repository query I traced pins on the actor's company: `OrderController.php:66-71` (`o.company = :company`), `CompanyUserController.php:210` and `:311-333` (target pinned to `currentCompanyId()`), `CompanyAddressController.php:135`, `AbstractCustomerController::currentCompanyId()` (`:38-46`). `applyCustomerProductVisibilityRule()` (`:483-494`) correctly enforces private-product visibility on both the storefront and the API module (`CatalogQueryService.php:92`). No IDOR or missing company scope was found in the customer surface — the only cross-tenant defect (finding 8) is denial of service, not disclosure.
- **SQL injection.** All queries go through Doctrine QueryBuilder/DQL with bound parameters. No string-concatenated SQL was found in `src/` or `modules/`.
- **Password storage.** `UserPasswordHasherInterface` throughout; the committed hash is bcrypt cost 13. `ProfileController.php:57-61` correctly requires the current password for a self-service change (which is exactly the control finding 7 is missing).
- **Reset-token *design*.** `ResetTokenService` hashes tokens before storage and `AuthController.php:47` looks them up by hash — the design is correct; the defect (finding 3) is that a plaintext copy leaks out through a different channel.
- **Session cookie configuration.** `debug:config framework session` → `cookie_secure: auto`, `cookie_samesite: lax`, `cookie_httponly: true`. This is why several CSRF and cookie-theft scenarios required the remember-me cookie instead, and why the XSS in finding 11 cannot steal the session id.
- **Login throttling.** Both firewalls carry `login_throttling: max_attempts: 5, interval: '15 minutes'` (`security.yaml:33-35`, `:72-74`), correctly limiting credential stuffing (though irrelevant to a known password).
- **CSRF in the `modules/` importers.** `Number1ProductImportBundle:62`, `Number1CustomerImportBundle:22` and `Number1RimImportBundle:43` all validate tokens and throw `createAccessDeniedException()` — the correct pattern, which finding 6 asks the core controllers to adopt.
- **Payment replay protection.** `migrations/Version20260708093000.php:25` creates `UNIQUE INDEX UNIQ_4590F221FC72F97E ON admin_order_payment (stripe_payment_intent_id)`, present in the live DB, and the violation is caught at `CheckoutController.php:656`. This correctly bounds finding 4 to one order per intent.
- **The post-order Stripe payment path.** `OrderController.php:936-950` binds both `metadata.order_id` and `amount_received` to the expected total — this is the correct implementation, and it is what the checkout path should be copying.
- **`AdminHostSubscriber` baseline behaviour.** Verified live: `/admin/*` on the customer host returns 404, on the admin host returns 200. The invariant documented in `docs/event-listeners.md` holds for the shipped deployment; only the wildcard fallback (finding 16) is a defect.
- **Twig auto-escaping generally.** `grep -rn "|raw" templates/customer/` returns only four hits, and the three besides finding 11 are over admin-authored content. The admin templates render the same address fields escaped (`templates/admin/order/detail.html.twig:186`).
- **Non-HTTP SSRF schemes.** `HttpClientTrait.php:676-677` rejects `file://` and other non-HTTP schemes, so finding 10 is limited to http/https.
- **The API-key firewall** (`Number1ProductAPIManager`, stateless key-only) and its dedicated `api_manager_catalog` rate limiter are configured as intended.
- **`AuditLogSubscriber`** covers entity mutations, with the notable and deliberate exclusion of `EmailLog` (`:31`) — which is what makes finding 3 untraceable.

### Suggested remediation order

1. Rotate the `app:create-admin` credential everywhere and strip the defaults (finding 1) — cheapest fix, largest exposure.
2. Add a Twig `SecurityPolicy` sandbox for all five `createTemplate()` sinks (finding 2).
3. Stop persisting mail bodies and gate `/admin/email-log` (finding 3).
4. Bind the Stripe amount at checkout and gate the payment-row creation on the method (finding 4).
5. Validate downloaded image content and extension (finding 5).
6. Add a global CSRF check under `^/admin` plus `secure`/`samesite` on both `remember_me` blocks (findings 6 and 9) — these two are one change and fix each other's amplification.
