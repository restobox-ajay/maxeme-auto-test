# Plan: snapshot company identity onto orders and estimates

**Status:** not started
**Base:** must merge after PR #69 (`feature/document-address-snapshot`), which established the pattern
**Suggested branch:** `feature/company-identity-snapshot`

---

## The problem

`AbstractSalesDocument::$company` is a `ManyToOne` to `Company`, `NOT NULL`. Documents read straight
through it at render time, so **renaming a company or changing its phone number rewrites every
historical invoice**. This is the same defect the address snapshot fixed (PR #69), on a different
field.

Counts measured on the PR #69 branch:

| Field | Document templates | Email templates |
| --- | --- | --- |
| `company.name` | 25 | 12 |
| `company.phoneNumber` | 23 | 4 |
| `company.primaryEmail` | 20 | 2 |
| `company.tradeName` | 0 | 2 |
| `company.firstName` / `lastName` | 0 | 1 each |
| `company.id` | 5 | 0 |

Roughly **88 reads across 21 templates**, including ten email templates.

### Why it matters beyond cosmetics

- An invoice is a record of a transaction between two named parties. If the buyer's registered name
  changes — rebrand, acquisition, correction — every past invoice now names an entity that did not
  exist at the time.
- `pstNumber` is the reason a tax line looks the way it does. `BCTaxCalculator` charges PST **only
  when the customer has no PST number**. If a customer registers for PST later, every past order's
  tax appears wrong with nothing on the document to explain it.

---

## Decision: one JSON column, not five flat ones

```sql
ALTER TABLE admin_order ADD COLUMN company_snapshot CLOB DEFAULT NULL;
ALTER TABLE estimate    ADD COLUMN company_snapshot CLOB DEFAULT NULL;
```

Holding:

```json
{"name": "...", "tradeName": "...", "email": "...", "phone": "...", "pstNumber": "..."}
```

**Why JSON here when province deliberately used a table.** Province had to be queryable — reporting
filters on it. Company identity does not: the searching and sorting that exists
(`OrderController:117`, `:128`, `:265`, all `c.name`) answers *"show me all orders for Acme"*, which
is a relationship question and should keep using the **live `company` FK**. When an admin filters the
order list by customer they mean the customer as they are now, not as they were named in 2024. So
nothing needs to query the snapshot.

**Precedent.** `admin_order` and `estimate` already carry three CLOB snapshot columns doing exactly
this job — `tax_lines`, `fee_lines`, `extra_charges`. One more is consistent, not novel.

Two columns total instead of ten, and adding a sixth field later becomes a code change rather than a
migration.

**Trade-off accepted:** "find orders where the customer was named X at the time" becomes a `LIKE` over
JSON instead of an indexed lookup. The live FK covers the normal case.

### Field list

Include: `name`, `tradeName`, `email`, `phone`, `pstNumber`.

Exclude:

- **`id`** — route generation; needs the live FK.
- **`firstName` / `lastName`** — one email read each, and PR #69's address snapshot already carries
  the contact's names per address. Duplicating them on the parent recreates the two-sources-of-truth
  problem PR #69 removed. Repoint those two email reads at the address snapshot instead.

---

## Work

1. **Entity** — `company_snapshot` on `AbstractSalesDocument` as a JSON/CLOB column, with a small value
   object or array accessor (`getCompanySnapshot(): array`, `snapshotCompany(Company $c): void`).
   Follow how `taxLines`/`feeLines` are read and written.
2. **Write sites** — populate at creation:
   - `Customer/CheckoutController` (order + estimate)
   - `Admin/OrderController` create, update, `cloneOrder`
   - `Admin/EstimateController` create
   - `EstimateConversionService` — **copy the estimate's snapshot**, do not re-read the company.
     Same rule as addresses; re-reading reintroduces the drift.
3. **Read sites** — repoint ~88 reads. Provide a fallback to the live company for rows predating the
   backfill, so nothing renders blank.
4. **Migration** — add the column, backfill from each document's current `company` row. Lossy for
   anything already renamed; unavoidable and acceptable (no live instance).
5. **Keep** `c.name` filters and sorts on the live FK. Do not change them.
6. **Tests** — mirror `tests/Entity/DocumentAddressSnapshotTest.php`: rename a company, assert the
   order still prints the old name; assert `EstimateConversionService` copies rather than re-reads.

---

## Traps (learned the hard way in PR #69)

- **Twig-side fallbacks survive PHP-side removal.** PR #69 deleted
  `getEffectiveBillingAddress()`'s company-default fallback, but two templates re-implemented the same
  thing in Twig (`order.billingAddress ?: order.company.defaultBillingAddress`) and kept the bug alive.
  After repointing reads, `grep` the templates for any residual `\.company\.` in document context.
- **Seeding at creation is fine; reading at render is not.** Copying the company's current details into
  the snapshot once is correct. Reading the live company every render is the bug.
- **Doctrine inverse collections are empty for entities created in the same request** unless both sides
  are set. Bit this project three times (`AdminOrder::addPayment`, `GeoCountry::addProvince`,
  `AdminOrderAddress`). Not directly relevant to a scalar column, but relevant if a value object is used.

---

## Verification

- `php vendor/bin/phpunit` and `php vendor/bin/codecept run Functional` — both must stay green
  (566 / 325 as of PR #69).
- Replay the migration chain from empty **with seeded data**, then check the rows survived. Do not
  trust "N migrations executed" — see `docs/plans/2026-07-30-ci-migration-replay.md` for why.
