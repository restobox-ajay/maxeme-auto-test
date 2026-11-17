# Visual recheck: dev mockup vs local app (2026-11-17, afternoon)

Checked in Chrome, page by page, at the same window size: the dev mockup
(`maxemenew.dev.rp017.webhelplogin.com/mockup/`, branch `mockup` at `84d136d`) against the local app
(`http://maxeme-auto.local/admin`, main at `d88ef64`). For every page: screenshots side by side, plus a
script that read the headings, table columns, buttons, field labels and the computed styles (font
size and weight, colours, borders, radius, padding, height) of the main elements. Nothing was changed.

- **Client comments:** still 55 threads and 284 comments. Nothing new since the morning check, so the
  thread-by-thread table in `FEEDBACK-CHECK-2026-11-17.md` still stands.
- **Mockup commits:** none after `84d136d`.
- **Data:** the mockup has its own demo data (newer dates, prices on every service, technicians on
  every repair order). Differences that come only from data are listed at the end and are not to-dos.

## 1. Against a client comment

| # | Thread | Page | Difference |
|---|---|---|---|
| 1 | 13347 / 13425 (B/W) | Quote and invoice documents | The mockup prints quotes and invoices in black and white like the work order. Local still has blue section titles ("Customer Information", "Quote Items"/"Invoice Items"), blue "Bill to"/"Vehicle" labels and a blue "Grand Total". |
| 2 | 13687 | Repair order list | The mockup has a **Master Technician** column; local has none. |
| 3 | 13689 | Work order | Results cell in the mockup reads "**Technician:** Mike Tran · In progress" (the service's technician and work status). Local prints blank "Technician: ____" lines. RO 15193 has no services locally, so this needs a re-test on an RO that has services before it counts as a bug. |
| 4 | 13628 | RO invoice view | Remark card: mockup shows "No remark." with an "Add remark" link. Local shows a textarea and an "Add remark" button. Same function, different look. |

## 2. Theme: one CSS change fixes every page

| Element | Mockup | Local |
|---|---|---|
| Top bar | Breadcrumb on the left ("Schedule › Appointments") | No breadcrumb |
| Sidebar active item | Solid blue `#428bca`, white text, weight 600 | Not highlighted |
| Sidebar group link | 12.5px / 400, `#333` | 12.5px / 500, `#181c2e`, bordered, radius 8 |
| Sidebar brand block | 72px tall, regular weight | 58px tall, weight 900 |
| Sidebar search | Label 10.9px; input 12.5px, 30px tall | Label 11.5px; input 13.6px, 34px tall |
| Sidebar collapse | "‹ Collapse" | "‹" only |
| Cards | White, 1px `#e5e7eb` border, radius 12, padding 20/22, gap between cards | Flat sections with grey header bars |
| Table header (th) | 11.2px / 500, padding 9×12, 36px tall | 12.5px, padding 10×20, 40px tall |
| Table cell (td) | 13.1px, padding 9×12 | 14px, padding 10×20 |
| Links inside tables (RO #, invoice #, customer, amounts) | Blue `#428bca` | Plain dark text, no link colour |
| Money in tables | "$365.69" | "365.69" (no $) |
| Status badges | Soft pills: light tint, coloured text | Solid pills with white text on some pages (client profile); soft on others |
| Small buttons | 12.2px, 28px tall, radius 5, padding 0×9 | 13px, 32px tall, radius 8, padding 0×12 |
| Inputs / selects | 12.5px, 30px tall | 12.8px, 34px tall, 1px `#ccc` border |
| Column filter boxes in table heads | Flat, no border | Bordered boxes |
| Row "more" button | Outline "⋯", menu with Logs | Blue chevron button (clients, vehicles, users, categories, labour, fees, reminders have none) |
| Table footer | "Showing 1 to 20 of N entries · Per page · 1 2 3 … ›" on every table | "Per page" only, and missing on many cards (Notes, History) |
| Empty horizontal scrollbar | None | A thick grey scroll strip under many tables even when nothing overflows |
| Back button | "↩ Back" | "‹ Back" |
| Form buttons (Cancel / Save) | Bottom-right | Bottom-left |
| Form labels | Above the field, hint inline in small grey | In a narrow left column, hint wrapped under the label |
| Text in table heads | Controls keep their case ("All methods", "Clear", "Reset") | Upper-cased by the th style ("ALL METHODS", "CLEAR", "RESET") |
| ACTIONS column head | Left-aligned | Centred |

## 3. Page by page

### Appointments calendar
- **Toolbar buttons:** mockup has white outline buttons (12.2px, 28px tall, `#ccc` border); the active view is `#3071a9`. Local has solid blue buttons, 14px, 34–36px tall.
- **Title:** mockup Day view reads "Tue, November 17, 2026" (16px/700); local "November 17, 2026" (20px/800). Week view: mockup "Nov 15 – Nov 21, 2026", local "Nov 15 – 21, 2026".
- **Day header:** mockup is 12.8px/700, dark, 32px tall; local is 12.5px/500, grey, 24px tall.
- **Time column:** mockup labels hours only, 10.9px grey; local labels every 30 minutes, 14px dark.
- **Today column:** mockup Day/Week leave it white; local tints it yellow `#fcf8e3`. Month view tints the cell in both.
- **Appointment text:** mockup 12.2px, local about 10.9px.
- **Month view:** mockup weekday heads are uppercase small grey, and only the month's own weeks show. Local adds a sixth row of next-month days.

### Appointment form
- **Labels:** mockup puts them above the fields. Client and Phone are read-only grey boxes.
  - Mockup order: Client, Phone, Vehicle, Start time, Duration [n][hours], Repair Order (+ "Open RO-…"), Status, Type, Assigned Technician, Note.
  - Local puts labels on the left, has "Appointment for:" (the type) near the top, and has Assigned Technician before Status.
- **Header buttons:** mockup has Delete and "↩ Back". Local also shows a blue **Check-in** button; the mockup has check-in only on the calendar popup and the client page.
- **Slot calendar:**
  - Mockup: grey hour column, rows "7:00 AM – [count]" with the chosen slot highlighted, title "Saturday, October 3, 2026", outline buttons.
  - Local: blue hour labels, a "Tuesday" header row, solid buttons, a title without the weekday, and an **empty third panel** on the right.

### Repair order
- **Sub-tabs:** local has "Repair Order | Invoices" tabs under the header; the mockup has none. Its "Invoice ▾" opens the invoice document directly.
- **Top card, column headings:** mockup has small uppercase CUSTOMER / VEHICLE / STATUS headings; local uses field labels.
- **Top card, field placement:**
  - Mockup puts Pickup address under the customer and Odometer + Tag/Key under the vehicle. Local has Pickup address under the vehicle.
  - Mockup has a note under Status: "A repair order can't be deleted; set it to Cancelled when no work was done." Local doesn't.
- **Customer name:** a blue link in the mockup.
- **Repair Name / Internal Comment / Recommendations:** mockup puts the label above the field with an inline hint, and Recommendations is half width. Local uses a left label column with a wrapped hint.
- **Totals:**
  - Mockup is a compact box on the right: fee/discount rows, "+ Add discount", "GST [5] %" and "PST [7] %" with 70px inputs, and a bold Total under a rule.
  - Local stretches the GST/PST inputs across the full width, with "%" on its own line. This is the most visible break on the page.
- **Cancel / Save:** right-aligned in the mockup, left in local.
- **Notes card:** mockup has "+ Add Note" beside the heading; local has it far right. Notes and History have the "Showing … entries" footer only in the mockup.

### Repair order list
- **Master Technician column:** missing locally (see section 1).
- **Links:** RO # and customer are blue links in the mockup.

### Work order / quote / invoice documents
- **Work order section heading:** mockup "REPAIR ITEMS" in uppercase; local "Repair Items" (now black).
- **Items table header:** mockup "Repair Items | Results" in normal case on grey; local "REPAIR ITEMS | RESULTS", uppercased by the global th style.
- **Recommendations:** the mockup prints them under the work order items.
- **Quote and invoice:**
  - Mockup has bordered meta and Bill-to/Vehicle blocks, an items grid with a normal-case header ("Item | Qty | Price per unit | Amount"), uppercase black section titles, and "Total:" plus "Balance due:" in black bold.
  - Local has no borders, uppercase column heads and blue titles.

### Client profile
- **Appointments table:** RO numbers and amounts ("$365.69") are blue links in the mockup; local shows plain "RO-015193" and "0.00".
- **Status pills:** soft in the mockup, solid in local.
- **Pending / Past rows:** a light blue band in the mockup, grey in local.
- **Notes footer:** "Showing 0 to 0 of 0 entries" only in the mockup.

### Vehicle page
- **Details card:** mockup has a bordered card headed "VEHICLE", with the customer as a blue link. Local has no border, heading "Vehicle", and the customer as plain text.
- **Stat tiles:** separate tiles in the mockup, the first reading "REPAIR ORDERS" (2). Local joins the cells and reads "FINISHED REPAIR ORDERS" (1).
- **Repair History filters:** right after the heading in the mockup; local pushes them to the right.
- **Services column:** mockup adds the category in grey ("· A/C & Heating").

### Dashboard
- **Cards:** separate rounded cards in the mockup, with blue header links ("Calendar", "Repair Order Board", "All invoices") and bold counts. Local has flat sections with dark header text.

### Lists
| Page | Difference |
|---|---|
| Services | The mockup has an **Active** column with an on/off switch, plus an Active/Inactive filter. Local has neither. |
| Service form | The mockup has **Status ☑ Active** with the note "An inactive service is not offered on new repair orders; the ones already written keep it." Local lacks it. |
| Products | Mockup has a Vendor column; local has Custom Fields instead. |
| Clients, Vehicles | Local has an extra "OPEN" column. The mockup has a 🔍 search button in the header and a ⋯ menu per row. |
| Service categories | Local has no ACTIONS heading. |
| Service reminders | Local adds a "Service Reminders" card title and has no Export CSV. |
| Labour, Government fees | Local adds a card title ("Labour", "Government Fees") that the mockup doesn't have. |
| Board, Reminders, Payment report, Service report | Local has an extra "Period" preset select. The mockup has From/to only; its payment report select reads "Custom date range". |
| Payment report | The mockup has a bold "Overall:" totals row, a blue Filter button and a "Clear" link. Local shows "Filter" as plain text and "CLEAR" as an outlined button. |
| Repair Order Board | Column numbers sit in blue circles in the mockup; local shows plain numbers. |
| Stock adjustment | The product search box is there (13626 ✅), but locally its suggestion list opens by itself when the page loads. Labels are above the fields in the mockup. |
| Settings › Tax Rates | The mockup is a compact half-width card with grey GST/PST chips and Save on the same row as the note. Local is full width, has an extra card title, and shows a "Per page 100" control. |
| Choose a client | The mockup has a **Start without a client** button; local doesn't. Empty cells show "—" in the mockup. The sidebar highlights Schedule › Repair Order in the mockup, People › Client in local. |
| Product form | The mockup has 2 columns, with Logs and Delete at the top. Local has 3 columns with Remarks, Short Description and Long Description editors, and Back only. |
| Vendor detail | The mockup still shows a Payments tab, which predates thread 14028. Local follows the client and has none. There are no vendors locally, so the rest of this page was not compared. |
| Browser tab title | Some local pages end in "\| Admin" (Stock Adjustment, Update Product); others end in "\| Maxeme Auto". |

## 4. Data only (not to-dos)
- **Imported data:** the local data is old imported data, with appointments up to June 2024, no technicians, no service prices or tax classes, and no vendors. So local tables and the calendar look emptier than the mockup's.
- **Phone numbers:** the mockup shows raw phone numbers ("7786689766"); local formats them ("778-668-9766"), which is better.

## Not checked
- The login, forgot-password and reset pages: the session was logged in.
- Printing and the PDFs. Vendor detail, because the local database has no vendors.
