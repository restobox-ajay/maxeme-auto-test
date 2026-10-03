# Maxeme Auto — Feature status

Each item in `SPEC.md` checked against `main` at `7b4ed70` (2026-10-03), by reading the code rather than the commit messages. Decisions behind the **Changed** rows are in `DECISIONS.md`.

**Done** = built as specced. **Partial** = built, with a gap. **Changed** = built differently on purpose (see ADR). **Missing** = not built.

## Open gaps

| # | Gap | Spec | Where |
|---|-----|------|-------|
| G1 | Repair Order has no pickup-address picker; only the blank work order has one | §3 | `repair_order/form.html.twig`, `RepairOrder` (no address field) |
| G2 | Delete without confirm: the RO appointment-row Remove, and line/charge Delete on the issued-invoice edit page. `DangerousActionsAskFirstTest` only scans `.js-post-action` | §3 | `maxeme-repair-order.js:175`, `maxeme.js:367`, `invoice/issued_edit.html.twig:64,79` |
| G3 | The Customer search box matches `inv.id` only, so invoices renumbered with Change Invoice # aren't found (the Invoice # box finds them) | §2 | `ClientRepository::inView` |
| G4 | No separate "Add Canned Service" button; one catalogue search covers both | §7 | `repair_order/form.html.twig` |
| G5 | No Authorizations entity or approval log; "authorized" is just an RO status change in the audit log | §7 | — |
| G6 | Quote PDF is titled "QUOTE" only; 報價單 is missing. Quote → Sales Order → Invoice as three documents isn't built | §8 | `DocumentKind::title()` |
| G7 | CSV export is missing on service categories, reminders, service reminders, staff, settings lists, vehicle history and client profile tabs | §14 | 11 lists have it |
| G8 | Govt fees: the auto-fee hook exists (`GovtFeeRule`, `GovtFeeRules::feesFor`), but no rule is written and nothing calls it | §6 | `src/Maxeme/Fee/GovtFeeRules.php` |
| G9 | Service grid inline edit covers name, label, price and tax class, not colour or category | §4 | `maxeme_service_inline` |
| G10 | Nothing in this snapshot turns `shipped` holds into an actual stock decrement | §11 | `RepairOrderStockSubscriber` |
| G11 | Test coverage is thin: 21 files in `tests/Maxeme`, none on the reminder queue, stock buckets, charge-through totals, GHL, RO, documents, board or reports | — | `tests/Maxeme` |

## §1 Auth, logging, roles

| Requirement | Status | Evidence |
|---|---|---|
| wholesale-b2b base | Done | `src/Maxeme`, `config/packages/maxeme.yaml` on core · e299e6d |
| Logging | Done | `src/Maxeme/Audit/*`, Activity / Email / Error Log · 0927cd2, 965eb75, d680296 |
| SQLite | Done | migrations; phpLiteAdmin console `admin_db_console` · 5f95489 |
| 7 roles | Done | `Security/StaffRole.php`, `config/rbac/role_permissions/*` · 6323129, 16364c4 |
| Legacy-like colours | Done | `public/assets/css/maxeme-theme.css` · ab881df |

## §2 Sidebar and top bar

| Requirement | Status | Evidence |
|---|---|---|
| All nav in the sidebar; top bar = My Profile / Logout | Done | `maxeme.admin_menu`, `MaxemeAdminMenuProvider` · b5b7fe3, 1e87372 |
| Search: Client box (1 → client, n → list) | Partial | `ClientController::search` · 2100e03 — G3 |
| Search: Vehicle box | Done | `VehicleController::search` · 2100e03 |
| Search: Invoice # box | Done | `InvoiceController::find` (custom #, then id, then prefix) |
| Menu groups | Changed | ADR-003 |
| Brand → Day Agenda | Done | `HomeController` → calendar, day view |
| Parts Inventory + physical count | Changed | core products + InventoryDepth adjust/count · ADR-002 |

## §3 Client

