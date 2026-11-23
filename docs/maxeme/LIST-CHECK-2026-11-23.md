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

## The mockup has, the app did not (added afterwards)

Each checked in Chrome after the change (both directions, and each new box with words from the rows).

| Page | Added |
|---|---|
| Staff | First / Last name, Email, Username sort |
| Repair Orders | Master Technician sorts (by the name shown) |
| Appointments (list) | Date box (11/20/2026, 11/20/26, 11/20 or 2026-11-20); Technician and Repair order boxes; Technician and $ sort |
| Client › Appointments tab | Repair Order and $ sort |
| Reminders | Client Info, Vehicle, Reminder note, Phone number (digits), Email boxes; Phone / Email Status sort |
| Product Categories | Products count sorts (the list goes flat while sorted) |
| Units of Measure | Referenced by sorts (by the number of records) |
| Vehicle page › Appointments | Date sorts |
| Invoice › Payments | When / Method / $ sort |

Still different: the Error Log Method and Summary columns show the request's method / URL where the mockup
shows the level / area (they still sort by level / area).
