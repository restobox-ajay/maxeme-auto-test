# Maxeme Auto: how to test the whole flow

How to walk through the app from set-up to a paid invoice and a service reminder, what each step
should show, and what was found when it was run on 2026-11-19 (local, `http://maxeme-auto.local`).

## 0. Before you start

- **Log in** at `/admin` as an admin (all permissions). Role checks are in step 13.
- **No real email can go out locally.** `MAILER_DSN="null://null"` in `.env` swallows every email.
  Always use `@example.invalid` addresses for test clients anyway, so the live site can never mail them.
- **Emails are queued, not sent at once.** Mailer and GoHighLevel messages go to the `async` queue.
  On the server a cron runs the worker; locally nothing does, so the **Email Log stays empty** until you run:
  `php bin/console messenger:consume async --time-limit=60`
  (Safe locally: null mailer, and GHL is blank in `.env`, so nothing leaves the machine.)
- **Name test data "QA …"** (client "Qa Testclient", plate "QA1234", SKU "QA-…") so it is easy to find and remove.
- Keep **Logs › Error Log** open in a second tab: anything red that appears while you test is a bug.

## 1. Settings (check once)

| Where | Check |
|---|---|
| Settings › Tax Rates (`/admin/shop-settings`) | GST 5 %, PST 7 % |
| Settings › Tax Classes | E – Exempt, G – GST only, S – GST+PST |
| Settings › Doc Prefixes | INV-, WO-, QO-, RO- (blank = the default) |
| Settings › Payment Types | Cash, Debit, Credit Card, E-transfer, Cheque… |
| Settings › Reminder Emails | booking link and the email template text |
| App setting `sender_from_address`, or `MAILER_FROM` in `.env.local` | **Sender "From" address**: blank locally (see finding F5) |

## 2. Catalogue

1. **Service category**: Parts & Service › Service Categories › New: name, colour, Visible. → "was created".
2. **Labour**: Parts & Service › Labour › Add: code, name, price 100, unit Hours, tax class S, Active.
3. **Government fee**: Government Fees › Add: code, name, price 5, tax class E (exempt), Active.
4. **Part (product)**: Products › Create:
   - name, SKU, Original Price 40, **Sale Price 45**, **Status Active**, stock Main = 10.
   - New products start as Type Part and Sales Tax Code "S - GST+PST".
   - Only **Active** products appear in the part pickers (repair order, service lines, invoice).
5. **Service**: Services › New: name, label (the name printed for the customer), tax class S, category, **a default price**, and lines:
   labour 1.5 h, part × 2, gov fee × 1 with **Charge through = Yes**, discount −10.
   - Expect: each line's item search finds the QA labour / part / fee with its price.
   - Note: the service's **price** is what the customer pays; the lines are advice, except charge-through lines, which are added on top.
6. **Service reminder rule**: Reminders › Service Reminders › Add: the service, 180 days, a template.
   Without a rule, completing a repair order queues **no** reminder for that service.

## 3. Client and vehicle

1. People › Clients › Add client: first/last name, preferred name, `…@example.invalid`, phone 1 and 2 → opens the client page.
2. Address Book tab: add an address.
3. Client Profile: add a note (shows author and time; edit and delete it).
4. Vehicles tab: add a vehicle (year, make, model, VIN 17 chars, plate, mileage, KM/Miles).
5. Search boxes in the sidebar: **Customer** by phone or name → straight to the client when one matches;
   **Vehicle** by plate → straight to the vehicle page.

## 4. Appointment → repair order

1. Client page › "+ Add new appointment": pick the vehicle, click a 15-minute slot in the day view,
   set the duration, "Create new repair order", type Repair, a technician, a note → Save and confirm.
   - Expect: "Appointment booked. Repair order RO-0xxxxx created." The appointment shows in **Appointments** (calendar) on that day.
