# Client feedback check: dev mockup vs local app (2026-11-17)

Source: SureFeedback project 13049, all **55 threads and 284 comments** with replies, read through the
feedback site in Chrome. Compared with the dev mockup (`maxemenew.dev.rp017.webhelplogin.com/mockup/`)
and the local app (`http://maxeme-auto.local/admin`, main at `596c645` at the time of the check; fixes since then are pushed up to `e840f29`). No code was
changed for this check.

**How each row was checked:** B = seen in the browser on both sites · C = checked in the local code ·
T = done in the mockup-sync tracker (`docs/maxeme/MOCKUP-SYNC.md`) and not re-tested by hand.

**Status:** ✅ matches · ⚠️ differs (small) · ❌ missing / to do · 🔁 conflicts with a decision · ℹ️ answer only, no code

## Summary: what needs attention

| # | Thread | Page | Issue | Suggested action |
|---|---|---|---|---|
| 1 | **13631** | Products → stock | Mavis replied "不用了 谢谢" (not needed any more, thanks); thread Resolved. | ✅ The Purchase History work was reverted (`9802bae`, `50511ed`). |
| 2 | **14028** | Vendor detail | Mavis on 11-12: please remove the Payments tab. | ✅ Removed (`7ef90cd`); vendor page shows Purchase Orders / Bills / Returns. Not seen on screen (no vendors in the local data). |
| 3 | **13337** | Appointments | Decided 2026-11-17: follow Mavis, completed appointments cannot be deleted. Already enforced in `AppointmentService::delete()`. | ✅ No change needed. |
| 4 | **13347 / 13425** | Work order document | No colour (B/W printing): the "Repair Items" heading was blue. | ✅ Now black on the work order, page and PDF (`4874f90`). |
| 5 | **13314** | Appointments | Still **In Review**: Mavis said Today in Month view didn't jump back; the team asked her to hard-refresh. No answer since. | Re-check Today in Month view; ask Mavis to confirm. |
| 6 | **13628** | Invoice | Admin note done; thread still **In Review** (waiting for Ken to check). | No code; ask Ken to confirm. |

**Update (later on 2026-11-17):** items 1–4 and the three small mockup differences are done and pushed. Only 13314 and 13628 remain, both waiting on the client.

Everything else is ✅ or ℹ️ (below).

## Page by page

### Appointments calendar (`appointments.html` ↔ `/admin/appointments`)

| Thread | Request (short) | Client status | Local | How |
|---|---|---|---|---|
| 13201 | While dragging, show the time (red line + outline) | Resolved | ✅ drag guide in `maxeme-calendar.js` | T, C |
| 13202 | Open on Day view, not Week | Resolved | ✅ opens on Day | B |
| 13314 | Today works in every view; Week view back | **In Review** | ⚠️ Today button present in all views; Mavis's Month-view report not re-confirmed | B |
| 13315 | Blocks show the work (note) | Resolved | ✅ "Description: …" on each block | B |
| 13337 | Can a past (completed) appointment be deleted | Resolved (answer: no) | ✅ refused for completed appointments | C |
| 13338 | Drag and drop to another slot | Resolved | ✅ `editable` + drop | C |
| 13339 | Default Day, but keep Week | Resolved | ✅ month / week / day / list | B |
| 13485 | Colours by status: booked #489ce5, in progress #ee9d3a, completed #53ac75; type colours | Resolved | ✅ blue / orange / green seen | B |
| 13703 | Drag the bottom edge to change duration | Resolved | ✅ `eventResize` saves | C |
| 13743 | Bigger text when appointments overlap | Resolved | ✅ side-by-side blocks readable | B |
| — | Day header count "Tue 11/17 (4)" in mockup | — | ✅ added in Day and Week view (`98e4fa7`) | B |
| — | Block contents | — | ✅ wide blocks already matched; overlapping blocks now show time, customer, vehicle, work, technician (`98e4fa7`) | B |

### Appointment form (`appointment-form.html` ↔ `/admin/appointments/{id}/edit`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13317 | What is the repair order field for | Resolved (no reply) | ℹ️ | — |
| 13485 | Calendar colour → Type pick-list | Resolved | ✅ `appt_type` column | C, T |
| 13687 | Assigned Technician picked on the appointment, shown on RO / WO / calendar | Resolved | ✅ `technician_id` on appointment | C, T |
| 13746 | Check-in sync; "First Printed On" instead of Last Modified | Resolved | ✅ "First Printed On: set when first printed" | B |
| 14026 | Repair Order dropdown lists only open ROs | Resolved | ✅ (355a9f8) | T |

