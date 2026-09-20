# Decisions needed — write your answer under each ❓ and send it back

Generated 2026-09-11. Source of truth stays `docs/feature_list.json`; this is just the
question sheet. Three parts:

1. **Needs your call** — I will not start these until you answer.
2. **Already ruled** — no action from you, listed so you can see what's coming.
3. **Plain defects** — no decision needed, I'm dispatching these.

---

# PART 1 — NEEDS YOUR CALL

## 42. You can't see which POs are late
Expected Arrivals shows 2026-01-15 (8 months overdue) in the same black as next week's.
No badge, no count, no filter. Also: the date box says "due on or before" but secretly
*also* returns POs with **no** expected date.

Plan: red "Overdue" badge + a count + an "Overdue only" filter, and fix the date box to
stop returning rows you didn't ask for.

❓ Good? And do you also want an "Overdue: 12" tile on the procurement landing page?
**Your call: ANSWERED — "Colouring is fine but should use shared class names and core css
defines all classes that we know of. So all objects with similar traits eg 'late' is same
treatment."** Taken as: build it, but the badge uses a shared core `late` class, not a
procurement-local colour. The landing-page tile is folded into #48 rather than asked twice.

---

## 43. The vendor page tells you nothing about the vendor
It's a contact-card editor. No POs, no bills, no balance. AP Aging links the vendor name
straight here — so you click "Blackrock owes CAD 1,142, 90+ days" and land on an address
form. It also shows raw DB column names to the user (9 of them, e.g. `payment_term_id`)
and has two Notes boxes, one labelled "Notes (legacy field)".

Plan: mirror the customer page we already shipped (subtabs: POs / Bills / Returns /
Payments), hide the DB names, kill the legacy Notes box.

❓ Mirror the customer page? Anything else you want on a vendor's front page?
**Your call: ANSWERED — mirror it; POs / Bills / Returns / Payments; notes stay, like the
customer page.** Two corrections from reading the templates: "legacy" is accurate (Company
has ONLY threaded notes; Vendor has threaded notes AND an older single box), so "like
customer" means one threaded list, with the old box's text migrated into a note rather than
dropped. And it is EIGHT db names on screen, not nine — `payment_term_id` is in a Twig
comment and never renders. All eight are in help text written in developer language; they
get rewritten in plain English, not deleted.

---

## 44. Most documents can't be printed or emailed
Only the PO and the RFQ reply can. Missing: **debit memo, vendor return, goods receipt,
vendor bill** — and on the sell side, **credit note and sales return**.

❓ All six, or a priority order? (My order: vendor bill → goods receipt → credit note →
debit memo → vendor return → sales return.)
**Your call: ANSWERED — "yes you need to do a full sweep. basically every doc has a pdf and
print version. same button layouts."** So not six gaps in an order: print + PDF is a
property every document has, with identical buttons, enforced by a conformance test that
discovers documents rather than a list. #55 becomes a prerequisite, because the existing
discovery route misses Goods Receipt — which is one of the documents that needs printing.

---

## 45. The bill form never shows what it adds up to
Three problems on one screen: (1) no running total while you type, (2) exactly **3** line
rows, forever — a 10-line invoice takes 4 saves, (3) the line table is headed "Charges"
while the actual charges table below it is headed "Freight and other charges".

The PO form and the RFQ form both already have "add another line". The bill form just
skipped it.

❓ Fix all three? (I assume yes.)
**Your call: ANSWERED — bigger than three fixes.** "Bill is not even done ... add another
line, total, subtotal, custom fees, taxes, everything. audit bill compare it to invoice and
make sure bill isn't missing anything." So it becomes an audit of bill against invoice in
both directions. Item 15 claimed this work and I had it marked done — reopened.

---

## 46. The PO list is the only list that doesn't fit the screen
Shows **5 of 37 rows** in a squashed inner scrollbox. Money is printed 8 different ways in
one column (`CAD 3296.54`, `CAD 1520`, `CAD 4137.5`, `USD 58.2` — 4137.5 looks truncated).
And "Outstanding 86.00" is a *unit count* sitting in a money column with nothing saying so.

