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

1. **Reminders › Service Reminder Queue**: the client's entry, Queued, due = completion date + rule days.
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
| F3 | A split payment prints and reports as one method: invoice shows "Payment method: Credit Card 318.60" (the Debit 100 is missing); the Payment Report files the whole invoice under the last method | `PrintedDocuments.php:74`, `PaymentReport.php:55` | Open |
| F4 | Board dialogs ("Issue the invoice now?", "Move repair order") read "this item RO-…" and use the red delete icon and button | `maxeme-board.js:22,30` + core `showConfirmModal` | Open |
| F5 | Emails are sent with no From address locally ("No From: address could be resolved" in the Error Log) | app setting sender_from_address, or MAILER_FROM (blank in .env) | Config: set it in .env.local |
| F6 | Invoice payments are filed under area "System" in the Activity Log, not Accounting | `AuditLogEnricher` (no InvoicePayment entry) | Open |
| F7 | Email Log is empty locally because the async queue has no worker (10 messages waiting, 6 from September) | messenger `async` | Expected; run the worker |
| F8 | The service's lines are advice only: a service without a default price adds 0.00 to the RO until a price is typed | Services form | By design; worth a hint on the form |
