# Every sort, filter and search checked (2026-11-22 / 23)

Every list page in the menu (and the client tabs, vehicle page, stock page, payments and the reports) was
checked in Chrome:
- each sortable header clicked twice (A→Z, then Z→A) and the column's order checked;
- each header filter given an option or a word taken from the rows, and every row returned checked;
- each search box given words from the data (names, phones as shown, invoice numbers, plates);
- rows per page, page 2, and the period dropdowns of the Board and both reports.

## Fixed

| Commit | What was wrong |
|---|---|
| `908a14a` | Sorting was case-sensitive everywhere (SQLite byte order): every capitalised name came before every lowercase one. Text columns now sort case-insensitively (`App\Doctrine\CaseInsensitiveSort`, used by all Maxeme lists and the core Products grid, Error Log and audit log). |
| `908a14a` | Invoices search box did not find "INV-015842" / "15842" (only the sidebar box did). |
| `908a14a`, `e4d3cb2` | Sorts on another field than the column shows: Invoices Client / Vehicle, Vehicles Model / Customer, Appointments Client / Vehicle now sort as they read (as the mockup does). |
| `e4d3cb2` | **Activity Log filter bar answered 400 Bad Request** on almost every Filter click: "Everyone" sends `user=` and `getInt()` rejects a blank value. Also hardened for `page=` / `limit=` (`ListQuery::intParam`). |
| `e4d3cb2` | Appointments Phone box and search box, and the Reminders search box, did not find a phone typed as shown ("604-555-0101", stored 6045550101); they compare digits now. |
| `e4d3cb2` | Client search ("… or invoice #") did not find "INV-015842" / "WO-00015836", only a bare number. |
| `e4d3cb2` | Tax Classes / Payment Types had a per-page select that could not work; removed (the mockup draws plain tables). |
| `1bcd018` | **Error Log header filter row did nothing** (no field names, nothing read on the server). The eight boxes now filter. |

## Checked and working

Repair Orders, Appointments (list), client pickers, Reminders, Services, Products grid, Movement History,
Product Categories, Units of Measure, Vendors, Clients, client tabs (Vehicles, Appointments, Work Orders),
Vehicles, Staff, Service Categories, Labour, Government Fees, Activity Log, Error Log, Invoices, stock page,
Board / Payment Report / Service Report periods, the sidebar Customer / Vehicle / Invoice # boxes. Email Log
has no emails yet, so only "no error" could be checked there.

## The mockup has, the app does not (not changed)

| Page | Mockup | App |
|---|---|---|
| Staff | sorts First / Last name, Email, Username | no sorting |
| Repair Orders | Master Technician sortable | not sortable |
| Appointments (list) | Date filter; $ and Technician sortable; Technician filter | none of these |
| Client › Appointments tab | Repair Order and $ sortable | Date and Note only |
| Reminders | text filters Client, Vehicle, Reminder note, Phone number, Email; Phone / Email status sortable | Status / Phone status / Email status dropdowns |
| Product Categories | Products count sortable | no sorting |
| Units of Measure | Referenced by sortable | not sortable |
| Vehicle page › Appointments | Date sortable | not sortable |
| Invoice › Payments | When / Method / $ sortable | not sortable |
| Error Log | Method and Summary show the level / area | they show the request's method / URL (sort still by level / area) |
