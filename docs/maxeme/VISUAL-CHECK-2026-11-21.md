# Mockup recheck: whole app vs mockup (2026-11-21)

Every mockup page was checked against its local page in Chrome. The mockup was mockup branch `84d136d`,
the newest commit; nothing has been added since 2026-11-12. The local app was main at `7cc6576`. No code
was changed for this check.

**How:** the mockup files were served from the local site for the duration of the check (a temporary
folder, removed afterwards), so both sides ran in one browser. For each of the 70 page pairs a script
read the headings, table columns, filter controls, buttons, tabs and field labels and listed what each
side had that the other didn't. Every difference that wasn't plainly data was then compared side by side
on screenshots. The 2026-11-17 checks (`VISUAL-CHECK-2026-11-17.md`, `FEEDBACK-CHECK-2026-11-17.md`)
and their fixes (T1–T13, P1–P26) are the starting point; this check looks for anything still different.

- **Mockup commits:** none after `84d136d`; all of them are ported (`MOCKUP-SYNC.md`).
- **Client comments (SureFeedback 13049):** still 55 threads, 284 comments; the newest is from 2026-11-12.
  Two threads are waiting on the client: 13314 (Today in Month view) and 13628 (Ken to check the admin note).

## Result

Every page matches in its structure and flow. That covers the sidebar, page titles, tabs, column
headings, filters, form fields and buttons, the repair order flow, quote / work order / invoice, payments,
reports, lists, settings, roles and logs. What is left is one missed mockup behaviour, a few small layout
differences, and some core / module pages that the mockup draws differently.

## 1. A mockup behaviour not ported

| # | Page | Mockup | Local |
|---|---|---|---|
| G1 | Invoice › Work Order (`/admin/invoices/{key}/work-order`) | An invoice that belongs to a repair order shows that repair order's own work order (`ro-document.html?doc=work_order`). Its note reads "one sheet, the same content wherever it is opened from" (mockup `34181b0`). | Shows the old invoice-based work order page instead: "Repair Order WO-015840" with Print / Save as PDF / More, and no Repair Order · Quote · Invoice · Work Order switcher. Reached from the client's Appointments "Work Order" button and the invoice page. The tracker marks `34181b0` done, so this one step was missed. |

## 2. Small layout differences (Maxeme pages)

| # | Page | Mockup | Local |
|---|---|---|---|
| L1 | Detail pages (repair order, client, service form, activity detail, product lookup, stock) | Breadcrumb ends with the record: "Schedule › Repair Order › RO-020040", "People › Client › YI CHUN LIN (TIMMY)". | Group and list only: "Schedule › Repair Order". |
| L2 | My Profile | Two cards side by side (profile left, Update Password right, New / Repeat password on one row); breadcrumb "Account › My Profile". | Cards stacked in one narrow column; no breadcrumb. |
| L3 | Activity detail | Details card (User, Role, Area, Action, Record, IP) and Changes card side by side. | Stacked, full width. |
| L4 | Role page | Access by area and "Granted in the file" side by side. | "Granted in the file" under the access table. |
| L5 | Invoice builder (an invoice with no repair order) | Client Info and Vehicle Info fields in two columns each; prices "65.00"; blue "+" buttons. | One column each; prices "65"; green "+". |
| L6 | Reminders | The date preset reads "This Week" when the dates are this week. | "Custom date range" for the same dates. |
| L7 | Email page (quote / work order / invoice) | Cancel / Send inside the card. | Below the card. |
| L8 | Edit issued invoice, typed for an invoice with no repair order | Opens the invoice builder. | 404 "Only an invoice issued from a repair order is edited here." No local link leads there. |

## 3. Core / module pages the mockup draws differently (not Maxeme code)

| # | Page | Mockup | Local |
|---|---|---|---|
| C1 | Products list | Visible (All / Yes / No), Status (All / Active / Inactive / Draft) and Private (All / Public / Private) filters are dropdowns; Vendor has a search box; each row has Edit, Stock, ⋯. | Text boxes for those three, no Vendor box, Edit only; an extra Custom Fields column. |
| C2 | Units of Measure | "Referenced by: Products (12)"; quantities "1", "0.01"; Family filter is a dropdown; small outline Actions. | Shows raw table names: "product_core.unit_id (55), product_core.default_unit_id (54)"; "1.000000"; solid blue Filter / Actions. Core template `templates/admin/unit_of_measure/_rows.html.twig`. |
| C3 | Stock detail (`/admin/bundles/inventory-depth/stock/product/{id}`) | Product Lookup, Edit product and Logs buttons in the header; Recent Activity chips All / Invoice / Movement; quantities "5"; label / value columns in the side card. | No header buttons; chips All / Order / Purchase / Shipment / Credit Memo / Movement; "1.0000"; "Name: …" on one line. |
| C4 | Movement History | Filter / Reset buttons in the last column heading. | None; the filters apply on their own. |
| C5 | Product Lookup | Find button beside the box. | Under the box. |

