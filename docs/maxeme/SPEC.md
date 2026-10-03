# Maxeme Auto — Spec

Source: the client's Google Doc ("Maxeme Auto specs", read 2026-10-03). This is a markdown copy so it lives next to the code; the Google Doc stays the original. Status of each item: `FEATURES.md`.

Base: clone of [wholesale-b2b-core](https://github.com/axcelmediacorp/wholesale-b2b-core). Old site: https://admin.dev.maxemeauto.com

```
Repair Order (header: customer, vehicle, complaint, mileage, status, totals)
 ├─ Appointment          scheduling
 ├─ Work Order           time + materials + sublet, what happens in the shop
 ├─ Authorizations       approval log
 └─ Estimate / Invoice   financial documents
```

## 1. Auth, logging, roles

- Use wholesale-b2b as the base. Logging: yes. SQLite (not MySQL/MariaDB).
- Roles: Tech Support, Super Admin, Admin, Secretary I, Secretary II, Receptionist, Technician. RBAC: see §17.
- Colours: roughly like admin.dev.maxemeauto.com, on the wholesale-b2b theme.

## 2. Sidebar and top bar

- All nav moves into the sidebar (wholesale-b2b convention). Top bar keeps only the user menu: My Profile, Log out.
- Sidebar search, three boxes:
  1. Client: first / last / preferred name, phone, invoice #, address. One match → client; several → client list showing the results.
  2. Vehicle: VIN, licence, manufacturer, model… One match → vehicle; several → vehicle list.
  3. Invoice #: one match → invoice; several → invoice list.
- Menu:
  - Schedule: Repair Order +, Appointments +, Reminders +
  - Parts & Service: Services +, Parts Inventory +
  - People: Client +
  - Accounting: Summary Report
  - Config: Admin User +, Settings

| # | Menu | Label | Old page | Add |
|---|------|-------|----------|-----|
| 1 | Brand logo | Home / Day Agenda | /admin/schedule/appointment/display/agendaDay | |
| 2 | Schedule | Appointments | /admin/schedule/appointment/display | |
| 3 | Schedule | Reminder | /admin/schedule/reminder | |
| 4 | Parts & Services | Parts Inventory | /admin/inventory/manage | Physical inventory count from wholesale-b2b |
| 5 | Parts & Services | Services | /admin/service/manage | |
| 6 | Accounting | Summary Report | /admin/accounting/report/type/list | |
| 7 | People | Client List | /admin/client/list/ | |
| 8 | Config | Manage Admins | /admin/user/manage | |
| 9 | User menu | My Profile | /profile/ | |

## 3. Client

**Detail**
- Every delete button anywhere gets a JS confirm.
- Remove the yellow Check-in button.

**Profile edit**
- Replace cell / work / home numbers with Phone Number 1–4.
- Address → address book like wholesale: unlimited pickup addresses, user-set order. Top 2 show on the profile; the Repair Order address picker lists them in that order.

**Notes** (one standard notes system, shared abstract class/interface): Author, Message, Timestamp; edit, delete.

**List**
- Buttons at the top; Export CSV of the current view (filters applied, all pages).
- Phone 1–4 combined in one "Phone" cell.
- Second-row filters: first / last / preferred name, email, phone (all 4), address (all), note.
- View button on the right; names link to the profile too.

## 4. Services

**List** (/admin/service/manage)
- Export CSV; second-row search on name.
- Columns: Name (raw) | Label (on the printed invoice) | Default Price | Tax Class | Colour (defaults to the category colour, can override; used by the calendar).
- Easy in-page grid editing.

**Edit** — service becomes multi-table:
- Main: Name, Label, Default Price, Tax Class, Colour.
- Service lines (1:M, 0..n of each type): `service_id | line_type | entity_id | qty | price (may be negative)`. Types: Labour (X hours), Parts, Sublet, Govt Fees, Discount (1, negative). Page to add / edit / delete lines.
- Charge through: Yes → the invoice shows the line (qty × unit price) and adds it on top of the service amount (e.g. government levies). No (default) → price ignored, line hidden from the customer.
- Line editor: `[type] [type-search dropdown] [qty] [price per unit] Charge through [yes/no] [delete] [Add line]`.

**Service Category** (new): hierarchical catalogue like wholesale-b2b, plus a Colour (calendar colour).

## 5. Service reminders

**Service Reminder** (new): `[Add Service Reminder]`; columns Service | Reminder Days | Template | Message | Emails Sent | Action. Sets how many days after a service the customer is reminded.

**Reminder Template**: Name, Subject, Body. Tags such as `{{message}}`, `{{car model}}`. Plain text/HTML editor (not visual). CRUD. Purpose: a friendly reminder to call (tap to call) or book through the website form (link from Ken; no in-app form).

**Service Reminder Queue**
- When a repair order is COMPLETED, take the service with the shortest reminder days and queue it. Other services on the same RO within 60 days of it → Skipped; more than 90 days later → Queued.
- When the customer books a new appointment, every future Queued reminder for the same customer AND vehicle → Booked (not sent).
- Columns: Queued | Sent | Email | Phone (+ to expand past 2 lines) | Client info | Vehicle | Reference service (RO date + triggering service, link to RO) | Reminder days | Status (Queued, Sent, Skipped, Error, Booked, Resolved) | Action ([view email] [resend] [Book] [Resolved]).
- Book → new Repair Order for the client. Resolved → cancel future emails.

## 6. Labour, Govt Fees

- **Labour** (list / add / edit / delete): Code, Name, Price per unit, Unit (hours, ea), is_sublet, is_active, Tax class.
- **Govt Fees**: manually added fees, with room for code that adds fees automatically. Name, Code, $, Description, is_active, Tax class.

## 7. Repair Order (replaces /admin/invoice/view/appointment/{id})

Everything starts from the Repair Order; it ties together appointments, invoices and the work order. An appointment is only date/time and check-in.

- Jobs table = Services. C/C/C = delete.
- Advisor: any admin user (default: whoever starts the RO). Tag/Key: free text. Promised: date/time the vehicle is due back.
- Notes (standard notes: author, text, date/time; admin and technician can add).
- All fields optional (a customer may book with just "strange noise").
- Custom lines under the subtotal, above tax (custom fee / custom discount), add or delete any number — as in wholesale-b2b.
- GST and PST shown separately.
- Repair Name: short internal description, shown on the calendar and RO list. Concern: the customer's own words.

**Services on the RO**
- Add Service / Add Canned Service; each adds a row.
- Loading a service pre-loads all its lines and values; lines editable like the Service edit page, with qty and price; "Add Parts" → "Add Lines". Line type is any of the five, with type-search from inventory, labour, sublet, govt fees, discount.
- The service price comes from the service's default price, not from its lines (lines are advice); admin can overwrite it.
- Qty lives on lines, not on the service.

**Header**: Customer, Vehicle (look up or add), Mileage, Status:
`estimate_being_built`, `estimate_approval` (set when the estimate is sent), `authorized`, `in_progress`, `awaiting_parts`, `completed`, `completed_picked_up`, `invoiced`, `cancelled`.

**Appointments on the RO**: 1:M. Each has an appointment time and a promised time; past ones are read-only; current ones have [Edit] [Check-in] [Delete]; add more with +.

**Audit log** for the RO and all children, in one table: appointment book/change/cancel; service and sub-line add/edit/delete; estimate/invoice sent/paid; authorizations approved.

**Action buttons**: `[Invoice] [Quote] [Work Order]` bar right under the title.

## 8. Documents

Each document page has a bar with the other two:
`[X PDF ▾ Print / Save as PDF / Save and Email]  [Go to Y] [Y PDF ▾]  [Go to Z] [Z PDF ▾]`. "Save and Email" opens a Zoho Books-style compose screen.

**Work Order**
- "Home" → Phone (Phone 1, 2…). "Address:" before the address.
- Master Technician at the top (field on the RO, choices from Settings › Technicians); blank prints `________` to fill in.
- Vehicle block: Make, Year, Model, VIN, License, Mileage `____ KM / ____ Miles`.

**Invoice**
- No longer merged with the appointment. On the RO: Invoices tab with `[+ Issue Invoice]`; columns Date | Amount | Status (Issued, Paid, Cancelled) | Action ([View] [Edit] [Delete]).
- Invoice # keeps the current format and prefix.
- Shows service names only (no labour/parts breakdown) plus charge-through items, Subtotal, Fees, Taxes, Grand Total.

**Quote**: same content as the invoice.

**Save as Quote** (item 13): a button; the saved PDF is titled QUOTE / 報價單. Client note: Quote → Sales Order → Invoice are three separate documents; the Work Order is a separate flow.

## 9. Appointments and calendar

- Appointment: `[Check-in] [Edit] [Delete]`; Client, Vehicle, Repair Order, Start, End.
- Status: `new` (booked), `in_progress` (dropped off), `extended` (needs longer), `no_show`, `cancelled`, `completed` (manual, or set when the RO completes).
- Calendar: keep as is, fed from appointments. Event text: Time [status] vehicle / Customer / Phone / RO short description.
- Week view overlaps: the hovered event comes to the top layer.
- Remove the yellow Check-in button.
- Colour by service; admin can override per appointment. Settings for service → colour.

## 10. Vehicles

- 1 customer : many vehicles; proper sidebar entry.
- List of every vehicle: Customer | Year | Model | Colour | Licence Plate | VIN.
- Vehicle page: stats, all repair history (historical ROs), filter by Service and Service Category; notes (1:M, author, date/time).
- Proper add / edit page (not a popup): add, delete, edit, assign to client.

## 11. Inventory

- Use wholesale-b2b. Keep: add/edit product, product grid (fewer columns later), product detail (inventory-depth stock page), Stock Adjustment, Movement History, UoM, Vendor, Product Import, Product Categories.
- RO "Parts" lines use the product catalogue. Stock buckets by RO status:

```
available = quantity + received − write_off − quarantine − cart_hold
          − sales_hold   ← RO approved
          − pending
          − approved     ← RO in_progress
          − shipped      ← RO completed or later
          − backordered
```

- Parts added after the job starts (already in_progress) go straight to "approved"; available drops.

## 12. Settings

- Tax Classes CRUD: E Exempt, S GST+PST, G GST only.
- Tax Rates (editable): GST 5%, PST 7%.
- Doc Prefixes: Repair Order RO-, Work Order WO-, Invoice INV-, Quote QO-.
- Technicians: list, used for Master Technician.
- Payment Types: Cash 1 (NT), Cash 2 (T), E-transfer, Credit Card, Cheque, Debit, IOT.

## 13. Webhook to GHL

On a completed order: find the contact by email in GHL, create it if missing (email, name, phone), add tag `send-review-request` if absent. A GHL automation on that tag sends the pre-made Google-review email (Honeylett makes the email, Leo supplies the link).

## 14. Reporting

- Time filter: `[preset ▾] From [ ] To [ ] [Run]`; presets Today, Yesterday, This Week, This Month, Last Week, Last Month, Custom. The preset only fills From/To by JS; the search uses From/To.
- Export CSV on every report and every view: the exact table, all pages.
- **Payment Report** (keep): payment method becomes a table filter (not 5 tabs). Revenue → Parts, Labour, Discounts, Govt Fees, Subtotal, Taxes, Grand Total. Remove material cost, revenue, discount, sales tax, net gain. Based on payment date.
- **Service Report**: RO Date | RO # | Service | Customer | Labour | Parts | Discounts | Govt Fees | Subtotal | Taxes | Grand Total; one row per service.

## 15. Work Order Board (item 21)

Status categories by vehicle stage, separate from payment and quote fields: Appointment Made → Pending on Quote → Check-in → Repairing (Work Order issued) → Complete, Pending Pick Up → Completed (Paid). (已預約 = 待報價 > 已入場 > 維修中（工單產出）> 維修完成待交車 > 已結帳交車離開.) Filter by date, total per category, click a count to see those work orders.

## 16. Accounting (item 19)

Invoice # and Work Order # can be edited.

## 17. User access

| Role | Appointment | Reminder | Service | Parts & service | Work Order | Accounting | People | Car |
|---|---|---|---|---|---|---|---|---|
| Admin | Edit | Edit | Edit | Edit | Edit | Edit | Edit | Edit |
| Secretary I | Edit | Edit | Edit | Edit | Edit | Edit | Edit | Edit |
| Secretary II | Edit | Edit | Edit | Edit | Edit | View | Edit | Edit |
| Receptionist | Edit | Edit | View | View | View | View | Edit | Edit |
| Technician | View | View | View | View | Edit | – | – | Edit |

## 18. Logs

Standard audit log on every entity: who changed what, when.
