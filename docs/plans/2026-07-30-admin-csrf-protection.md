# Plan: CSRF protection on admin state-changing routes

**Status:** not started
**Independent** — does not depend on any open PR
**Suggested branch:** `security/admin-csrf-protection`

---

## The problem

Most admin POST routes accept a state change with no CSRF token: no hidden field in the form, no
`isCsrfTokenValid()` in the controller. Combined with the `REMEMBERME` cookie being issued without
`Secure` or `SameSite` (see below), that makes them remotely forgeable — an admin who visits an
attacker's page while logged in can have their session used to delete a company, delete a user, reset
a password, or run a catalogue-wide import.

This was finding 6 of the full-repo security review. It resurfaced concretely while writing tests for
the admin address form: that form has no `_token` field at all, so
`tests/Functional/AdminAddressDeliveryInstructionsCest.php` posts **without** one — because that is
what the route currently accepts. Those tests will need updating as part of this work.

## Current coverage, measured

| Controller | POST routes | `isCsrfTokenValid` calls |
| --- | --- | --- |
| `Admin/CompanyController` | 14 | **0** |
| `Admin/UserController` | 10 | **0** |
| `Admin/ProductController` | 7 | **0** |
| `Admin/OrderController` | 10 | 2 |
| `Admin/InventoryController` | 1 | **0** |
| `Admin/PriceListController` | 3 | **0** |
| `Admin/ProductImportController` | 1 | **0** |
| `Admin/ConfigController` | 29 | 17 |
| `Admin/RedirectController` | 3 | 3 |
| `Admin/CompanyPaymentMethodController` | 2 | 2 |

So roughly **56 of 80 admin POST routes are unprotected**, and three whole controllers have zero
coverage. Note the pattern is inconsistent rather than absent — `RedirectController` and
`CompanyPaymentMethodController` do it correctly, which is the model to follow.

The customer side is in better shape: `CheckoutController`, `CompanyAddressController`,
`Customer/OrderController` all validate tokens.

---

## Amplifier: the remember-me cookie

`config/packages/security.yaml` declares `remember_me` with only `secret`, `lifetime` and `path`.
`debug:config` resolves `secure: false` and `samesite: null`, and the compiled container stores a hard
`false`, so `AbstractRememberMeHandler`'s `?? $request->isSecure()` fallback never fires.
`framework.yaml`'s boolean `session: true` prevents `RememberMeFactory::prepend` from inheriting the
session's safer `cookie_secure: auto`.

That is what turns "missing CSRF token" from a paper cut into a remote vector, and the security review
noted the two fixes belong together: each one reduces the other's impact.

**Do both in this change:** add `secure: true` (or `auto`) and `samesite: strict` (or `lax`) to both
`remember_me` blocks.

---

## Approach

Two options; recommend the second.

**Per-route tokens.** Add a hidden `_token` to every admin form and an `isCsrfTokenValid()` to every
handler. Explicit and matches the existing correct controllers, but 56 routes plus their templates is a
large mechanical diff with many chances to miss one silently.

**A global guard under `^/admin` (recommended).** An event subscriber on `kernel.request` that rejects
any non-GET/HEAD request under `/admin` without a valid token, with an explicit allowlist for the
routes that legitimately cannot carry one. Then per-form tokens are added so the guard passes, but a
newly added form that forgets one **fails closed** rather than being quietly unprotected.

Symfony's `framework.csrf_protection` plus form-component tokens would be the idiomatic route, but this
codebase builds forms by hand in Twig rather than with the Form component, so a subscriber fits what is
actually here.

### Must be excluded from the guard

- `POST /webhook/stripe` — Stripe is not a logged-in user and cannot carry a token; its authenticity is
  the `Stripe-Signature` check. Already `PUBLIC_ACCESS` in `security.yaml`; it is outside `^/admin`, so
  a path-scoped guard does not touch it. Verify this when implementing.
- Any admin AJAX endpoint whose caller does not currently send a token — enumerate by grepping
  `sendAjaxPostRequest` in `tests/Functional/` and `$.ajax`/`$.post` in `public/assets/js/app.js`, then
  either add the token client-side or allowlist deliberately with a comment.

---

## Work

1. Event subscriber enforcing tokens for non-GET under `^/admin`, with a documented allowlist.
2. Hidden `_token` in every admin form that posts; grep `<form` under `templates/admin/` for coverage.
3. Client-side: any admin JS that POSTs must send the token — see `app.js` for existing patterns that
   read `input[name="_token"]`.
4. `remember_me`: `secure` and `samesite` on both firewalls in `config/packages/security.yaml`.
5. Update `tests/Functional/AdminAddressDeliveryInstructionsCest.php`, which currently posts without a
   token by design, plus any other Cest that posts to an admin route.
6. New Cest asserting a tokenless admin POST is rejected and changes nothing — the regression guard that
   makes this stick.

---

## Verification

- `php vendor/bin/phpunit` and `php vendor/bin/codecept run Functional` — both green (566 / 325 as of
  PR #69). Expect several Cests to need tokens added; that is the change working.
- Manually exercise one destructive route (company delete) with and without a token.
- `grep -rn "isCsrfTokenValid" src/Controller/Admin/ | wc -l` should approach the POST-route count, or
  the subscriber should be covering the difference.