| Requirement | Status | Evidence |
|---|---|---|
| JS confirm on every delete | Partial | `.js-post-action` confirm + test · 4ee43e5 — G2 |
| Yellow Check-in removed | Done | 18c81ac |
| Phone Number 1–4 | Done | `Client` phone1–4 · 0da80d4 |
| Address book, unlimited, user order; top 2 on profile | Done | `ClientAddressController` (reorder) · 0da80d4 |
| RO address picker in book order | Missing | G1 |
| Shared notes (author, message, time; edit, delete) | Done | `AbstractNoteController`, `_notes.html.twig` · 0da80d4, b5e049a |
| List: top buttons, CSV of current view (all pages) | Done | `maxeme_client_export` · 2d55d70 |
| List: phones in one cell; second-row filters (names, email, 4 phones, all addresses, note) | Done | `ClientRepository::FILTERS` · 4f21de1 |
| List: View button, names link | Done | e3414c5 |

## §4 Services

| Requirement | Status | Evidence |
|---|---|---|
| List: CSV, name filter, Name / Label / Price / Tax Class / Colour | Done | `maxeme_service_*` · dd21402, ac4cc2c, fd5200d, 3e7bf64 (path is `/admin/services`) |
| Colour inherits from the category | Done | `ServiceItem::getColour()` · fd5200d |
| In-page grid editing | Partial | `maxeme_service_inline` · cc6860a — G9 |
| Service lines 1:M, 5 types, negative discount, line editor, Add line | Done | `ServiceLine`, `ServiceLineType` · 7872019, f780ad1 · ADR-013 |
| Charge through (shown and added / hidden) | Done | `RepairOrderCalculator`, `PrintedDocuments` · d9c3627, 6eaeb5a |
| Service Category: hierarchy + colour | Done | `ServiceCategory` · 7872019 |

## §5 Service reminders

| Requirement | Status | Evidence |
|---|---|---|
| Service Reminder list / add | Done | `maxeme_service_reminder_*` · 7872019 |
| Templates: name, subject, body, tags, code editor | Done | `ServiceReminderTemplate` · 7872019 |
| Tap to call; booking link set by the shop | Done | Settings › Reminder Emails · e2ca80d |
| Queue on RO complete; 60/90-day rule | Changed | `RepairOrderCompletionSubscriber`, `ServiceReminderQueue::queueFor` · 2450ec2 · ADR-008, ADR-009 |
| New appointment → Booked | Done | `ServiceReminderQueue::bookFor` · 2450ec2 · ADR-008 |
| Queue table, statuses, actions (view email, resend, Book, Resolved) | Done | `reminder_queue/*` · 2450ec2, b8f8d32 |
| Sending | Done | `app:maxeme:send-service-reminders` (needs the daily cron on the server) |

## §6 Labour, Govt Fees

| Requirement | Status | Evidence |
|---|---|---|
| Labour CRUD with all fields | Done | `Labour`, `maxeme_labour_*` · 7872019, 2d20111 |
| Govt Fees CRUD with all fields | Done | `GovtFee`, `maxeme_govt_fee_*` · 7872019, 95856f8 |
| Code that adds fees automatically | Partial | G8 |

## §7 Repair Order

| Requirement | Status | Evidence |
|---|---|---|
| RO is the hub | Done | `RepairOrder`, `maxeme_repair_order_*` · 60ccb49, 07695c5 · ADR-016 |
| Jobs = services, no C/C/C, advisor, tag/key, notes, all fields optional | Done | `RepairOrderJob`, `RepairOrderWriter`, `RepairOrderNote` |
| Promised | Changed | per appointment · ADR-005 |
| Custom fee / discount lines; GST and PST separate | Done | `RepairOrderCharge` · 6eaeb5a |
| Repair Name + Concern | Done | `ScheduleCalendar::event()` uses the name |
| Add Service / Add Canned Service | Partial | G4 |
| Service loads its lines; 5 types; price from the default; qty only on lines | Done | `maxeme-repair-order.js`, `maxeme-service-lines.js` · f780ad1 |
| Header incl. 9 statuses; estimate_approval set on send | Done | `RepairOrderStatus`; set by emailing the quote |
| Appointments 1:M with promised time, check-in, delete, add | Changed | inline edit · ADR-006 |
| Audit log for the RO and its children | Partial | `RecordHistory` · 069b060, 6ed1623 — G5 |
| Quote / Invoice / Work Order bar | Done | `repair_order/_nav.html.twig` |

