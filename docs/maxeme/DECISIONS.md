# Maxeme Auto — Decisions (ADR)

Short records of where the build departs from, or reads into, `SPEC.md`. Each one: what was decided, why, and where it lives. Written 2026-10-03 from main's code; "Accepted" means it's live on main. "Ask client" marks a decision the client should confirm.

## ADR-001 Build on wholesale-b2b core
Accepted. Maxeme is a layer on the core (`src/Maxeme`, `templates/maxeme`, `config/packages/maxeme.yaml`, `config/routes/maxeme.yaml`). `app:maxeme:setup` switches off the B2B modules the shop doesn't use. Keeps upstream core fixes mergeable. · e299e6d

## ADR-002 Parts Inventory = core catalogue + InventoryDepth / Procurement
Accepted. The spec's single "Parts Inventory" page became the core screens: Products, Stock Adjustment (which includes cycle count), Movement History, Product Import, Categories, UoM and Vendors. They replace the first Maxeme-only physical-count page. One stock engine for RO holds and adjustments. · 3242179 → 4556b57

## ADR-003 Sidebar differs from the spec's menu
Accepted.
- "Summary Report" is labelled **Payment Report**, and a **Service Report** sits beside it (spec §14 asks for both reports).
- **Settings** is its own group, with one row per tab.
- Rows added to existing groups: Work Order Board, Service Reminder Queue, Vehicles, and the service set-up lists under Config.

· 7f765fe, e51204e

## ADR-004 RBAC wider than the §17 matrix
Ask client. The matrix is implemented exactly (`config/rbac/role_permissions/*`). Areas outside it work like this:
- Settings, Roles, Activity / Email / Error Log and the Database console are open only to Super Admin and Tech Support.
- Shop Admin (`ROLE_SHOP_ADMIN`) also manages staff.
- Tech Support can't be assigned from Manage Admins.

Confirm that a shop Admin shouldn't reach Settings. · 6323129, e873e2a, cbe62f1

## ADR-005 Promised time belongs to each appointment
Accepted. The spec lists "Promised" on the RO header but also pairs each appointment with its own promise (§7 Appt 1/Promised 1…). Stored as `Appointment::$promisedAt`, with no header field.

## ADR-006 RO appointments are edited inline
Accepted. Current appointments on the RO are edited inline rather than through an [Edit] button. Check-in shows only while the status is `new`; past rows are read-only.

## ADR-007 Appointment status value `complete`
Accepted. The stored value stays the legacy `complete` (labelled "Completed") so imported appointments need no rewrite. The other five values match the spec.

## ADR-008 Reminder queue spacing and booking
Accepted, ask client about the 60–90 day window.
- Spacing: after the first Queued entry, a later service is Queued only if it's more than 90 days after the last queued one; otherwise it's Skipped. The spec leaves 60–90 days open, and those are skipped too. `SKIP_WITHIN_DAYS` is only descriptive.
- Booked: set when any new appointment is created for the same client and vehicle, including past-due unsent entries. The queue's Book button just opens a new RO.

· 2450ec2, b8f8d32

## ADR-009 "Work done" covers Completed, Picked Up and Invoiced
Accepted. These side-effects fire once per RO on the first of the three statuses:
- reminder queueing
- stock moving to shipped
- the GHL review tag
- appointments set to complete

Legacy-converted ROs (`holdsStock` off) are excluded so imports don't trigger emails or stock moves.

## ADR-010 RO status → stock bucket
Accepted. `authorized` → sales_hold; `in_progress` and `awaiting_parts` → approved; completed and later → shipped; estimate and cancelled → released. Every Parts line holds stock whether or not it's charged through. Any line change reconciles the whole RO into its current bucket. · b563b5f

## ADR-011 Work Order Board stages derived from RO status
Accepted. Stage isn't stored:
- **Check-in vs Repairing** depends on whether a master technician is set ("work order issued").
- **Completed (Paid)** = `invoiced`, which is set when all of the RO's invoices are paid.

Defaults to the last 30 days by RO date. · bd9ddb4

## ADR-012 Calendar colour set on the service, not a Settings page
Accepted. Colour lives on Service Category, can be overridden per Service and again per appointment. This covers the spec's "service → colour" settings without a separate page. · fd5200d

## ADR-013 Service lines use typed references
Accepted. Instead of a generic `entity_id`, each line references labour, product or govt fee by its own key. Sublet is a Labour row with `is_sublet`. Gives real foreign keys and per-type search. · 7872019, f780ad1

## ADR-014 Save as Quote is a PDF, not a document type
Accepted for now, ask client. Save as Quote produces the RO quote PDF ("QUOTE" title). Quote → Sales Order → Invoice as three separate documents (the client's note on item 13) isn't built, and 報價單 isn't on the title yet. · 2f67e84

## ADR-015 Payment Report date = invoice payment date field
Accepted. Rows are dated by `Invoice.lastModified`, which is set when the invoice is first paid or typed in by hand. A hand-typed date can therefore pull in an unpaid invoice. · 7f765fe

## ADR-016 Legacy invoice and work order pages kept
Accepted. The appointment-based invoice builder, print and work order pages stay alongside the RO so imported history opens as it did. New work starts from the Repair Order. · 07695c5