2. Open the repair order. Fill in Repair Name, Odometer, Pickup address (the client's address book), Tag/Key.
3. "+ Add Service": search the service, pick it → its lines load. Set the price.
4. Totals must be: Subtotal = service price + charge-through lines; GST/PST only on taxable items
   (an exempt fee carries no tax). Example used: 280 + 5 → GST 14.00, PST 19.60, **Total 318.60**.
5. Recommendations (printed on work order and invoice), Internal Comment (never printed). Save.

## 5. Quote

1. Repair order › **Quote** tab: QUOTE / 報價單, QO- number, the service label, charge-through lines, totals,
   "Status: Awaiting approval".
2. PDF menu › **Save and Email**: the To is the client email; send. → RO status becomes **Pending on Estimate**.
3. **Approve** (by phone / email / in person / signed) → RO status **Authorized**; the quote shows
   "✔ Accepted · by phone · date". Try **Decline** on another test RO too.

## 6. Work in the shop

1. Repair order › Appointments: **Check-in** → the appointment badge changes.
2. **Repair Order Board** (`/admin/repair-order-board`): set the date range to include the appointment day,
   drag the card Authorized → In Progress (toast "moved to In Progress"; the card moves a moment later).
3. **Work Order** tab: WO- number, technician, client, vehicle, mileage, repair items, recommendations.
   Print it (Ctrl+P): **First Printed On** is set the first time only.
4. Products: the part is held for the repair order (stock buckets follow the RO status; see FEATURES.md G10).

## 7. Invoice and payment

1. Drag the card to **Completed** (or set Status = Completed and Save).
   → "Issue the invoice now?" (board dialog, or a yellow bar on the RO page). Issue it.
2. Invoice page: INV- number, same lines and totals as the quote, Remark card (internal, never printed).
3. **Payment**:
   - Add payment: part (e.g. 100 Debit), then the rest (218.60 Credit Card) → status **Paid**.
   - Paying more than the balance is refused ("That is more than the balance due").
   - **Mark paid** (one click, one method) also works.
   - When paid, the repair order becomes **Invoiced**, and its appointment **Completed**.
4. **Change Invoice #** (More menu): set a custom number, find it with the sidebar Invoice # box, then clear it to go back to INV-.
5. Invoice PDF, Save as Quote, Save and Email: open each.

## 8. After the job

1. **Schedule › Reminders** (the service reminder queue is listed here; `/admin/service-reminder-queue` redirects to it):
   the client's entry, email status Queued, due = completion date + rule days (widen the period to see it).
   View email (preview), **Send now** → Sent (only to the queue; run the worker to see it in the Email Log),
   Book Appointment, Resolved, phone status (Not called / No answer / Reached…).
2. **Reminders** (manual): client page › add a reminder for a date; it appears in the Reminders list.
3. **Vehicle page**: totals, first / last visit, repair history with service and category filters.
4. **Client page**: Appointments and Repair Orders tabs list both repair orders.

## 9. Reports

- Accounting › **Payment Report**: the invoice, method, subtotal, tax, total; per-method totals; period presets.
- Accounting › **Service Report**: the service with labour / parts / discount / fees / totals for the period.
- Each has Export CSV: the file has every row of the current view.

## 10. Lists, search and CSV

For Clients, Vehicles, Repair Orders, Invoices, Services, Labour, Government Fees, Reminders:
search box, column filters, sort, paging, **Export CSV** (all rows of the view, not just the page).

## 11. Logs

- **Activity Log** (`/admin/logs/activity`): an entry for every create / update / print / email / payment above,
  with Details showing the changed fields. Every record has a **Logs** button that filters to it.
- **Email Log**: after running the worker (section 0).
- **Error Log**: must stay empty while testing.

## 12. Edge cases worth a try

- Cancel a repair order (Status = Cancelled): it cannot be deleted, only cancelled.
- A Draft or Inactive product does not appear in part searches.
- Delete buttons ask first (lines, services, appointments, notes).
- A completed appointment cannot be deleted.
- Walk-in: Repair Order › New without an appointment, existing client (see fixed bug F1), and "+ New customer" / "+ New vehicle" from the same page.

## 13. Roles

Staff › create a user with the **Technician** role, an `@example.invalid` email and a test password;
log in with it in a private window. Per `config/rbac/role_permissions/technician_role_permission.php` a
technician can view appointments, reminders, services and parts, and edit work orders and cars, with no
Accounting (invoices, payments, reports) and no People (clients, staff). Check the sidebar and try an
invoice URL directly: it must be refused. Roles › each role shows its permission matrix; do the same for
Receptionist and the Secretary roles.

---

## Test data created on 2026-11-19 (local)

| What | Record |
|---|---|
| Service category | QA Test Category (id 3) |
| Labour | QA-LAB "QA Test Labour" (id 4) |
| Government fee | QA-FEE "QA Test Tire Levy" (id 3) |
| Part | QA-PAD-1 "QA Test Brake Pads", product 56, Active, stock 10 |
| Service | QA Brake Service (id 345), label "QA Front Brakes", reminder rule 180 days |
| Client | Qa Testclient (QA Tester), client 4060, qa.testclient@example.invalid |
| Vehicle | 2018 Toyota QA Corolla, plate QA1234, vehicle 6230 |
| RO 1 | RO-015194: appointment 11/20 10:00, quote approved, work order printed, INV-015838 paid (Debit 100 + Credit Card 218.60) |
| RO 2 | RO-015195: walk-in, completed, INV-015839 paid (Cash 2), service reminder queued then sent |

## Findings from the 2026-11-19 run

| # | Finding | Where | State |
|---|---|---|---|
| F1 | New Repair Order: picking an existing customer left the vehicle and pickup address lists empty (JS error `item.phones.map is not a function`) | `RepairOrderController::clients()` | **Fixed** d1b3a45 |
| F2 | Printed invoice shows "Balance due: <full total>" after a part payment | `document/_sales_document.html.twig:69` | **Fixed**: shows "Paid so far" and the real balance |
| F3 | A split payment prints and reports as one method: invoice shows "Payment method: Credit Card 318.60" (the Debit 100 is missing); the Payment Report files the whole invoice under the last method | `PrintedDocuments.php:74`, `PaymentReport.php:55` | **Fixed**: each method printed with its amount; the report row lists them all, the method filter finds any of them, per-method totals count what each took. The Invoices list CSV lists them the same way |
| F4 | Board dialogs ("Issue the invoice now?", "Move repair order") read "this item RO-…" and use the red delete icon and button | `maxeme-board.js:22,30` + core `showConfirmModal` | **Fixed**: `MxConfirm` (maxeme.js), question icon and blue OK for questions, no "this item"; also "Confirm appointment" and the appointment's "Delete this appointment?" |
| F5 | Emails are sent with no From address locally ("No From: address could be resolved" in the Error Log) | app setting sender_from_address, or MAILER_FROM (blank in .env) | Config: set it in .env.local |
| F6 | Invoice payments are filed under area "System" in the Activity Log, not Accounting | `AuditLogEnricher` (no InvoicePayment entry) | **Fixed**: InvoicePayment filed under Accounting; the 5 existing entries moved too |
| F7 | Email Log is empty locally because the async queue has no worker (10 messages waiting, 6 from September) | messenger `async` | Expected; run the worker |
| F8 | The service's lines are advice only: a service without a default price adds 0.00 to the RO until a price is typed | Services form | By design; worth a hint on the form |
| F9 | 463 imported paid invoices have no payment amount stored, so they print "Payment method: Cash" with a blank amount | `_sales_document.html.twig`, `_invoice_document.html.twig` (payment line) | **Fixed**: with no stored amount the line shows what the payments took, else the total (434 of them are 0.00 invoices; the other 29 have payments equal to the total) |
| F10 | Work order PDF from an invoice (`/admin/invoices/{key}/work-order.pdf`) gave a 500; its "printed" call too | `WorkOrderController` (missing `use` lines) | **Fixed** 7bd25d4 |
| F11 | The printed invoice showed phones as digits ("6045550101"), the quote and work order as "604-555-0101" | `PrintedDocuments`, `_invoice_document.html.twig` | **Fixed** b125e22 |
| F12 | Part stock and product changes (core ProductInventory, InventoryDetail, ProductCore) were filed under "System" in the Activity Log | `AuditLogEnricher` | **Fixed**: filed under Parts and service; 285 existing entries moved |
| F13 | The repair order's Odometer In is not copied to the vehicle (the older invoice form does, as legacy did), so the vehicle page and the reminders show the last mileage typed on the vehicle | `RepairOrderWriter` / `RepairOrderInvoicing` | Open: decide whether saving (or invoicing) a repair order should update the vehicle's mileage |

## Recheck on 2026-11-20 (local, Chrome)

- **Link crawl**: every `/admin` link reachable from the dashboard, two of each kind of page (272 pages, 92 distinct
  routes): no error status, no error page, no redirect to login. The Error Log page itself contains error text (expected).
- **Not linked anywhere, called directly**: calendar feed, slots, days, slot; client, vehicle, repair order, service
  and line-item searches; invoice find, items, print, work order, edit-issued; every PDF; every CSV export
  (header and rows); password reset pages. All fine except F10.
- **JavaScript**: 59 pages opened in the browser, no console errors from the app.
- **Flow by hand**: appointment 24/11 10:00 → RO-015196 (odometer, tag, repair name, pickup address, service at 280,
  technician) → quote QO-015196 emailed (an invalid address is refused) → approved by phone (Authorized) → check-in
  (In Progress) → board drag to Completed, "Issue the invoice now?" → INV-015840 → remark → 400 refused, Debit 100
  (Paid so far / Balance due printed), Credit Card 218.60 → Paid, RO Invoiced, appointment Completed → work order
  PDF sets First Printed once → Change Invoice # to QA-2002, found by the sidebar box, cleared → invoice emailed →
  service reminder queued (due 05/19/2027) → Payment Report "Debit 100.00 + Credit Card 218.60", Service Report,
  vehicle page totals → Activity Log has every step.
- New QA records: appointment 17719, RO-015196, INV-015840 (key `980e66193a73f009`), payments 12581–12582,
  reminder queue entry 2.

## Seeded flows on 2026-11-21 (local, Chrome, against the mockup)

The flows the old import has no data for, seeded through the app itself and compared with the mockup page by page.

| Flow | Result |
|---|---|
| Log in / Forgot password / Reset password (signed out) | Same fields, buttons and links as the mockup; "Forgot your password ?" appears after a failed log-in on both |
| Vendor: create → vendor page → note → contact | Works. QA Test Vendor (vendor 2), note 1, contact 1 |
| Product category: create, set on a part with the vendor | Works. QA Brakes (category 1) on QA-PAD-1, vendor QA Test Vendor |
| Appointment today → dashboard | Appointment 17720, 11/21 10:00, RO-015197; shows in Today's appointments, then In the shop after check-in |
| Repair order with a service and parts | Odometer 86500, tag K-21, pickup address, QA Brake Service at 280 with the pads charged through: subtotal 375.00, GST 18.50, PST 25.90 (the levy is exempt), total 419.40 |
| Quote → approve → check-in → board (Awaiting Parts → Completed) → issue invoice → payment | QO-015197 approved by phone (Authorized, 2 pads held) → In Progress → INV-015841 → E-transfer 419.40 (payment 12583) → Paid, RO Invoiced, appointment Completed; Payment Report and Service Report list it |
| Stock adjustment → stock page → Movement History | +5 QA-PAD-1, Purchase, ref QA-PO-1 (movement 1): on hand 15 |
| Product import with column mapping | `qa-import.csv` with other column names → Match columns → import #1: QA-ROTOR-1 (57), QA-FILTER-1 (58) |
| Activity Log / Error Log | Every step logged (after F14). No errors from the app |

### Findings

| # | Finding | Where | State |
|---|---|---|---|
| F14 | Vendors, vendor notes / contacts, product categories, stock movements and imports were filed under "System" in the Activity Log | `AuditLogEnricher` | **Fixed** c9e2b6a; the 15 existing entries moved |
| F15 | A paid repair order's parts never leave stock: QA-PAD-1 still showed on hand 10 (before the +5 adjustment) and "Units sold 0" after four repair orders used 8 pads, and Recent Activity lists no invoice. The mockup's stock page lists each invoice ("Invoice INV-000069 (part of a service) −1") and takes it off on hand. This is G10 in FEATURES.md (the `shipped` hold is never turned into a stock decrement) | `RepairOrderStockIssue` | **Fixed** 89de40b, the user's rule: parts leave the stock when the invoice is issued and come back when it is cancelled (or deleted, or the RO Cancelled); the hold is released meanwhile. `app:maxeme:sync-repair-order-stock` took the 4 QA repair orders' 8 pads out (QA-PAD-1 on hand 15 → 7). Checked: cancel INV-015841 → back to 9 and held again (Completed); new INV-015842 → 7 again |
| F16 | Stock Adjustment's "When" (a date) is saved as midnight UTC, so Movement History shows the evening before (2026-11-20 18:00 for an adjustment on 11-21) | core module `InventoryDepthBundle` `AdjustmentController::occurredAt()` | Open (core) |
| F18 | The stock page's Recent Activity lists a repair order's parts as "ship (2.0000)" with no − sign or invoice number, and "Units sold" stays 0 (it counts core orders); Movement History shows the reason and the invoice number | `RepairOrderPartActivity`, core `StockController` | **Fixed** a892e2c: "Invoice INV-015840 · RO-015196 (part of a service) −2" rows linking to the RO's invoices, "Receipt QA-PO-1 +5" movement rows, filters All / Invoice / Movement (only types with rows), column "Change in on hand", Units sold 8 |
| F17 | Product import stays "queued" locally: the request starts `import:process` in the background, which does not run under WAMP on Windows. Ran `php bin/console import:process` by hand and it completed | core import framework | Local only; on the server check it runs (or add `import:process` to the cron) |

### Core / module pages drawn differently in the mockup (in addition to C1–C5 in VISUAL-CHECK-2026-11-21.md)

| # | Page | Mockup | Local |
|---|---|---|---|
| C6 | Vendor page | Logs and Delete buttons; a Payments tab; "View all stock movements"; Add Contact opens in a dialog | No Logs / Delete; no Payments tab; "View all purchase orders"; Add Contact is its own page |
| C7 | Product Categories | Each row has Edit, Delete and ⋯; Status filter is a dropdown | Edit and Delete only; Status filter is a text box |
| C8 | Stock page "Vendors" | The product's vendor | Only vendors it was bought from on a purchase order, so "—" for QA-PAD-1 |
| C9 | Vendor form | Payment term is a dropdown; fields in two columns | Payment term is a text box; one column |
| C10 | Import result | Back and Products buttons; Product Import stays selected in the sidebar | Back only; nothing selected in the sidebar |