## §8 Documents

| Requirement | Status | Evidence |
|---|---|---|
| Doc bar (PDF menu, Go to / PDF of the other two) | Done | `DocumentActions`, `document/page.html.twig` · 07695c5 |
| Save and Email (Zoho-style) | Done | `document/email.html.twig`, `DocumentMailer` |
| WO: phones, Address:, Master Technician (blank line), KM / Miles | Done | `document/_work_order.html.twig`, `_mileage.html.twig` · c469cab |
| Invoices tab: Issue Invoice, Date / Amount / Status / actions | Done | `repair_order/invoices.html.twig` · 07695c5 |
| Invoice and quote content (service names, charge-through, totals) | Done | `_sales_document.html.twig` · d9c3627 |
| Save as Quote | Partial | 2f67e84 — G6 · ADR-014 |

## §9 Appointments and calendar

| Requirement | Status | Evidence |
|---|---|---|
| Appointment page and actions | Done | `schedule/form.html.twig` · 6490ee2 |
| Six statuses | Changed | stored value `complete` · ADR-007 |
| Completed when the RO completes | Done | `RepairOrder::setStatus()` · ADR-009 |
| Calendar text, hover-to-top, no yellow Check-in | Done | `ScheduleCalendar`, `maxeme-theme.css` · 18c81ac |
| Colour by service, per-appointment override | Changed | on the service, not a Settings page · ADR-012 |

## §10 Vehicles

| Requirement | Status | Evidence |
|---|---|---|
| Sidebar entry, list of all vehicles (+ CSV) | Done | `maxeme_vehicle_index` · e6392bd |
| Vehicle page: stats, RO history, service and category filters, notes | Done | `vehicle/show.html.twig` |
| Full add / edit page, re-assign client | Done | `vehicle/form.html.twig` |

## §11 Inventory

| Requirement | Status | Evidence |
|---|---|---|
| Keep the core inventory screens | Done | `core_route_permissions` · 4556b57, 3242179 · ADR-002 |
| RO Parts lines use the catalogue | Done | `AbstractServiceLine::$product` · 4556b57 |
| Stock buckets by RO status; parts added later | Done | `RepairOrderReservationSubject::bucketFor` · b563b5f · ADR-010 — G10 |

## §12–13 Settings, GHL

| Requirement | Status | Evidence |
|---|---|---|
| Tax Classes, Tax Rates, Doc Prefixes, Technicians, Payment Types | Done | `maxeme_settings_*` · bbb57ac, e51204e |
| GHL: find or create the contact, add `send-review-request` | Done | `Ghl/ReviewRequestHandler`, `GhlClient` · b4f9a58 (async; skipped when no token or no client email) |

## §14–18 Reports, board, accounting, access, logs

| Requirement | Status | Evidence |
|---|---|---|
| Period filter with presets | Done | `report/_period.html.twig`, `ReportPeriod` · 7f765fe |
| CSV on every report and view | Partial | G7 |
| Payment Report | Done | `PaymentReport` · 7f765fe · ADR-015 |
| Service Report | Done | `ServiceReport` · 7f765fe |
| Work Order Board | Changed | stages derived · bd9ddb4 · ADR-011 |
| Change Invoice # / Work Order # | Done | `DocumentNumberEditor` · f76564f |
| Role matrix | Done | `role_permissions/*`, `RolePermissionMatrixTest` · 6323129, e873e2a · ADR-004 |
| Audit log on every entity | Done | core audit log + `AuditLogEnricher`, Logs buttons · 0927cd2 |
