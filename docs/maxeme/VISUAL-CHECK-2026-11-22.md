# Mockup recheck: whole app vs mockup (2026-11-22)

A rerun of the 2026-11-21 check (`VISUAL-CHECK-2026-11-21.md`) after the fixes since: G1, L1–L8, F14–F18
and the core / module pages C1–C10. The mockup is still `84d136d` (no commits since 2026-11-12).

**How:** the same as on the 21st. The mockup was served from the local site for the check (a temporary
folder, with its `version.txt` present so it filled no Error Log), and a script loaded each of 73 page
pairs in Chrome and listed the headings, table columns, buttons, tabs and field labels each side had that
the other did not. Every difference that was not plainly data was then looked at on screen. This time the
pairs use the seeded QA records (RO-015197 with a service and parts, INV-015842, vendor 2, the QA pads'
stock page), and three pairs were added: the vendor page, the blank work order and Add a unit.

## Result

Every page matches the mockup in structure and flow. No page errored. No new differences were found
apart from one label:

| # | Page | Mockup | Local | State |
|---|---|---|---|---|
| R1 | Repair order › Invoices, with an invoice already issued | "+ Issue Invoice" (the dialog it opens is titled "Issue another invoice") | "+ Issue another invoice" | **Fixed** `9d00f1a` |

Checked on screen and found to match, though the script listed them:

- **Settings** tabs (Tax Rates, Tax Classes, Doc Prefixes, Payment Types, Reminder Emails) are there.
  The script looks for another tab markup.
- **Invoice page**: Payment and Invoice PDF are there; they are menu buttons the script does not list.
- **Work order**: the same header as the mockup (Work Order PDF ▾, ⋯). Print and Change Work Order # are
  items inside those menus.
- **Products / Categories / UoM** row buttons (Edit, Actions) use other button markup locally. All are on
  screen as in the mockup (see C1, C2, C7).
- **Repair order › Invoices** row: View, Edit, then Logs in the ⋯ menu. This is the app-wide rule that a
  row shows its first two actions as buttons; the mockup draws Logs as a third button.

What remains is data, not differences: other record numbers and amounts, the mockup's own sample records
(its client and vehicle pages open "not found" without an id), dates, and page counts.

## Since the 21st, now matching

G1 (invoice work order), L1–L8 (breadcrumbs, My Profile, activity detail, role page, invoice builder,
date presets, email page, edit issued invoice), F14–F18 (log areas, parts leave stock when invoiced, stock
page Invoice rows, adjustment dates, background imports) and C1–C10 (Products list, Units of Measure,
stock page, Movement History, Product Lookup, vendor page, categories, vendor form, import result).

## Client comments (SureFeedback 13049)

Unchanged since the last check: 55 threads, 284 comments, the newest from 2026-11-12. All are done except
two waiting on the client: 13314 (Today in the calendar's Month view: checked again, it goes back to the
current month) and 13628 (Ken to check the invoice admin note). One clash was found and fixed: C6 had put
back the vendor page's Payments tab, which the client asked to remove in thread 14028; it is removed again.

## Second run (later on 2026-11-22)

The 73 pairs again, plus the repair order flow side by side on screen (list, new repair order, client,
board, Payment Report). Against the first run only two things changed, both expected: the "+ Issue Invoice"
label now matches, and the vendor page has no Payments tab (removed for thread 14028, so it now differs
from the mockup drawing on purpose).

One fix: the date presets read the viewer's clock. Opened from India in the evening (already the next day),
the board showed "Yesterday" for the shop's today. The preset dropdown now carries the shop's date and
counts from it (`d123bb9`); it also no longer has an old choice restored by the browser on reload.
Small differences left as they are: New Repair Order has Back where the mockup has ⋯, and no
"No customer picked." hint under the empty Customer box.

## Clean-up

The temporary mockup folder and scripts are removed. No Error Log or Activity Log entries came from the check.
