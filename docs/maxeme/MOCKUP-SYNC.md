# Mockup → Symfony app sync

The static mockup (`mockup` branch, `public/mockup/`) is where the client's changes are agreed first.
This file tracks which mockup commits have been ported into the Symfony app on `main`.

## Sync marker

| | Commit | Date |
|---|---|---|
| Fork point (main == mockup) | `7b4ed70` | 2026-10-03 |
| **Mockup reviewed up to** | `84d136d` | 2026-11-12 |
| **Mockup ported up to** | P1–P8 + f556ee8, 84d136d (see rows marked done); not yet a contiguous commit | 2026-11-14 |

**When new mockup commits arrive:** `git fetch maxemeauto && git log --oneline 84d136d..maxemeauto/mockup`
(replace `84d136d` with the "reviewed up to" hash above), add the new rows below, then move the marker.

Status: **todo** · **partial** (some of it already in main) · **done** (ported, with main commit) · **in main** (main already had it) · **n/a** (mockup-only: seed data, localStorage, comment overlay, pull page, docs, fixes that made the mockup copy main) · **superseded** (a later mockup commit replaced it; port the later one).

## Not to port (n/a)

`f2bb015` `9c71411` `65847ce` (mockup scaffolding) · `e1f2511` (mockup copies main) · `fd6ada1` `61ac1c0` `c99e91b` `0de84ca` (mockup-only fixes) · `8d5b1a8` (comment overlay) · `2df572a` · `90cb27a` `0980ee1` `769d354` `b0ebe82` (seed data) · `c149120` `007f3dd` `b45fbc8` `57400e6` `ff92a13` (docs, copied below as reference) · `bd48fc4` `5a01ebc` `b2e877d` `7228ae5` (pull page) · `96a01d1` (no-cache .htaccess) · `660d3e2` (mockup version stamp) · `f810f86` (removes tech notes/result — main never had them) · `0609b62` (Week view back — main never removed it) · `faece1e` `3b89f79` (calendar resize / text size — main already does this) · superseded board steps `c60f3f6` `17537c4` `758f5ce`.

## Commits to port