### Repair order (`repair-order.html` ↔ `/admin/repair-orders/{id}/edit`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13225 | Items don't change the appointment colour | Resolved | ✅ colour by status/type | B |
| 13321 / 13322 | Remove per-service result box and technician notes | Resolved | ✅ not on page | B |
| 13323 | Totals: discount + subtotal; GST % / PST % editable | Resolved | ✅ "+ Add discount", GST % / PST % | B |
| 13324 | How is the invoice issued; prompt on Completed | Resolved | ✅ Invoices tab + Complete flow | B, T |
| 13433 / 13480 / 14314 | Paid invoice → RO + appointments Completed and locked; refund unlocks; "Complete and lock?" when RO still open | Resolved | ✅ (8877be9, 355a9f8) | T |
| 13481 | What is History for | Resolved | ℹ️ History log present | B |
| 13672 | Odometer KM / Miles | Resolved | ✅ KM / MILES dropdown | B |
| 13687 | No Advisor; "Assigned Technician" | Resolved | ✅ | B |
| 13707 | Does removing the Technician/Work row affect the WO | Resolved | ℹ️ answer only | — |
| 13708 | Rename to "Internal Comment"; keep "Repair Name" | Resolved | ✅ both labels | B |
| 13997 | Keep Work status (it feeds progress) | Resolved | ✅ Work dropdown kept | B |
| — | Recommendations placement | — | ✅ own "Work order notes" card after Services (`e840f29`) | B |

### Repair order list (`repair-orders.html` ↔ `/admin/repair-orders`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13343 / 13427 | 6-digit numbers (RO-, QO-, WO-, INV-) | Resolved | ✅ RO-015091, WO-015091 | B |

### Work order document (`ro-document.html?doc=work_order` ↔ `/admin/repair-orders/{id}/work-order`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13319 | Overall format: client to send a sample | Resolved ("ok") | ℹ️ no sample received | — |
| 13345 / 13430 | At most Phone 1, Phone 2, then address | Resolved | ✅ | B |
| 13347 / 13425 | No colour, B/W, header aligned left | Resolved | ✅ header plain; "Repair Items" heading black (`4874f90`) | B |
| 13349 / 13431 | "Name: year make model" on one line | Resolved | ✅ "Name: 2011 TOYOTA CAMRY" | B |
| 13426 | Company line on one line; 5 items fit one Letter page | Resolved | ✅ one line; one-page print not re-tested | B |
| 13428 | "____ KM / MILES" | Resolved | ✅ "Mileage: 50852 KM" | B |
| 13670 | No Concern on the work order | Resolved | ✅ | B |
| 13689 | "Assigned Technician: X", no blank line; hidden if none | Resolved | ✅ matches mockup (only the per-item Results "Technician: ___" line remains, same as mockup) | B |
| 13690 | Remove authorisation text, signature, date | Resolved | ✅ | B |
| 13746 | "First Printed On" | Resolved | ✅ | B |

### Quote document

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13227 | "不需要" on the Environmental fee amount | Resolved | ℹ️ answered: optional fee, row kept | — |

### Invoice document

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13628 | Admin-only note, shown on Payment report | **In Review** | ✅ admin note + Remark column | C |
| 13694 | Which grey ↳ rows print for the customer | Resolved | ℹ️ answer only | — |

### Client profile (`client-profile.html` ↔ `/admin/clients/{id}`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13341 | Vehicle in appointment tables is a link | Resolved | ✅ links to the vehicle page | C |
| 13480 | Past appointments locked once paid | Resolved | ✅ | T |
| 13705 | "Repair Order" button next to Invoice / Edit Appointment | Resolved | ✅ | C |

### Services (`services.html` ↔ `/admin/services`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13483 | Tax class: keep; GST/PST override on the RO | Resolved | ✅ | T |
| 13484 | Remove the Colour column / field | Resolved | ✅ | T |

### Reports (`service-report.html` ↔ `/admin/accounting/…`)

| Thread | Request | Client status | Local | How |
|---|---|---|---|---|
| 13692 | Payment methods as tick boxes; 2.5 s delay | Resolved | ✅ 2500 ms timer | C |
| 13695 | Payment report: only Subtotal, Taxes, Grand Total | Resolved | ✅ per template header comment | C |

### Parts & stock

| Thread | Page | Request | Client status | Local | How |
|---|---|---|---|---|---|
| 13626 | Stock adjustment | Product is a search box | Resolved | ✅ | T |
| 13631 | Products → stock | Part purchase history page | Resolved: **"not needed"** | ✅ not built (reverted) | — |
| 14028 | Vendor detail | Remove the Payments tab | **In Review** | ✅ removed (`7ef90cd`) | C |

### Other

| Thread | Note |
|---|---|
| 13200, 13332 | Resolved with no text (screenshot-only pins). |

## Not checked

- Printing (one Letter page with 5 items, B/W output) was not test-printed.
- Local data is the imported legacy data (appointments end June 2024), so features that need a
  specific state (technician assigned, open RO with lines) were checked in code, not on screen.
