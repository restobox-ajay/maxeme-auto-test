# What the app has that the mockup does not (2026-11-23)

The rule: every page and the whole flow follow the mockup (`maxemeauto/mockup`, unchanged since `84d136d`) plus the
client's comments on it, with nothing extra. This lists what the app has beyond that. Nothing was changed.

How it was checked: the app's sidebar against the mockup's `layout.js` menu; every `/admin` route against the
mockup's `sitemap.html`; then each of the mockup's 70 pages (its HTML and inline script) against the app's template,
controller and page script. Before an item was counted as extra, `DECISIONS.md`, `FEEDBACK-CHECK-2026-11-17.md`,
`VISUAL-CHECK-2026-11-*.md`, `TEST-FLOW.md`, `MOCKUP-SYNC.md` and the git log were searched for a comment or
decision that asked for it. Unless marked otherwise, nothing asked for any item below.

## 1. Menu and pages

- **Sidebar**: same sections, items and "+" shortcuts as the mockup. One difference: Repair Order "+" opens
  New Repair Order directly; the mockup's goes to Choose a client first.
- **Core platform pages still open by URL** (hidden from the menu, not switched off): about 150 routes, among them
  Orders, Quotes (`/admin/estimate`), Credit Notes, Sales Returns, Customers (`/admin/company`), Warehouses, Price
  Lists, Carts, Backorders, Payment Terms, Shipping Zones, Email Templates, Custom Fields, the core Settings and User
  Manager, and the bundle pages (Procurement: purchase orders, bills, RFQs, receiving, AP aging; Warehouse Ops; Lots,
  Bins, Cycle Count, Low Stock; Fees; Shipping; Tax; News; Barcodes). WooCommerce answers 404 (switched off). The
  Maxeme pages link to none of them, but some core pages do (below).

## 2. Schedule