## 4. Data only (not to-dos)

- Local data is the old import. No appointments today, no vendors, no product categories, services
  without prices or tax classes, no movements, and no open repair order with services. So the dashboard,
  calendar, vendors, categories, movements and the repair order's service table are empty locally.
- Local users have no first / last name on the signed-in account, so My Profile and the Activity Log show the email.
- The Payment Report's "Issued, not paid yet" card is built; it hides when nothing is unpaid in the range.
- The mockup quote shows a "Shop supplies" line: that is a fee on its demo repair order.
- **Email Log is empty locally:** email goes through the Messenger `async` queue (13 messages waiting in
  `messenger_messages`) and no worker runs locally. A direct send was checked and does reach the Email
  Log, so logging works. On the server the cron `messenger:consume` sends them; `MAILER_FROM` must be set
  first (F5 in `TEST-FLOW.md`).

## Pages compared

Dashboard; Repair Order list, new, edit, Invoices list; Quote, Work Order, Invoice documents; document
email; edit issued invoice; invoice builder; invoice print; invoice work order; payments and payment form;
Repair Order Board; Appointments calendar; appointment new and edit (slot calendar); Reminders, reminder
email, new reminder; Choose a client; Clients, client profile; Vehicles, vehicle, vehicle new / edit;
Payment Report; Service Report; Invoices; Services, service form; Products, product form, Product Lookup,
stock detail, Stock Adjustment, Movement History, Product Import; Product Categories and form; Units of
Measure; Vendors, vendor form; Admin User, edit user; Roles, role page; Settings (Tax Rates, Tax Classes,
Doc Prefixes, Payment Types, Reminder Emails); Service Categories and form; Service Reminders; Reminder
Templates and form; Labour; Government Fees; Activity Log, activity detail; Email Log; Error Log; Database
Console; My Profile, edit, change password.

Not compared: login / forgot / reset password (signed in), vendor detail (no vendors locally),
product import mapping (needs an upload).

## Clean-up after the check

- The temporary mockup folder under `public/` is removed.
- Its 107 Error Log entries (404s for the mockup's `assets/version.txt`, and one typed `edit-issued` URL) are deleted.
- No Activity Log entries were made: the check only opened pages.

## Fixes made (2026-11-21): one commit each

| # | Commit | What changed |
|---|---|---|
| G1 | `3fb2943` | `/admin/invoices/{key}/work-order` opens the repair order's own work order when the invoice belongs to one. Every imported invoice links to a repair order, so they all open it now. It prints the customer's current details and the repair order's WO number (WO-000676 rather than WO-000798), as the mockup does. |
| L1 | `bfd6098` | Breadcrumb third part (`crumb_tail` block): RO number, client name, vehicle name (› Edit), service name / Service, role, Detail, appointment title, New reminder, Email, Edit, Form, Edit Invoice. My Profile / Edit / Change Password read "Account › …". |
| L2 | `cad3a6b` | My Profile: two cards side by side, label / value left-aligned with "—" when empty, New / Repeat password on one row. |
| L3, L4 | `a6e79eb` | Activity detail: details card (label / value) beside Changes. Role page: Access by area beside Granted in the file. |
| L5 | `f9e5cad` | Invoice builder: Client Info and Vehicle Info in two columns, prices "65.00", wider Quantity / Price, square − / + with a blue +. |
| L6 | `1fd8add` | Date presets as in the mockup: This Week = Sunday to today, Last Week = Sunday to Saturday, This Month = 1st to today. Reminders reads "This Week"; options in the mockup's order. |
| L7 | `79489be` | Email page: 680px card, Attachment label above the file, Cancel / Send inside the card. |
| L8 | `4c69c15` | `edit-issued` for an invoice not issued from a repair order redirects to the invoice builder instead of a 404. |

The section 3 items (core / module pages) were done on 2026-11-22, with the C6–C10 found in the seeded run (`TEST-FLOW.md`):

| # | Commit | What changed |
|---|---|---|
| C1 | `a2c992a` | Products list: Visible / Status / Private dropdown filters, Vendor filter, Edit · Stock · Logs per row, Custom Fields hidden by default. |
| C2 | `465e753` | Units of Measure: "Products (55)", 1 / 0.001, Family applies when picked, small outline Actions with Logs, Filter / Clear. |
| C3 | `0919c4a` | Stock page: Product Lookup / Edit product / Logs, quantities "8", label / value cards (activity filters were F18). |
| C4, C5 | `d5f9228` | Movement History Filter / Reset under the last heading; Product Lookup Find beside the box. |
