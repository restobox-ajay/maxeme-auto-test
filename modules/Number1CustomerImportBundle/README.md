# Number1CustomerImportBundle

**Type:** Import · **Name:** Number1 Customer CSV Import · **Edit route:** `admin_bundle_customer_import_index`

## What it does

Imports/updates `CustomerUser`, `Company`, and `CompanyAddress` records in bulk from an
uploaded CSV. Column format (`First Name`, `Last Name`, `Main Email`, `Company`,
`Main Phone`, `Active Status`, `Invoice To 1-5`, `Ship To 1-5`) is intentionally
compatible with the customer-import CSV used by the legacy Number1 Inventory system,
to ease migrating that data/workflow into this system.

Where this bundle deliberately behaves differently from the Number1 Inventory reference:

- **Update-on-match instead of create-only.** The reference app matches on email and
  silently skips the row if a customer with that email already exists. This bundle
  updates the existing `CustomerUser` instead — blank CSV cells never overwrite an
  existing value, only non-blank cells apply.
- **Structured addresses instead of 5 opaque strings.** The reference app stores each
  "Invoice To"/"Ship To" column verbatim as 5 raw string columns on the customer row.
  This bundle parses the 5 cells (contact name / street address / "City PROV" / postal
  code / extra) into one real `CompanyAddress` each — a default billing address from
  "Invoice To", a default shipping address from "Ship To" — and updates that same
  address in place on re-import instead of appending duplicates.
- **Visible errors instead of silent skips.** Rows with a missing/invalid email or a
  missing company name are reported as error rows in the results screen rather than
  dropped without a trace; the rest of the file still processes.
- **Company is a real, matched-or-created entity.** The reference app has no Company
  entity at all — `company_name` is a bare string on the customer row. This bundle
  matches the `Company` column against existing companies by name (case-insensitive)
  and auto-creates one (via the shared `App\Service\CompanyCodeGenerator`) when no
  match is found, caching the resolution per import run so multiple rows for the same
  company never create duplicates.

New customers get a random, never-communicated password (same convention as
`Admin\UserController::customerCreate()`) and `ROLE_COMPANY_OWNER` unless their company
already has an owner, in which case they get `ROLE_COMPANY_STAFF`.

## How it's configured

One admin screen, `admin_bundle_customer_import_index` (`/admin/bundles/customer-import`):
upload the CSV, optionally check "Send an account-setup invite email to every newly
created customer in this batch" (per import run, not a persistent setting), and submit.
Results — created/updated/skipped counts, companies auto-created, invites sent, and a
row-by-row report with any warnings/errors — render on the same page. A CSV template
matching the exact expected columns is downloadable from the same screen
(`admin_bundle_customer_import_template`).

There is no separate on/off feature toggle beyond Bundle Management itself
(`/admin/bundle-management`) — deactivating the bundle there removes its nav entry.

## Data

No new entities or migration — reuses the existing core `Company`, `CustomerUser`, and
`CompanyAddress` entities exactly as they already exist for manual admin/customer use.

## External dependencies

None. Reuses two core services extracted alongside this bundle so the logic isn't
duplicated: `App\Service\CompanyCodeGenerator` (company code generation, originally a
private method on `Customer\AuthController`) and `App\Service\CustomerInviteMailer`
(account-setup invite email, originally a private method on `Admin\UserController`).
Both existing call sites were switched over to the shared services with no behavior
change.