Plan: copy the bill list (row-count dropdown + status quick-tabs — it fits exactly); money
always 2 decimals with currency everywhere; rename the column "Units outstanding".

❓ OK?
**Your call: ANSWERED — "ok".** Approved as planned, using shared core colour classes per
your #42 ruling.

---

## 47. The Edit button works on 7 of 37 POs  ← real question here
Edit shows on every row, succeeds on 7 (the drafts). Worse: once **anything** has been
received, cancel is also refused — so an issued PO with a wrong price and one receipt
against it has **no correction path at all** except closing it short.

The easy half: hide Edit when it can't work.

❓ The hard half — how do you fix a wrong issued PO?
  **(a)** Close it short + raise a new PO (no new code, what you can do today)
  **(b)** Amend/revise: issued PO gets a revision, old version kept for history
  **(c)** Allow editing lines that have no receipts against them yet
**Your call: ANSWERED — borrow the sell side, which already does what Zoho/NetSuite do.**
PO locks at Closed/Cancelled only; Issued and Partially Received stay editable. RECEIVED is
the floor for quantity — can't reduce below it, can't delete a received line, nothing
received means the line is free. PRICE is floored by BILLS, not receipts: a received-but-
unbilled line is still correctable. A bill disagreeing with the order raises the existing
price-variance exception rather than being refused. Cancel stays blocked once anything is
received. TO CHECK FIRST: I could not find a guard stopping a SALES order line being reduced
below what is already invoiced — if that hole is real, the buy side must not copy it.

---

## 48. The procurement landing page links to nothing
It names four jobs ("receive goods", "match bills"…) and links to none of them. The
explanation is above the fold, the actual work is below it. Exceptions (48 rows!) isn't
mentioned at all. You open this screen 20x/day.

Plan: turn it into a work list with live counts — Overdue POs, Awaiting receipt,
Exceptions, Unbilled receipts — each one a link.