| Mockup commit | Change | Status | Batch |
|---|---|---|---|
| a38e7a2 | RO pickup-address picker (client's address book, default none per aa322bb); quote title "QUOTE / 報價單"; CSV export on remaining lists (FEATURES G1, G6, G7) | done — quote title 2b53d29; pickup address + Export CSV on the remaining lists ccf56b6; Reminders CSV d55340b | P5 |
| 898d57e | Remove the description line under page titles (≈21 list/config pages) | done 2b53d29 | P1 |
| 1b7f972 | Reminder Todo's + Service Reminder Queue merged into one paginated "Reminders" page | done d55340b | P6 |
| deba1ef | Appointments: 4th "List" view (paginated, filterable table) | done 2b53d29 | P3 |
| 3d5095e | Staff can hold several roles (checkboxes; permissions = union) | done 5ddbada | P7 |
| 169b599 | Govt-fee/Discount lines default to charge-through; per-service Technician + Work status; RO Recommendations copied to invoice | done — per-service technician + work status e2dc368; charge-through default 564f67a; recommendations on the work order and invoice ccf56b6 | P5 |
| 858860a | Master Technician = active users with the Technician role; Settings › Technicians tab removed | done e2dc368 | P5 |
| 7edd4f7 | No gap between document band and first table | done 2b53d29 | P2 |
| 051cc07 | Appointment ↔ repair order links (calendar popup wrench, list column) | done 2b53d29 | P3 |
| d0b772c | RO form section "Visit" → "Status" | done 2b53d29 | P1 |
| aa322bb | Repair Order Board kanban (drag to change status); Reminders period filter + Phone/Email status; quote Approve/Decline; calendar default (→ Day) | done — board cf7874a; quote Approve/Decline ae378a1; Reminders period filter + Phone / Email status d55340b | P6 |
| e516c4a | Unnamed clients still get a link label (preferred name → phone → email → Client #id) | done 2b53d29 | P1 |
| 8ef5e05 | Unit of measure L (Litre) | done 2b53d29 | P1 |
| 1cb62e9 | Movement History date filters stacked | done 2b53d29 | P1 |
| 41ebdc0 | Stock Adjustment as one form (product, qty, ref, note, when, location, reason) | done ecebd48 | P8 |
| 38af4be | Document action bar: own actions first, then PDF, then other documents | done 139c22b (the other documents moved to the switcher per 345c037) | P4 |
| 9f94e48 | Slim scrollbars without arrows (Firefox fallback) | done 2b53d29 | P1 |
| 8c0ad56 | Board columns = exactly the 9 RO statuses (Cancelled included), help text per column | done cf7874a | P4 |
| 551ab32 | Extended only after arrival; RO list Appointment Status column + filter; client tab "Work Orders" → "Repair Orders" | done 2b53d29 | P3 |
| e437ffc | RO status estimate_approval label → "Pending on Estimate" | done 2b53d29 | P1 |
| 7bd9227 | Board filtered by appointment date (default today); stock reason "Purchase" first | done — board cf7874a; Purchase reason first ecebd48 | P8 |
| e0fde70 | Client profile: 4-col form, Address Book tab, Appointments card, RO + $ columns; Movement History product type-ahead, lot/serial columns removed; "Book Appointment" | done — client profile 2b53d29; Movement History type-ahead, From/To/Lot/Serial columns removed ecebd48 | P8 |
| d3375af | RO form: Tag/Key under Odometer, no extra Status label | done 2b53d29 | P1 |
| 345c037 | Dashboard landing page; multiple payments per invoice (auto Paid); one-row document header switcher; quote status line; WO row height; role matrix View/Create/Edit badges | partial — quote status line ae378a1; one-row header + switcher + More menu 139c22b; WO five-line rows already in 2b53d29; role matrix badges 5ddbada; left: dashboard, payments (P10) | P10 |
| 2371126 | RO appointment rows: Duration (h), default 2 h, labelled fields | done 2b53d29 | P3 |
| 0a4baef | "Out of date" badge + Sync Repair Order on an unpaid invoice | todo | P10 |
| 6611dd0 | Restricted issued-invoice edit (qty down only, paid = locked); partial invoicing (Ordered/Charged/Remaining) | todo | P10 |
| 8b30420 | Audit fixes (documents, RO, scheduling, parts, people) — see section below | todo | see below |
| 34181b0 | Round-2 fixes (invoice/RO status rule, tax bases, add paths, delete guards) — see section below | todo | see below |
| 3dddb7b + 6bfe69e | 6-digit numbers on RO, QO, WO, INV (RO-020046) | done 2b53d29 | P1 |
| 55f432c | GST % / PST % editable per RO in the Totals box | done 564f67a (whole percent, as the rates are stored) | P5 |
| 9d04430 | RO Totals: "+ Add discount" only; existing fees kept | done 564f67a | P5 |
| ee7a843 | Paid (Invoiced) RO + its appointments locked except notes; refund unlocks; vehicle links on client appointments | done 8877be9 (vehicle links 2b53d29) | P5 |
| ca9d795 | Appointment form hides Delete for Completed | done 2b53d29 | P3 |
| 88ddf43 | Completed → "Issue the invoice now?"; Completed, Picked Up needs a live invoice | done 8877be9 (repair order page and board) | P5 |
| 689a4a1 | Appointment Type (Repair/Bodywork/Buying-selling/Other) with fixed colours; status colours; service colour removed | done 2b53d29 | P3 |
| 25368c7 | New repair appointment must pick an open RO or "+ Create new repair order" | done 2b53d29 | P3 |
| 9ba2951 | Buying/selling + Other appointments need no vehicle (vehicle_id nullable) | done 2b53d29 | P3 |
| e533d51 | Print: black-and-white header, company line on one line, vehicle on one line, max 2 phones, "KM / MILES" | done 2b53d29 | P2 |
| 18acc2d | Calendar opens on Day (Today already works everywhere) | done 2b53d29 | P1 |
| cc1f585 | Stock adjustment product type-ahead (SKU, name, category, vendor) | done ecebd48 | P8 |
| d6f741f | Drag preview: red line + landing time | done 2b53d29 | P3 |
| 258fdc2 | "+ Create new client" from the client picker, continue to the appointment/RO/reminder | done b9001e9 | P7 |
| 649ae8e + 4e14fb6 | Invoice "Remark" (internal, never printed) on builder + issued edit; Products filter row (Type/U-M dropdowns) | todo | P9 |
| df38f34 + d16ca79 | Payment report Remark column (wide) with pencil → edit popup | todo | P9 |
| 986d771 | Work order: no concern/authorisation/signature, "Assigned Technician"; RO: Advisor hidden; appointment technician synced with RO; mileage unit KM/Miles | done — P2 parts in 2b53d29; Advisor hidden + appointment ↔ RO technician sync e2dc368 | P5 |
| 0ce22aa | Products: Source column removed | done 2b53d29 | P1 |
| d2fbfc8 | Invoice page shows the Remark card above the document (staff only) | todo | P9 |
| 8b7138d | "Save and add vehicle" after creating a client | done b9001e9 | P7 |
| c857353 … 28b649a | Payment report: multi-select payment methods (auto-apply 2.5 s), with-tax totals per method, Filter/Clear, columns only Subtotal/Taxes/Grand Total + Remark | todo | P9 |
| 77bc586 | RO "Concern" → "Internal Comment (staff only, never printed)" | done 2b53d29 | P2 |
| 1e3b16c | Client appointments: Repair Order button | done 2b53d29 | P3 |
| 33caa46 | Check-in button on the calendar popup and client's pending appointments | done 2b53d29 | P3 |
| f556ee8 | Work order "First Printed On" replaces "Last Modified On": set on the first print (or PDF), never changed (RO; invoice with no RO) | done 7381719 | P2 |
| 84d136d | New-appointment RO list = open orders only (estimate … awaiting parts) with status; a payment that makes an invoice Paid while its RO is still open asks "Complete and lock?" | done — RO list 355a9f8; "Complete and lock?" on the invoice page after it is saved Paid 8877be9 | P5 |

## Audit fixes inside 8b30420 / 34181b0

| Change | Status | Batch |
|---|---|---|
| RO is Invoiced only when a **Paid** invoice exists (from Completed / Picked Up); no paid invoice left → back to the earlier status; manual Invoiced refused | done 8877be9 | P5 |
| Discount capped at subtotal + fees; tax bases never negative (RO, issued edit, builder; JS warns) | partial — repair order done 564f67a (refused on save, JS warns); issued edit and builder with P10 | P5/P10 |
| Part lines taxed by the product's tax class (`sales_tax_code`) | done 564f67a (PartTaxes; repair order, invoices, prints, live totals) | P5 |
| Confirm before a second invoice; confirm before moving a RO with live invoices out of Completed/Picked Up/Invoiced | done 8877be9 (board cf7874a) | P5 |
| Cancelling a RO cancels its pending appointments | done cf7874a | P5 |
| Finished RO keeps its saved totals (no re-pricing on untouched save) | done 8877be9 | P5 |
| Issued-invoice edit: amount paid ≥ total; edits copy back to a finished RO | todo | P10 |
| Invoice totals box: method/amount/change only when paid, else Balance due | done 2b53d29 | P2 |
| Delete guards: tax class (services, products), labour/govt fee (RO lines), clients/vehicles in use, service reminder with queued entries | done 6b631b0 (clients and vehicles are only deactivated in main, history kept: no guard needed) | P7 |
| Client duplicate check (email, phone last 10 digits, name); VIN unique; mileage "88,000" → 88000 | done 6b631b0 | P7 |
| Phones shown as 604-285-8200 and stored as digits when edited; postal code "V6X 2P9" | done 6b631b0 | P7 |
| Appointment duration 0.5–24 h; past-day booking needs a yes; inactive vehicle already on the appointment/RO stays | done 2b53d29 (appointment; RO side with P5) | P3 |
| Start a RO from an appointment (`?appointment=`); RO save needs customer + vehicle; inactive technician kept as "(no longer active)" | done — inactive technician kept e2dc368; ?appointment= and customer + vehicle required ccf56b6 | P5 |
| Reminder queue: 90-day skip with reason; entry keeps its template + message; resend keeps Booked/Resolved; buttons by status; no booking link → drop the whole line; View email shows the sent copy | done d55340b (the sent copy is kept on the queue entry, not via email_log) | P6 |
| Payment report = paid invoices only, unpaid listed separately; revenue split with Fees column; Service report = finished work only; report default period falls back to newest month | todo | P9 |
| Vehicle page stats count finished ROs only | done 6b631b0 | P7 |
| No duplicate names: services, service categories (same parent), reminder templates | done 6b631b0 | P7 |
| Product category can't move under its own descendant | done ecebd48 | P8 |
| Inactive client profile shows a banner + Reactivate | done b9001e9 | P7 |
| Logs use one date style `m/d/Y h:i A` | done 2b53d29 | P1 |

Extra data-model needs from these: `maxeme_service_reminder_queue.template_id` + `message` + `sent_subject` / `sent_body` (done, Version20261114140000); `maxeme_invoice_service_line.kind` + `incl_parts`.

## Data-model changes needed

- `repair_order` / `invoice`: `wo_first_printed_at` (done, Version20261114090000).
- `repair_order`: `quote_approved_at`, `quote_approved_via`, `quote_declined_at` (done, Version20261114100000).
- `repair_order`: `pickup_address_id` (→ client_address, SET NULL), `recommendations` TEXT (done, Version20261114130000); `status_before_invoiced` (done, Version20261114120000); `mileage_unit` (done); `master_technician_id` re-pointed to `admin_user` (done, Version20261114110000).
- `repair_order_job`: `technician_id` (→ admin_user), `work_status` (not_started / in_progress / done) (done, Version20261114110000).
- `maxeme_appointment`: `appt_type` (repair / bodywork / buy_sell / other, default repair), `vehicle_id` nullable, optional `technician_id` (done); `colour` no longer used.
- `maxeme_service_item.own_colour`: no longer used.
- `invoice`: `admin_note` (Remark), `vehicle_mileage_unit`; new table `invoice_payment` (backfilled from `payment_amount`); `invoice_service_line.continued`.
- `vehicle.mileage_unit`; `reminder.phone_status` (+ `delay_note`, `delayed_at`) and `service_reminder_queue.phone_status` (done, Version20261114140000).
- Reference data: unit of measure L (done); inventory adjustment reason `purchase` (sort before the others; done, Version20261114150000).
- No schema change: several roles per user (roles is already a JSON array), 6-digit numbers, the GST/PST per RO (whole-number rates; decimals would need a migration).

## Batches (suggested order)

- **P1 Quick wins:** labels, 6-digit numbers, Day default, Source column, page leads, client display name, unit L, small CSS.
- **P2 Printed documents:** B/W header, one-line company/vehicle, 2 phones, KM/MILES, work order without concern/signature, Assigned Technician, Internal Comment never printed.
- **P3 Appointments:** types and colours, optional vehicle, pick/create RO, check-in, list view, RO links, duration, drag preview, Extended rule, client profile appointment columns.
- **P4 Board and document header:** kanban by RO status with drag, appointment-date filter, action bar order, one-row switcher, quote Approve/Decline.
- **P5 Repair order rules:** paid lock/unlock, invoice at completion, discount only, GST/PST per RO, technicians as users, job technician/status, recommendations, pickup address, mileage unit.
- **P6 Reminders:** one merged page, period filter, phone/email status.
- **P7 People:** several roles, create client from picker, save and add vehicle.
- **P8 Stock:** one-form adjustment, product type-ahead, Purchase reason, Movement History tweaks.
- **P9 Invoice Remark and Payment report.**
- **P10 Payments and invoicing:** multiple payments, restricted edit, partial invoicing, out-of-date sync; Dashboard.

Client decisions behind these changes: `git show mockup:docs/feedback/decisions-needed.md` and `git show mockup:docs/feedback/triage-2026-10.md`.