| Page | Extra in the app | Where |
|---|---|---|
| Appointments › List | Per-day list with ‹ › day steps, "All days" and a date picker (the mockup's list is one list; ‹ › hidden there) | `schedule/calendar.html.twig:28-39` |
| Appointments › List | "For" (type) column and filter (mockup: type badge in the Repair order column); Vehicle as a link; Check-in and Edit buttons | `calendar.html.twig:51,56,74,77,94-98` |
| Appointments › calendar | "+N more" opens a popover (mockup switches to Day / List) | |
| Repair Order Board | Hint "Drag a card to another column to change its status."; "Services done" tooltip | `board/index.html.twig:65,48` |
| Repair order form | Confirm before deleting a filled service line or discount row (mockup removes at once); "Yes, move it anyway" checkbox where the mockup uses a dialog; Recommendations hint adds "printed on the work order and" | `maxeme-service-lines.js:69-75`, `maxeme-repair-order.js:38-43`, `repair_order/form.html.twig:63-66,189` |
| Repair order › Invoices | "Charged so far" extra sentences, "(fee / discount)" tag, ✓ marks; Issue-another modal intro and "Nothing remains to charge" text | `repair_order/invoices.html.twig:83-114` |
| Quote / Invoice document | "Out of date" banner with explanation (mockup: Sync button only; partly from mockup `0a4baef`); Approve / Decline dialog hints; Remark edited in place (partly from P19) | `document/page.html.twig:74-83,161,163` |
| Edit issued invoice | Hint paragraphs ("An issued line keeps…", "Payments are recorded under Payment ▾…") where the mockup has Paid / Balance due fields | `invoice/issued_edit.html.twig:20,33` |
| Appointment form | "(after check-in)" on the disabled Extended option | `schedule/form.html.twig:90` |
| Choose a client | "N matches for 'x'" bar with Clear search | `_search_results.html.twig` |

## 3. People and Accounting

| Page | Extra in the app | Where |
|---|---|---|
| Client List | Preferred name is a link | `client/index.html.twig:61` |
| Client profile | Inactive banner adds "or the searches. Their history is kept."; Appointments card vehicle link and type label | `client/show.html.twig:12,80` |
| Client › Vehicles tab | Two hidden Add / Edit vehicle dialogs no button opens (dead markup) | `client/show.html.twig:155-185` |
| Client › Appointments tab | "+ Repair Order" button on rows without one; "Work Order" button for roles without accounting; type label in place of "—" | `client/_appointments.html.twig:24,37-45` |
| Invoices list | "Out of date" badge and "Sync Repair Order" (mockup `0a4baef` has them on the repair order's Invoices tab only) | `invoice/index.html.twig:35,37` |
| Invoice builder | Payment method select, paid amount, "Paid" checkbox and "Change:" row (mockup: read-only Paid / Balance due with "Add payment · View payments"); KM / MILES select (mockup: fixed KM); confirm before removing a part row; last row cannot be removed | `invoice/form.html.twig:75,117-127`, `maxeme-invoice.js:93-109` |
| Printed invoice | Remark card (mockup `d2fbfc8` has it on the repair order document only) | `invoice/_document_page.html.twig:35-37` |
| Add payment | "Choose…" empty option (mockup preselects a method) | `invoice/payment_form.html.twig:76` |

Match: Vehicles, Vehicle, Vehicle form, Payment Report, Service Report, Payments, sidebar search boxes.

## 4. Parts & Service (mostly core platform pages)

| Page | Extra in the app | Where |
|---|---|---|
| Products | Lead paragraph (mockup `898d57e` removed page leads); "Custom Fields" in Choose Columns; Private customers as chips | `admin/product/detail.html.twig:18,124,200` |
| Products › Pricing | Core price-list page: price group chooser, a column group per price list, bulk apply, Category column and filter (mockup: ID / Name / SKU / Tax Class / Cost / Original / Sale / Deposit / Margin) | `admin/product/prices.html.twig` |
| Products › Inventory | Warehouse chooser, its own column chooser, a column group per warehouse, Category column and filter | `admin/inventory/index.html.twig` |
| Product form | One stock box per fulfillment region (mockup: Main); Private and Visible change each other; Fees, Custom Fields and locked-unit sections when those exist | `admin/product/form.html.twig` |
| Product Lookup, Stock, Stock Adjustment, Movement History | Back goes to the Inventory Depth hub (not a mockup page); Lookup suggests as you type | `InventoryDepthBundle/templates/_nav.html.twig:13` |
| Stock page | Lots / Serials card, Reorder Status card, Dimension / Required rows, Order / Purchase / Shipment / Credit Memo filters, when that data exists | `stock_by_product.html.twig` |
| Stock Adjustment | Bin select; "Which stock / units" pickers and "Add ten more rows"; picking-order and search hints; "Already reversed" column; lot / serial / expiry inputs when tracked | `adjust.html.twig` |
| Product Import | "Import log" opens the core Imports page (Kill Process, Process Queue, more columns) where the mockup has a modal | `admin/product/import.html.twig:13` |
| Import mapping | A "Price: <list>" field per price list and fee columns, when those exist | `ProductImportDefinition.php:69-82` |
| Category form, Vendor form | Custom Fields section when fields exist; vendor form hint for an old free-text payment term | |
| Units of Measure | "Filter" button beside Clear | `unit_of_measure/index.html.twig:87` |
| Vendors | Back goes to the Procurement hub; "Addresses" opens a separate page; Remit-To expand button | `ProcurementBundle/templates/_nav.html.twig:22` |
| Vendor page | ⋯ menu links to Purchase Orders and Create Purchase Order; "View all bills" and "Aged breakdown" link to Bills and AP Aging (the mockup shows a toast that the shop doesn't use them); Address Book and Add Address as separate pages | `ProcurementBundle/templates/admin/company/detail.html.twig:58-59,152` |

Match: Services, Service form, Product Categories, UoM form.

## 5. Config, Settings, Logs, Account

| Page | Extra in the app | Where |
|---|---|---|
| Edit user | Roles shown as a disabled text box when the account holds a role that can't be assigned here | `staff/edit.html.twig:22-23` |
| Settings › Tax Classes, Payment Types | Card titles the mockup doesn't draw (removed elsewhere by P11) | `settings/tax_classes.html.twig:8`, `payment_types.html.twig:8` |
| Activity Log (record history) | Lead line "Who changed what, and when…" (`069b060`) | `activity_log/index.html.twig:7` |
| Error Log | Method / Summary show request method / URL (mockup: level / area); File and Line above the message; "(#id)" after the user | `error_log.html.twig:61-74` |
| Email Log, Error Log | Back goes to the dashboard (mockup: Appointments) | |
| Database Console | Back goes to the core Settings page (mockup: Maxeme Settings) | `admin/database/console.html.twig:12` |
| Check your email | Says "If the username or email address matches an active account…" and the once-every-N-hours line, not the address (security fix `c9edab4`) | `resetting/check_email.html.twig:5-6` |

Match: user menu, Manage Admins, Roles, Role detail, Service Categories and form, Service Reminders, Reminder
Templates and form, Labour, Government Fees, Tax Rates, Doc Prefixes, Reminder Emails, Activity detail, Profile,
Edit Profile, Change Password, Log in, Forgot / Reset password.

## Found on the way: the mockup has, the app does not

Appointment edit page Check-in button (mockup `33caa46`); Client › Repair Orders tab search box; Vehicle ›
Appointments paging over 10 rows; Service Report "latest finished RO" hint and entry count; Issue-another modal
Ordered / Charged columns and "Show lines already charged"; Edit issued invoice Paid / Balance due and Add payment;
Activity detail Record link; Database Console "Switch off now"; Email Log Module options "Service Reminder" / "Other"
and per-page select; Vendors row "Logs"; Appointment Status sort on Repair Orders.

## To do (agreed 2026-11-23, one commit each, checked in Chrome)

Done: invoice builder Mileage is a fixed unit label showing the invoice's own unit (KM, or MILES when saved
in miles) (`e2ca759`).

1. **DONE** (see the commit "Close the platform pages the mockup does not have"). **Switch off the platform pages the mockup doesn't have** (section 1): answer "Page not found" for the core
   pages (Orders, Quotes, Credit Notes, Sales Returns, Customers, Warehouses, Price Lists, Carts, Backorders,
   Payment Terms, Shipping Zones, Sales Tax, Email Templates, Custom Fields, Fulfillment Regions, Redirects,
   Front-end Menu, Connectors, Help, Onboarding, core Settings, User Manager, core Audit Log) and the unused pages
   of the two active modules (Procurement: purchase orders, bills, RFQs, receiving, debit memos, AP aging, vendor
   prices / returns / sheet import, hub; Inventory Depth: bins, cycle count, lots, low stock, pack conversion,
   shipments, tracking, hub). Nothing deleted. Keep what the mockup pages use (vendors, stock pages, products with
   Pricing / Inventory, imports). Then the links into them: vendor ⋯ Purchase Orders / Create Purchase Order,
   "View all bills", "Aged breakdown"; Back on Product Lookup, Stock, Stock Adjustment, Movement History and
   Vendors → Products; Database Console Back → Settings. As built: `maxeme.hidden_admin_pages` in
   `config/packages/maxeme.yaml`; lot / serial tracking pages left open for item 10; the vendor page's purchase
   order, bills and AP aging links show the mockup's messages ("… not used by the shop") instead of opening a page.
2. **LEFT AS IT IS** (user, 2026-11-23). **Appointments › List as the mockup draws it**: all appointments, "All appointments", newest first, 20 a page,
   no ‹ › day steps / All days / date picker; "today" fills the Date box; columns Date, Status, Client, Phone,
   Vehicle (plain), Repair order (type badge for non-repair), $, Description, Technician, Action; no "For" column
   or filter; actions Repair order / + Repair order, Client, Invoice, Delete (not on completed); no Check-in / Edit.
3. **DONE** (commit "Invoice builder: payments only through Add payment"). **Invoice builder totals**: read-only Paid and Balance due with "Add payment · View payments" ("Save first" on
   a new invoice) in place of Payment method / paid amount / Paid checkbox / Change; part "−" removes at once
   (no confirm, the last row too).
4. **DONE** (commit "Out of date, Sync and Remark only where the mockup has them"; the in-page Remark box on the repair order's invoice kept, user's choice). **Shown on more pages than the mockup**: Out of date badge and Sync Repair Order off the Invoices list (keep on
   the repair order's Invoices tab); Remark card off the printed invoice (keep on the repair order document);
   Out of date banner down to the mockup's Sync button.
5. **LATER** (details in "Later" below). **Hints and help text** the mockup doesn't have (sections 2–5): board drag hint and "Services done" tooltip,
   Approve / Decline hints, "Charged so far" extras and Issue-another intro, edit-issued-invoice hints, Recommendations
   hint wording, Extended "(after check-in)", Products lead paragraph, Activity Log lead line, client inactive
   banner extra sentence.
6. **LATER**. **Confirm dialogs** the mockup doesn't have: deleting a filled service line or discount row on the repair order.
7. **LATER**. **Small extra buttons and links**: client Appointments tab "+ Repair Order" and the role-based "Work Order";
   Choose a client "Clear search" bar; UoM "Filter" button; Client List preferred-name link; client profile
   Appointments card vehicle link; "Choose…" option on Add payment.
8. **LATER**. **Settings / Logs**: Tax Classes and Payment Types card titles; Error Log Method / Summary to level / area,
   File / Line and "(#id)" out; Email Log and Error Log Back → Appointments.
9. **LATER**. **Dead markup**: the two hidden vehicle dialogs on the client page.
10. **LATER**. **Platform extras on Parts & Service pages** (section 4): Products Pricing / Inventory tabs down to the mockup's
    columns; product form one Main stock box; Stock Adjustment, Stock page and Import log down to the mockup's.
    Biggest of the lot; to be planned page by page.
11. **LATER**. **Ask first**: Edit user roles fallback (a guard for roles that can't be assigned there) and the Check your
    email wording (security fix `c9edab4`) change behaviour, not just looks.

Then the other way round (the last section above): what the mockup has and the app lacks.

## Later (parked 2026-11-23)

Items 1, 3 and 4 are done (`a48e0cd`, `e2ca759`, `a9faecd`, `0d16f5b`). Everything below waits; take them one at a
time: explain, get the go-ahead, change, check in Chrome, commit.

### Item 5: hint and help text (text only; nothing works differently)

| # | Where | App now | Mockup | Change |
|---|---|---|---|---|
| 5.1 | Repair Order Board, under the columns (`board/index.html.twig:65`) | "Drag a card to another column to change its status." | nothing | remove |
| 5.2 | Board card progress (`board/index.html.twig:48`) | tooltip "Services done" | none | remove |
| 5.3 | Quote › Approve dialog (`document/page.html.twig`) | "The repair order becomes Authorized." | nothing | remove |
| 5.4 | Quote › Decline dialog (same file) | "The repair order becomes Cancelled, and its pending appointments with it." | nothing | remove |
| 5.5 | Repair order › Invoices › Charged so far (`repair_order/invoices.html.twig:83`) | "What the repair order orders, … Lines are matched by name." | "Invoices that are not cancelled. Cancelling an invoice frees what it charged." | mockup's text |
| 5.6 | Same table (`:91`, `:100`) | "(fee / discount)" tag, ✓ marks, "Everything on the repair order is charged." | none | remove |
| 5.7 | Issue another invoice dialog (`:107-114`) | intro listing the invoices; "Nothing remains to charge. Change the repair order first…" | "Only what has not been charged for yet is listed. Lower "Invoice now" to charge less."; empty: "Everything on this repair order is already charged." | mockup's text |
| 5.8 | Edit issued invoice, above the items (`invoice/issued_edit.html.twig:20`) | "An issued line keeps its name and price; a quantity can only be lowered. …" | Items note: "A copy of the repair order when the invoice was issued. Only a quantity can change here, and only down; a row can be deleted (deleting a service deletes the charge-through lines under it). To change a price or add something, change the repair order and use Sync Repair Order. The repair order itself is not changed by what you do here: it stays what was ordered." | mockup's text |
| 5.9 | Edit issued invoice, Status options (`:28`) | "Not cancelled (follows its payments)" / "Paid (follows its payments)" / Cancelled | "Issued" / "Paid (set by its payments)" / "Cancelled" | mockup's text |
| 5.10 | Repair order form, Recommendations (`repair_order/form.html.twig:189`) | "(for the customer: printed on the work order and carried onto the invoice)" | "(for the customer: carried onto the invoice)" | mockup's text |
| 5.11 | Appointment form, Status (`schedule/form.html.twig:90`) | "(after check-in)" on the greyed-out Extended | greyed out only | remove |
| 5.12 | Products, under the title (`admin/product/detail.html.twig:18`) | "Products are managed across three areas: …" | nothing (mockup `898d57e` removed page leads) | remove |
| 5.13 | Activity Log, one record's history (`activity_log/index.html.twig:7`) | "Who changed what, and when: this record and the records that belong to it." | title only | remove |
| 5.14 | Inactive client banner (`client/show.html.twig:12`) | "…do not appear in the Client List or the searches. Their history is kept." | "…do not appear in the Client List." | mockup's text |

With it, or with the "mockup has, app lacks" pass: Edit issued invoice "Payments are recorded under Payment ▾ …
(view payments)" (`:33`) → the mockup's read-only **Paid** and **Balance due** with **Add payment · View payments**
(as the builder got in item 3).

### Item 6: confirm dialogs the mockup doesn't have
Repair order form: deleting a filled service line (`maxeme-service-lines.js:69-75`) or a discount row
(`maxeme-repair-order.js:38-43`) asks "Are you sure?"; the mockup removes at once (only the Services set-up page asks).
Nothing is lost until Save.

### Item 7: small extra buttons and links
Client › Appointments tab "+ Repair Order" on rows without one and "Work Order" for roles without accounting
(`client/_appointments.html.twig:37-45`); Choose a client "N matches · Clear search" bar (`_search_results.html.twig`);
UoM "Filter" button (`unit_of_measure/index.html.twig:87`); Client List preferred name as a link
(`client/index.html.twig:61`); client profile Appointments card vehicle link and type label (`client/show.html.twig:80`);
Add payment "Choose…" empty method (mockup preselects one) (`invoice/payment_form.html.twig:76`).

### Item 8: Settings and Logs
Tax Classes and Payment Types card titles (`settings/tax_classes.html.twig:8`, `payment_types.html.twig:8`); Error Log
Method / Summary → level / area, File / Line and "(#id)" out of the list (`error_log.html.twig:61-74`); Email Log and
Error Log Back → Appointments.

### Item 9: dead markup
Client page: two hidden Add / Edit vehicle dialogs nothing opens (`client/show.html.twig:155-185`).

### Item 10: platform extras on the Parts & Service pages (largest; plan page by page)
Products › Pricing (price groups, per-price-list columns, bulk apply, Category) and › Inventory (warehouse and column
choosers, per-warehouse columns) down to the mockup's tables; product form one Main stock box, no Fees / Custom
Fields / locked-unit sections, Private and Visible independent; Stock Adjustment without bins / lots / serials /
pickers / "Add ten more rows" / "Already reversed"; Stock page without Lots / Serials and Reorder cards and the
Order / Purchase / Shipment / Credit Memo filters; Import log as the mockup's six-column window; Product Lookup
suggestions as you type; Vendors "Addresses" page and Remit-To expand; then close the lot / serial tracking pages
left open in item 1 (tracking policies, tracking worklist, lots).

### Item 11: ask first (behaviour, not looks)
Edit user roles fallback (`staff/edit.html.twig:22-23`); Check your email wording (security fix `c9edab4`).

### Left as they are (user's choice)
Item 2 (Appointments › List per day); 4e (Remark edited in place on the repair order's invoice); F22 (Reminders
"Previous Appointment" date, as the mockup draws it).

### Then: the mockup has, the app does not
See "Found on the way" above: appointment edit Check-in, Client › Repair Orders search, Vehicle › Appointments paging,
Service Report hint and count, Issue-another Ordered / Charged columns and "Show lines already charged", Edit issued
invoice Paid / Balance due and Add payment, Activity detail Record link, Database Console "Switch off now", Email Log
Module options and per-page, Vendors row "Logs", Appointment Status sort on Repair Orders; and the calendar "+N more"
(mockup switches to Day / List).