❓ Good? Which counts do you actually want on it?
**Your call: ANSWERED — (a) keep them on the procurement page, not the global dashboard, and
this is LOW PRIORITY, below the bill audit (#45).** Looked up NetSuite's Procurement
Dashboard as instructed: its reminders include Late Purchase Orders, RFQs Awaiting Response,
RFQs Awaiting Award, POs to Approve, and KPIs for Open A/P by Vendor and Vendor Return
Amount. The rule worth stealing: a reminder renders ONLY when matching transactions exist —
a tile at zero is not shown, so nothing is padding. The six counts already exist in the
controller; the work is making them links, adding Exceptions, putting them above the guide
text, and hiding zeros. The guide text stays — it exists because the bundle can be switched
off and a fresh admin lands here knowing nothing.

---

## 49. Exceptions can't tell a $5 problem from a $5,000 one
No money column, so no sorting by size. And each row states the **finding**, not the
**move**: "Billed but not received" — OK, so do I chase the receipt or dispute the bill?
Neither is clickable from the row. (Good news: every row does name and link its document —
I checked that worry and it's fine.)

Plan: add an amount column + sort by it, and put the 1–2 real actions on each row.

❓ Good?
**Your call: ANSWERED — "yes this is good. Add."**

---

## 50. Sending an RFQ is a wall of vendor checkboxes
Flat list, no search, no grouping, no "vendors who actually supply this item".

❓ Which?
  **(a)** Search box + recently-used at top (cheap)
  **(b)** Filter to vendors who have a price on file for the item (smarter, more work)
  **(c)** Both
**Your call:**

---

## 51. Bad ids on create screens 400 or go blank
`/purchase-order/new?vendor=99999` either throws a raw 400 or silently pretends you asked
for nothing. Same family as the fail-open list bug.

Plan: say "That vendor doesn't exist" and carry on.

❓ Confirm.
**Your call:**

---

## 9. Product import: "bag of 50" — one piece left
**This one got built while you were away**, so the big question is answered: the
importer now **accepts** the row, imports the product in full, leaves its unit unset, and
flags it — the product form then opens on "Not set" and names `12/Case` as the thing to
settle. It never invents a unit of its own.

One piece is still open:

❓ A flagged product is **usable right now** with its unit unresolved. Should it be?
  **(a)** Leave it usable, just flagged (what's built)
  **(b)** Block it from being sold/counted until someone sets the unit
**Your call:**

---

## 52. Should the repo's working notes show up in the app's sidebar?  ← new
Found the hard way tonight: **every `.md` file under `docs/` becomes a row in the admin
sidebar.** That's why my own decision sheet broke the test suite — I put it in `docs/` and
it appeared in the menu. I moved it to the repo root, which fixed it.

But `docs/` currently mixes two things: real reference material an admin might want, and
the repo's own working notes (plans, code reviews, my queue file).

❓ Which?
  **(a)** Leave it — anything in `docs/` shows in the sidebar, authors just have to know
  **(b)** Exclude a subtree, e.g. `docs/notes/**` and `docs/plans/**`, from the sidebar
**Your call:**

---

## 54. Status colours — categories, not hues  ← new
Right now a status gets a colour only if someone wrote a CSS rule for its name. So
`Accepted`, `Rejected`, `Issued`, `Disputed`, `Partially Paid` and others are all the same
grey as each other. Nothing breaks when a new status is added — it just quietly looks like
everything else.

Proposed: **four** categories, because a status is *scanned*, not read —
grey "not real yet" (Draft) · amber "live, waiting on someone" (Issued, Sent, Open) ·
green "done right" (Paid, Closed, Received) · red "dead or wrong" (Void, Cancelled, Rejected).
Only real change to today: the blue "in progress" tier folds into amber.

And the part that matters more than the colours: each status **declares** its category in
code, with a test that fails if a new status doesn't — so this can't rot again.

❓ Two questions:
  (a) Four categories, or do you want a fifth for **Disputed** (red by outcome, amber by workload)?
  (b) The "declare it in code + test" mechanism — yes?
**Your call:**

---

## 34. Move a payment between invoices
You ruled an invoice with payments can't be cancelled — you have to remove or move the
payment. Only "remove" exists, so the error message can only offer half an answer. Zoho
lets you reassign.

❓ Build now, or park it?
**Your call:**

---

# PART 2 — ALREADY RULED (no action from you)

| # | Thing | Your ruling |
|---|---|---|
| 18 | Company → Customer | Labels only, no code/schema rename |
| 32 | Warehouse has no address | Give warehouse a real address; stop typing tax province per bill |
| 33 | Draft delete | Drafts are safe to delete |
| 35 | Sidebar `+` affordance | Item names its own parent; no icon = plain button, fine |
| 36 | Inventory screen 7 of 14 buckets | All buckets + warehouse filter + column chooser + a test that a new bucket must appear — **built** |
| 38 | Voiding a credit memo | Same 3-outcome treatment as the buy side (back in stock / written off / 0-qty note) |
| 40 | Void bill "still owes money" | Balance arithmetic is correct; add a **separate** real-AP figure (0 when void/draft) |
| 41 | Status hidden by one CSS rule | **Never hide status. Big and loud on detail pages, a column in every list.** |

---

# PART 3 — PLAIN DEFECTS, DISPATCHING (no decision needed)

_(#14, the PO/bill list conformance, landed while you were away — dropped from this table.)_

| # | Thing |
|---|---|
| 24 | "Restock" on a debit memo moves no stock and says nothing — stock is overstated, silently |
| 25 | Six provenance links stored but never written (can never be backfilled) |
| 27 | Vendor return & debit memo have no edit screen — the code exists, nothing calls it |
| 28 | SalesReturn has the same no-exit bug as the vendor return, in core |
| 30 | Three lists fail **open** on a bad company id — they show every customer's documents |
| 31 | Sell side can double-invoice the same line through drafts (buy-side fix already proven) |
| 37 | PO tax province typed by hand — derivable from the warehouse FK, no schema change |
| 39 | Four admin lists fetch every row, unpaged, into a bare table |

---

# CHANGES SINCE YOU GOT THIS FILE

- **#22 (break a case) is gone** — it got built and landed while you were away.
- **#9 shrank** — the importer got built too; only the "block it or not" half is left.
- **#52 added** — the `docs/` sidebar question above.
- **#54 added** — status colours.
- Everything else is unchanged. Your answers to the other questions still apply.
