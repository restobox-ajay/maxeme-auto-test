# Status seam — design handoff

Decided by the owner in discussion on 2026-09-12. This is the agreed design, not a
proposal. Queue item 64 carries the same decisions; this file is the buildable form
of them.

**Amended later the same day, after two further discussions with the owner.** If you are
holding a copy whose section 5 reads "Enums are DROPPED ENTIRELY", it is out of date. What
changed:

- **Section 5 now says the OPPOSITE** of the first version: the enums are KEPT, stripped to
  slugs, as the frozen core contract, and slug and label are split.
- **Section 9 is new** — nothing may print a slug, so display needs its own seam.
- **Section 1** renames `hasStatus()` to **`isStatus()`**, and no longer describes it as a
  validity check. It is the comparison. The old name and the old wording both contradicted
  the way section 5 uses it.
- **Section 4** no longer says providers are autoconfigured by interface. They are tagged by
  hand in each bundle's own `services.yaml`, like every other seam in this app.
- **Section 7** now does its lookup against the vocabulary rather than the enum, which is
  what section 5 requires.
- **Section 8 gains "Attribution"**, and section 2b is amended to match: `setStatus()` takes
  a REQUIRED `DocumentActor` and writes the log row itself, so the deriver stops writing its
  own. Five verbs in the codebase record nobody today; this closes that by construction.
- **Section 3** states that the loader is a dictionary, not logic. The initial state does not
  come from it — the entities keep their hardcoded initialiser, and the `initial` key is gone
  from the vocabulary. That closes the first of the original open questions.

---

# OWNER RULINGS — 2026-09-12, LATE. NOT OPEN FOR RELITIGATION.

**These are the owner's own words and decisions. They override every other section of this
file, every docblock in the codebase, and any reasoning an agent arrives at on its own.
If something below contradicts something further down this file, the ruling wins and the
older text is the thing that is wrong.** Several of these reverse positions that were built
and merged before being reverted — the reasons are recorded so nobody rebuilds them.

## R1. There is exactly ONE gate

> *"The setstatus of what any change in status has to call by external methods. If there are
> guards and logic it can either transpose the logic into set status or set status to
> delegate to a specific method which could be the verb by the verb is now private and
> cannot be called by external parties. The purpose of the exercise is there is only 1 gate
> where set status can happen, and any changes we do is only at one place. Not fixing 56
> instances of handrolling."*

`setStatus($target, $actor, $comment)` is the one public gate. Every externally requested
status change goes through it. Logic either moves INTO it or is delegated to a **private**
method it calls — that choice is an implementation detail, not a design question.

## R2. The verbs are what go — not the setter

> *"SetStatus needs to be the new place for approve and approve() should be removed."*
> *"All status verbs."*

> *"Status names cannot be verbs and MUST be moved. It's about status names. Other verbs are
> out of scope."*

**The criterion is the NAME, and nothing else.** A method named after a status — `approve()`
for `Approved`, `void()` for `Void`, `cancel()` for `Cancelled` — must not exist as something
a caller can reach. Its logic moves into `setStatus()`, or into a private method `setStatus()`
delegates to.

**Every other method is OUT OF SCOPE.** Not "stays and must call the gate" — out of scope.
Do not touch it, do not rewrite it, do not require anything of it. `issue()` is out of scope
because `Issued` is not a status in the invoice vocabulary:

> *"Issue is not a status. Yes it calls set status."*

This scope line has been widened by mistake repeatedly. It is: **is the method named after a
status in that document's own vocabulary?** If yes, it moves. If no, leave it alone.

## R3. Derived status is a different door, and is NOT a filing cabinet for guards

> *"Derived status right now is only for situations where we need the object to derive its own
> status without input of the caller. It's not 'classify a verb to it'. It's after some stuff
> happened (invoice for paid) and we want to know if sales order status changes (but the
> caller won't need to know the rules or the resulting end status) - and don't need to."*

Two doors, chosen by what the caller knows:

| the caller | door |
|---|---|
| names the target — "make this Cancelled" | `setStatus($target, $actor, $comment)` |
| names nothing, wants a recalculation | `applyDerivedStatus($actor)` |

`applyDerivedStatus()` / `deriveStatus()` are **out of scope** of the consolidation. Do not
move verb guards into them.

## R4. The transitions map is DELETED. It was never asked for.

> *"Map was a problem you invented. No one asked for it; no one wanted it, it does not work."*
> *"What is this map anyways why does map decide on status logic? I don't think it's possible.
> Like you have a status from x what can it be?"*
> *"The logic was always bespoke because every status is so specific."*

The `'transitions'` table of `from => [allowed targets]` is gone, along with anything existing
only to consult it. It was wrong on the evidence, in both directions at once:

- `sales_order` allowed `Closed -> Approved`, which `approve()` refused.
- `estimate` carried `'Accepted' => []`, which made an accepted quote unmovable — a restriction
  that did not exist before Estimate was wired to it, and which broke a real test.
- It had **no consumers outside the status layer**: no screen called `allowedTransitions()`,
  no controller called `canTransitionTo()`.

A `from -> to` grid cannot express rules that depend on the document's own facts, and those
are most of them. **The bespoke logic in `setStatus()` is the authority. There is no second
source.**

### What survives: the vocabulary LIST

The list of statuses, their labels, and the `derived` flag stay. They are facts, not rules,
and they are used — `statusLabel()`, `listStatuses()`, the status badge and column, and
`applyDerivedStatus()`'s refusal to write a status not marked `derived`.

**The list of facts stays. The table of rules goes.**

## R5. This is a REORGANISATION. There is no behaviour change.

> *"Status change logic must be kept the same and honoured. With move the logic into setStatus
> or delegate it to a private method. There is no logic change. Every test must have same
> result as before. It's just organization of where the code goes."*

**Acceptance criterion, and it is self-checking: no existing assertion may be edited.** Every
pre-existing test passes unmodified with the same result. The only permitted edits are call
sites that cannot compile because a verb is gone — `$order->approve($actor)` becoming
`$order->setStatus('Approved', $actor, 'Order approved.')` — with the assertions after them
untouched.

> *"Only call sites that can't compile because a verb is gone. No exceptions. It gets moved
> or I delete the verb by hand myself."*

**NO EXCEPTIONS.** Not "restoring" an assertion, not "the old behaviour is possible again
now", not "this one was wrong anyway". A call site that will not compile is the entire list.

**If you are editing an assertion to make it pass, you have changed behaviour and you are
wrong. Stop and report it.** This is not a formality: a previous round changed the timeline,
made `Accepted` terminal, and edited the assertions that would have caught both, in the same
commit as the change. That is why the latitude is gone — the owner would rather delete a verb
by hand than have an agent decide which assertions no longer apply.

## R6. What was built wrong before, so it is not built again

Two merged attempts were reverted. Do not reproduce either:

- **A transitions map made authoritative.** It introduced refusals nobody ruled (R4).
- **`setStatus()` made protected, the verbs kept as the public API, and the interface split so
  `Invoice` and `CreditMemo` structurally could not have a public setter.** That is the exact
  inverse of R1 and R2. Reverted in `2c0a1f78`.

The guard that makes R1 and R2 permanent is
`tests/Functional/NoStatusVerbIsReachableCest.php` — it derives the verb names from each
document's own vocabulary and fails if any of them is publicly callable. It is not a
hand-kept list and must not become one.

---

**Read this whole file before writing anything.** Several decisions here reverse an
earlier position, and the reasons are given because the reasons are what stop it
being reversed again by accident.

---

## What this is for

Status handling in this app is hand-rolled per entity. 14 enums, 27 entities on bare
strings, and the legal moves live inside method bodies where nothing can read them.
Two live defects came from exactly that during one evening:

- The product form looped a hand-kept `['Active','Inactive']` list and marked
  `selected` by comparison, so a **Draft** product rendered a select with nothing
  selected — and a browser posts the first option. Opening a Draft product and fixing
  a typo in its name silently **activated** it.
- The purchase order list rendered Edit on all 37 rows and it succeeded on 7, because
  the screen kept its own idea of what was legal.

Both are the same defect: knowledge about statuses re-implemented by hand, away from
the thing that owns it.

## Scope: SHAPE ONLY

**No status is renamed. No status value changes. No transition is added or removed.**

`InvoiceStatus` keeps Processing and On Hold. Void and Cancelled keep their current
inconsistent usage across documents. What is legal today stays legal — it just stops
being buried in method bodies and becomes declared.

The owner was explicit about this and it is not a detail: a rename is a separate
decision, and mixing one into this work makes the whole thing unreviewable.

---

## The pieces

### 1. `HasStatus` — an interface, built now

Its own interface, **not** methods bolted onto `CommercialDocument`. Status is not a
document concept: a product has one, a warehouse has one, a user has one. It is about
fifty lines and building it separately now costs the same as extracting it later.

```php
interface HasStatus
{
    /** Which vocabulary governs me: 'invoice', 'purchase_order', 'product'. */
    public function statusVocabulary(): string;

    /** Resolve this class's vocabulary. STATIC — see below, it matters. */
    public static function loadStatusVocab(): StatusVocab;

    /** The raw stored value. FORGIVING — returns values the vocabulary no longer knows. */
    public function getStatus(): string;

    /** THE ONE GATE (R1). Returns the resulting status. THROWS on a move the document refuses.
     *  WRITES THE LOG ROW TOO — $actor is REQUIRED. See "Attribution" in section 8. */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string;

    /** Is this move legal FROM WHERE I AM NOW? */
    public function canTransitionTo(string $status): bool;

    /** The whole vocabulary: slug => label. STATIC — a filter bar has no row in hand. */
    public static function listStatuses(): array;

    /** Legal moves from the CURRENT state, each carrying its `derived` flag. */
    public function allowedTransitions(): array;

    /** Is MY status this one? THROWS if the vocabulary has no such status — the typo guard. */
    public function isStatus(string $status): bool;

    /** What this document's own facts say its status should be. null = derives nothing. */
    public function deriveStatus(): ?string;

    /** Applies deriveStatus(). Returns TRUE only when the status actually changed.
     *  Takes an actor like any other write; the deriver passes DocumentActor::system(). */
    public function applyDerivedStatus(DocumentActor $actor): bool;

    /** This document's own log row, empty, for setStatus() to fill. Supplied by the abstracts. */
    public function newLogEntry(): DocumentLog;
}
```

**`loadStatusVocab()` being STATIC is the load-bearing decision, and it was reached the
hard way.** The obvious designs all fail, and the failures are recorded so nobody
re-derives them:

- **Pass the vocabulary in** — `setStatus(string $status, StatusVocab $vocab)`. Fails:
  every caller then has to fetch the right vocabulary, and `$invoice->setStatus('Received',
  $purchaseOrderVocab)` sails straight through. That is precisely the hand-rolled
  knowledge this work exists to delete.
- **A service as the only door** — `$statuses->setStatus($invoice, 'Approved')`. Works,
  but the entity still needs some way to write the column and that becomes the new back
  door, guarded by nothing but a test.
- **A per-instance property filled by a Doctrine `postLoad` listener** — works for
  hydrated entities, leaves a gap for `new Invoice()` before persist.

Static resolves all three: no argument for a caller to get wrong, no instance property,
no hydration gap, and the service-locator call is confined to **one well-named method per
class** instead of leaking into every call site.

**No trait.** With `loadStatusVocab()` static there is nothing per-instance to share, and
the two abstract base classes absorb the method bodies for nine of the ten documents.
Three classes implement directly.

Other notes on methods that are easy to get wrong:

- **`listStatuses()` and `allowedTransitions()` are deliberately separate.** A filter bar
  wants all six statuses; a detail screen wants "you may Issue or Cancel." Collapsing
  them means every screen filters the list itself.
- **`listStatuses()` is static; `allowedTransitions()` cannot be** — it reads current
  state.
- **`isStatus()` is the COMPARISON, not a validity check.** It answers "is my status this
  one"; an unknown status is a typo and THROWS rather than returning false. Section 5 is
  where it replaces `$this->status === PurchaseOrderStatus::Draft`.
- **It was called `hasStatus()`. The owner renamed it — do not rename it back.** The first
  version of this file declared it as *"true/false for a known status"*, which describes
  vocabulary membership rather than what the method does. The NAME produced that error: it
  misled its own author inside the same file, and it would have gone on misleading whoever
  wrote the hundreds of call sites — silently, because a wrong reading still compiles and
  still returns a bool. Three more reasons the new name is right: `src/` and `modules/` hold
  158 `public function is*()` predicates against 13 `has*`; the entities already carry
  `isActive()`, `isDraft()`, `isOpen()` and `isSettled()`, so this is the generalisation of a
  family that already exists; and `HasStatus` the interface means "possesses a status at
  all", so the old method name spent those same two words on a different meaning a few lines
  away. None of the candidate names existed in the codebase, so the rename cost nothing
  here — it only gets expensive once the call sites are written.
- **"Is this a known status" needs no method.** It falls out of `listStatuses()`. If
  untrusted input ever needs a boolean instead of a throw — an import row, a query
  parameter, the customisation screen — that is a SEPARATE method, not this one.
- **`setStatus()` always returns a status.** Not void, not bool. The owner:
  *"we have a duty to return status at all times."* A bool can be ignored silently,
  which is the fail-silently the owner has banned.
- **`getStatus()` must stay forgiving.** A legacy or orphaned value still comes back.
  See "Checked on load" below.
- **`$actor` is REQUIRED, and that is deliberate — do not make it optional.** It was
  proposed and rejected in discussion. `DocumentActor` already ships `forAdmin()`,
  `forCustomer()`, `system()`, `automation($label)` and `named($displayName)`, so every
  call site can name one: the deriver already uses `system()`, the demo seeder already uses
  `automation()`. Optional has two failure modes and both are silent. With no default the
  log row carries an empty `userName` — a status change attributed to nobody, which is the
  hole section 8 exists to close, reopened at every call site that omits the argument. With
  a System default, a human action that forgot the argument is recorded as the machine
  having done it: not a missing record but a FALSE one, perfectly plausible on the screen,
  and nothing will ever flag it.
- **`$comment` may be optional; `$actor` may not.** The rule is that optional is fine where
  the fallback is still true and forbidden where the fallback is a guess. A missing comment
  degrades to "Status changed to Approved", which is honest. Every possible default for an
  actor is a claim about a person.

### 2. Where it goes — two abstracts and three one-liners

`CommercialDocument extends HasStatus`. The footprint is much smaller than the interface
implies, because the abstracts do the work:

| | Documents |
|---|---|
| `AbstractSalesDocument` | Cart, CreditMemo, Estimate, Invoice, SalesOrder |
| `AbstractPurchaseDocument` | DebitMemo, PurchaseOrder, VendorBill, RfqVendorReply |
| Implement directly | **SalesReturn, GoodsReceipt, VendorReturn** |

So: two abstracts plus three classes. Five edits.

Two things to know rather than discover:

- **`Cart` extends `AbstractSalesDocument`** but is not a `CommercialDocument`. Putting
  the seam on the abstract gives Cart a status vocabulary too. A cart does have a state,
  so this is probably right — but look at it deliberately.
- **`Rfq` is SKIPPED.** It was hidden from the menu at `3f602264` and parked on GitHub
  #662 pending a proper tender model. Do not give it a seam; it picks one up when #662
  rebuilds it. `RfqVendorReply` rides on the abstract for free and needs no thought.
- **The buy/sell asymmetry is not yours to fix here.** The buy side declares
  `CommercialDocument` once on its abstract; the sell side declares it on each concrete
  class, and `AbstractSalesDocument` does not implement it at all. Leave that alone.

**Master data is OUT OF SCOPE.** ProductCore, Company, Warehouse, Vendor, PriceList and
the other 20 are untouched. They implement `HasStatus` later, opportunistically, when
somebody is next in that file. No coordinated migration.

### 2b. Derived statuses — the part the first draft missed entirely

**Most statuses are not set by anyone. They are computed.** This design initially had no
account of that, and it is not a detail: on `SalesOrder`, **five of six statuses are
derived and exactly one — Void — is a human action.**

`SalesOrderStatusDeriver::statusFor()` is the model, and the important discovery is that
**it is PURE**: no constructor dependencies, reading only the order's own methods
(`getStatusEnum()`, `hasCountingInvoices()`, `isFullyInvoiced()`, `getCountingInvoices()`).
Nothing stops that logic living on the entity.

So it moves onto the interface:

```php
public function deriveStatus(): ?string;                        // this document's own facts
public function applyDerivedStatus(DocumentActor $actor): bool; // apply them; true only if CHANGED
```

`applyDerivedStatus()` takes **no status argument**. The caller does not decide what the
status should be — the document does. That removes the last place a caller could hand a
document the wrong answer, the same way `setStatus()` removed it for vocabularies. It does
take an actor, because it writes the status and therefore writes a log row like any other
write; the deriver passes `DocumentActor::system()`, which is what it already uses today.

A document that derives nothing returns `null` from `deriveStatus()` and `false` from
`applyDerivedStatus()`.

**Three behaviours `applyDerivedStatus()` must keep.** They exist today in
`SalesOrder::applyDerivedStatus()` and dropping any of them breaks the deriver:

1. **It refuses to write a status the vocabulary does not mark `derived`** — currently
   `SalesOrderStatus::derivable()`. That list moves into the vocabulary.
2. **It refuses to recompute a Void order at all.** Void is a judgement about the
   document, not a consequence of what happened to one of its invoices.
3. **It returns TRUE only when the status actually changed, and a no-op is LEGAL, not a
   refusal.** That is what keeps the log to one entry per real transition rather than one
   per flush that happened to touch the order — a no-op writes no row, because `setStatus()`
   only logs when the status moved. `setStatus()` must therefore not throw when asked for
   the status the document already has.

**`derived` is a flag on each status in the vocabulary, NOT an exclusion from the map:**

```php
'Invoiced' => ['label' => 'Invoiced', 'derived' => true],
'Void'     => ['label' => 'Void'],
```

A derived transition is perfectly legal — it is just not a human's to make. So
`allowedTransitions()` returns it like any other, carrying the flag, and a button bar
filters on that. Leaving derived moves *out* of `allowedTransitions()` would be wrong:
they are allowed, and the method would be lying.

**What stays where it is:** `SalesOrderStatusDeriver` keeps its orchestration —
`recalculate()` deciding WHEN to run. Only the pure computation moves.

**What moves, and this AMENDS the first version of this section:** the timeline entry is no
longer the deriver's to write. It wrote one itself, with `DocumentActor::system()`, right
after calling `applyDerivedStatus()`. Under section 8 the log row belongs to `setStatus()`,
so the deriver passes the actor and writes nothing. Leaving both in place would put two rows
on every derived transition.

**And the consequence for custom statuses, which is worth knowing before anyone sells
it:** a customer-added status is only meaningful if something *sets* it. Derived statuses
are computed by code, so a customer cannot extend them — adding "Pending Manager Approval"
to `SalesOrder` would produce a status the app can never reach. Customisation applies to
the human-action transitions only, which on `SalesOrder` is exactly one.

### 3. `StatusVocabularyLoader` — the central lookup

> **AMENDED by ruling R4 — read this box before the section.** Everything below about
> **`transitions`** is WRONG and is kept only so the change is legible. The `from => [allowed
> targets]` table is DELETED: no provider declares one, no document consults one, and
> `StatusVocab::allows()` / `transitionsFrom()` survive as vestigial methods only because
> `tests/Status/StatusVocabTest.php` is built on them and R5 permits no edit to an existing test
> that is not a call site which will not compile. **Do not transcribe guards into a map.** A guard
> moves into the document's own `setStatus()`, or into a private method it calls. What survives from
> this section is the LIST — statuses, labels, the `derived` flag — and every word below about the
> loader being a dictionary, about the initial state, and about the define/edit surface belonging to
> the loader rather than to the entity.

One thing answers "what statuses exist for key X". Every `setStatus()` asks it.

```php
getVocabulary(string $key)      // slugs and labels, and which of them the deriver may write
getVocabularies()               // all of them — for the future admin UI
setVocabulary(string $key, ...) // the future customisation UI writes HERE
```

**The define/edit surface belongs here, not on `HasStatus`.** `$invoice->setVocab(...)`
would mean one invoice redefining statuses for all invoices — a per-type, global,
editable thing sitting on a per-instance interface. It is the same category error as
`setTaxProvince()` on a document, which this codebase has already removed once. The
entity says *which*; the loader owns *what*.

Make it an interface if you are confident the database-backed version is coming — the
owner's stated goal is customer-defined statuses, so it probably is. Then the config
version is explicitly *the first* implementation rather than *the* implementation.

**FOR NOW IT READS HARDCODED ARRAYS. That is the decision, not a placeholder to
apologise for.** Owner: *"the loader class just loads from a bunch of pre-written hard
coded arrays for now at least."*

No database table, no settings screen, no migration in phase one. The whole point of
putting a loader in front of the arrays is that swapping the source later changes **one
implementation and no call sites** — so there is nothing to gain from building the table
before anyone can edit it.

Concretely, each provider returns something of roughly this shape. Do not treat the key
names below as fixed; settle them when you build it and keep them identical across
providers:

```php
'invoice' => [
    'statuses' => [
        'Draft'      => ['label' => 'Draft'],
        'Pending'    => ['label' => 'Pending'],
        'On Hold'    => ['label' => 'On Hold'],
        'Processing' => ['label' => 'Processing'],
        'Completed'  => ['label' => 'Completed'],
        'Cancelled'  => ['label' => 'Cancelled'],
    ],
    // 'transitions' => [...]   <- DELETED by R4. No provider declares this key.
],
```

**The loader is a dictionary, not logic.** Owner's ruling, and R4 has since taken the last
piece of logic out of it. It answers what statuses exist, what they are called, and which of
them the deriver may write. Nothing else. In particular **the initial state does not come from
the loader**: the ten entities that hardcode `= XStatus::Draft` in a field initialiser stay
exactly as they are, and there is no `initial` key in the vocabulary.

**~~The transitions are the expensive part of this whole job~~ — there are no transitions.**
The instruction that stood here, *"read the existing guards … and transcribe what they already
enforce"*, was followed, and the result is what R4 deletes. Two things went wrong and both were
predicted by nobody:

- A `from -> to` grid **cannot** hold most of these rules, because most of them are about the
  document's own facts rather than about its current status. So the grid got the easy half and
  the guards kept the hard half, which is two sources for one question.
- Where the grid and the guard disagreed, the grid won and **nobody had ruled on it**.
  `sales_order` allowed `Closed -> Approved`, which `approve()` refused. `estimate` carried
  `'Accepted' => []`, which made an accepted quote unmovable — a restriction that did not exist
  before the quote was wired to the vocabulary.

**So: a guard is not transcribed anywhere. It MOVES.** Into the document's own `setStatus()`,
or into a private method `setStatus()` calls — rulings R1 and R2. An invoice holding payments
cannot be cancelled, a purchase order with receipts cannot be cancelled, only a Draft order can
be approved, an accepted quote does not move: each of those is one statement, in one method, on
the document that owns it.

### 4. Definitions are DISTRIBUTED, the lookup is CENTRAL

One loader, many contributors. Not one big file.

A small `StatusVocabularyProvider` interface returning definitions, collected as a tagged
service. Core ships one provider; `ProcurementBundle` ships its own; a future bundle ships
its own. **This is the pattern the app already uses for menus** — see
`ProcurementMenuOverrideProvider`, and about twenty other `app.*` tags
(`app.fee_calculator` on nine services, `app.admin_menu_override_provider` on five,
`app.document_prefix_provider` on two).

**Each provider is tagged by hand in its own `services.yaml`, the same as every other seam
in this app.** Owner's ruling: `services.yaml` changes to accommodate another tagged
service, which is routine. Core's `config/services.yaml` already imports
`../modules/*/config/services.yaml`, so a bundle contributes without touching core at all:

```yaml
# config/services.yaml — core's own provider, and the loader that collects them
App\Status\CoreStatusVocabularyProvider:
    tags: ['app.status_vocabulary_provider']

App\Status\StatusVocabularyLoader:
    arguments: [!tagged_iterator app.status_vocabulary_provider]

# modules/ProcurementBundle/config/services.yaml — two lines, in the bundle's own file
ProcurementBundle\Status\ProcurementStatusVocabularyProvider:
    tags: ['app.status_vocabulary_provider']
```

An earlier version of this section said providers were "autoconfigured by interface so
nothing is tagged by hand", which contradicted the menu example it cited in the next breath:
those providers ARE hand-tagged, and the app has no `registerForAutoconfiguration()` call
and no `_instanceof` block anywhere. Hand-tagging also keeps the seam greppable — every
contributor to every seam is found by searching `app.`, which an autoconfigured service
would not be.

If purchase-order statuses lived in a core file, switching the bundle off would leave
a vocabulary for documents that no longer exist.

**Two things to settle while building this:**

- **Collisions must fail loudly at boot.** If two providers claim `invoice`, throw in
  the loader's constructor. Letting the last one win is impossible to debug.
- **Bundle-off behaviour is an OPEN QUESTION, not an assumption.** Menus solve this
  with `denyIfInactive()` on controllers rather than by not registering services, so a
  switched-off bundle's services still exist in this app. That would leave a
  purchase-order vocabulary in the loader with the bundle off. Probably harmless —
  nothing asks for it — but **confirm it rather than assuming**, because the
  customisation screen is exactly what would surface documents that do not exist.

### 5. Enums are KEPT — stripped to slugs, as the frozen core contract

**This reverses the ruling that stood in this file earlier the same day, which said the
enums go entirely.** The owner's reason for the reversal: the core statuses must not move,
because scripts depend on them, and an enum is a good way to reinforce that. This is not a
softening of the argument against two sources of truth — it is a different job for the
enum, and the job is exactly one thing.

**Slug and label are split, and that split is what makes keeping the enum safe:**

- **The slug** is the value stored in the column, the thing guards compare, and what
  scripts, imports, exports and integrations depend on. It is frozen. `'On Hold'` and
  `'Partially Received'` keep their spaces and capitals forever — that is the wire format,
  and this file already rules that no status is renamed.
- **The label** is display only. It lives in the vocabulary and a customer may change it
  freely. Relabelling `Draft` to `Unsubmitted` touches no data and breaks no script.

So **slugs stop doubling as labels**, which they do today. Nothing may print a slug again —
see section 9.

**The enum's only job is to declare the core slugs.** It is stripped to bare cases:
`label()`, `SalesOrderStatus::derivable()` and anything else it carries move into the
vocabulary and come OFF the enum. Leave them on and labels and derived flags have two homes
again, which is the drift the owner rejected. **Everything at runtime comes from the
vocabulary** — statuses, labels, derived flags. (Not transitions: there are none, R4.)

**After the migration, no production code references a status enum at all.** Not a guard,
not a template, not the loader. In particular a comparison is `$doc->isStatus('Draft')`,
never `PurchaseOrderStatus::Draft->value` — reach for the case and the enum is back in the
runtime path. Only the checks below touch it, which makes "no production references" a
third conformance test in the same family as sections 8 and 9. The enum is therefore not a
fixture anybody has to keep in step with the vocabulary; it is pinned on purpose, and the
only reason to edit it is a deliberate, reviewed change to the core contract.

**The check: every enum case must exist in its vocabulary.** It runs at three moments, at
three severities, and they are not interchangeable:

1. **A conformance test**, enumerating the enums rather than naming them. Fast feedback,
   blocks the merge, protects what ships.
2. **At container build / cache warm** — a hard failure, at the same moment section 4
   already mandates one for provider collisions. This is the layer that matters once the
   loader reads a database instead of arrays: a customer can then drop a core slug from
   their own install and CI stays green, because CI never sees their data. Failing here
   stops a deploy while somebody is watching, rather than killing a live request, which is
   what makes a hard failure acceptable at this layer and not at the next.
3. **At hydration** — section 7, report and never refuse. That layer stays soft: a row you
   cannot load is a row you cannot repair.

The reverse direction — a vocabulary status with no enum case — is also an error **for
now**, because this phase is shape-only and adds no statuses. It stops being an error the
day customers can define their own.

**What this still costs, unchanged by keeping the enums:**

- Every guard that reads `$this->status === PurchaseOrderStatus::Draft` becomes
  `$doc->isStatus('Draft')`. There are hundreds of such call sites.
- The buy-side entities have **enum-typed properties** (`private PurchaseOrderStatus
  $status`), so their property types change to string. The sell side is mostly already
  string-typed. **No schema change** — the database column has always been a plain string;
  only the PHP type moves.
- You still lose compile-time checking at the call sites, because the comparison is a
  string. The enum does not guard those; `isStatus()` does, by throwing.

**And here is the trap that comes with it, which MUST be built for.** Today a typo in
`PurchaseOrderStatus::Draftt` is a fatal error before the code ever runs. Tomorrow
`getStatus() === 'Draftt'` **silently returns false** — no error, wrong branch taken,
nobody told. That is precisely the fail-silently the owner has banned everywhere else in
this app, and moving the call sites off the enums introduces it by default — keeping the
enums as the slug contract (section 5) does not fix this, because the comparison itself is
still a string.

So a raw `=== 'Draft'` comparison is not acceptable anywhere. Status comparisons go
through a method that **validates its argument against the vocabulary and throws on an
unknown status**, putting the loudness back at runtime where the compiler used to put it:

```php
$doc->isStatus('Draft')    // true/false
$doc->isStatus('Draftt')   // THROWS — no such status in this vocabulary
```

Add that to the interface. It is the replacement for the safety the enum was providing,
and without it this change is a net loss in correctness.

A conformance test should assert no production code compares `getStatus()` to a string
literal directly. Derive it; do not keep a list of allowed places.

### 6. Refusal: throw, and catch it centrally

`StatusTransitionRefused extends DomainException`, plus **one global exception
listener** that recognises it, flashes the message and redirects.

The owner's condition was exact: *"a throw works for me (as long as its caught)"*, and
then: *"im always nervous someone forgets to catch a throw."* So do not rely on
discipline — an uncaught `DomainException` is a 500, which is the opposite of loud.

With the listener, forgetting to catch locally degrades to *the right message
appeared*. Catching locally becomes an optimisation for a nicer screen, not a
requirement you can fail to meet. It also means the refusal wording is written once on
the exception rather than each controller inventing its own — the same argument as the
shared colour classes.

The alternative, a conformance test asserting every caller wraps it, is a hand-kept
list of callers. Do not.

### 7. Checked on load — report, never refuse

Validate the stored status when the object hydrates. **On a miss, mark it — do not
throw.** A row you cannot load is a row you cannot repair, and you would be fixing it
in SQL.

`ProductCore` already landed the right shape and is the reference: the row hydrates,
`getStatus()` returns the stored value, the lookup finds nothing, `isSellable()` is false,
and the grid renders `Waiting for Stock (unrecognised)`. There is a conducted test that
writes a stray straight into the column and proves exactly that. Copy it.

Copy the shape, not the plumbing: ProductCore does that lookup against its enum today, and
under section 5 the lookup is against the VOCABULARY, since no production code reads an
enum. The behaviour is identical — a value nobody recognises still loads and still says so.

**Amended after stage 2 shipped: the TRANSITION side has to say the same thing, and it did
not.** The guard `setStatus()` landed with read
`!$vocabulary->has($current) || !$vocabulary->allows($current, $status)`, and
`allowedTransitions()` answered the empty list for the same case. The first clause refused
**every** move out of an unrecognised status — the throw fired before the target was even
considered — so a sales order holding a legacy value like `Processing` could not be
transitioned by any means, including the status control on its own detail screen, and could
not be SAVED at all, because the save path reaches that guard through the deriver. Hydrating
a value the document can then never leave is not "report and never refuse"; it is a
permanent dead end that only SQL can undo, which is exactly what this section exists to
prevent.

The owner's ruling: **you can always leave a place that no longer exists; you just cannot go
back to it.** So the two ends of a transition are deliberately NOT symmetric:

- an unknown **FROM** permits moves out, to any status the vocabulary does know. It is what
  `readStatus()` found in the column, never a literal anybody typed, so tolerating it hides
  no typo.
- an unknown **TO** is refused, always. It is the caller's argument, and section 5's typo
  guard is the whole reason `isStatus()` exists rather than a raw `===`.

`allowedTransitions()` therefore returns the legal targets rather than nothing: the empty
list reads as "final" to everything that consumes it, so a picker built from one would offer
a stranded document no way off its value — an exception that no longer throws, with nothing
on screen to use instead, is not a fix.

> **AMENDED by R4.** Both halves used to live in `StatusVocab::allows()` and
> `StatusVocab::transitionsFrom()`. With the transitions table deleted they live on the document
> instead: `AbstractSalesDocument::allowedTransitions()` offers every status the vocabulary knows
> except where the document already is, and except anything a terminal state puts out of reach,
> which it learns by asking the document's own `assertStatusChangeAllowed()`. The RULE above is
> unchanged and the answers are identical — the grid said "everything except where you are" for
> every live status anyway.

### 8. Everything writes through `setStatus()`

The loader is only worth anything if it is the single door. If a repository, a raw SQL
update or a bare setter can still write the column, the loader is decoration.

Add a conformance test that nothing outside the entity writes the status column
directly. **Derive it — do not keep a list of allowed writers.**

Watch for raw SQL: the end-to-end walkthrough found seeders writing status literals in
embedded SQL, invisible to any PHP-level check.

> **BUILT.** `tests/Functional/NoStatusVerbIsReachableCest.php` is that test, and it does both
> halves: it derives the banned verb names from each document's own vocabulary and fails one that is
> publicly callable, and it scans every production file for a call to one and for a raw `UPDATE`/
> `INSERT` touching a status column. It is also what makes rulings R1 and R2 permanent, so it must
> not be turned into a hand-kept list and must not be edited to accommodate a change.

#### Attribution — the log row is written by `setStatus()`, not beside it

**Ruled in discussion: every status change records who made it, and the one door writes the
record.** If the log is written next to the setter rather than by it, attribution is
discipline — and this codebase already shows what discipline produces. `SalesOrder::approve()`
and `Invoice::issue()`, `complete()`, `cancel()` all take a `DocumentActor`, but
`CreditMemo::issue()`, `CreditMemo::void()`, `DebitMemo::issue()`, `DebitMemo::void()` and
`BackorderFulfillmentEntry::complete()` take none at all. Five status changes that record
nobody, in the same codebase as seven that do.

How it works today, which is what this replaces: `SalesOrder::approve($actor)` sets
`$this->status` directly and then calls `addLog(new SalesOrderLog()->setUserName(
$actor->displayName)->setComment('Order approved.')->setType('System'))`. The log stores a
NAME STRING, not an actor object and not a user id — `DocumentActor` is flattened to
`displayName` at the moment of writing.

So:

- **`setStatus()` writes the status AND the log row.** The verbs stop writing logs; they
  pass their wording through instead — `$this->setStatus('Approved', $actor, 'Order approved.')`.
  Under R2 the status verbs then stop existing at all, and their wording becomes the default
  comment `setStatus()` itself supplies when a caller names only the target: `SalesOrder` still
  writes "Order approved." and "Order voided (was Approved)." byte for byte.
- **It logs only when the status actually moved.** A no-op is legal and silent (section 2b).
- **`newLogEntry(): DocumentLog` is how a generic method writes a per-document row.** There
  are five log entities — `SalesOrderLog`, `InvoiceLog`, `EstimateLog`, `PurchaseOrderLog`,
  `VendorBillLog` — with identical shapes (`setUserName()`, `setComment()`, `setType()`) and
  **no interface or base class between them**. `DocumentLog` is new and is the smallest thing
  that lets `setStatus()` fill a row it did not construct. The two abstracts supply
  `newLogEntry()` for nine of the ten documents, as they do for everything else in section 2.
- **A conformance test that nothing outside `setStatus()` writes a status log row**, derived
  rather than hand-listed, the same as the two tests above.

### 9. Everything displays through `statusLabel()`

Section 8 puts writing behind one door and section 5 puts comparing behind another.
Reading for display needs the same treatment, and the first version of this file did not
say so anywhere.

Today every screen prints the raw status value and looks correct, because the slug and the
label are the same string. The moment section 5 splits them, each of those screens shows
the wire value instead of the customer's word — and it fails silently, because the page
still renders something plausible.

- **One door, on the entity**: `$doc->statusLabel()`. It belongs beside the other methods
  because the entity already knows its vocabulary; a Twig filter would have to be handed
  the key again at every call site, which is the hand-rolled knowledge this work exists to
  delete.
- **A conformance test that no template prints the raw status property.** Derive it from
  the templates themselves, and pair the absence assertion with a positive control (#627).
- **Dropdowns and filter bars carry both**: the slug as the option value, the label as the
  text. They are the most likely things to be written with a single string today, and a
  filter that posts a label silently matches nothing.
- **Anything that RECORDS a status stores the slug and renders the label** — timeline
  entries, audit rows, emails, exported documents. Store the label and a later relabel
  quietly rewrites history.

---

## Still open — decide before or during, do not silently pick

1. **Bundle-off behaviour** (section 4).

*(The static-versus-instance question is settled — see section 1. `loadStatusVocab()`
and `listStatuses()` are static; `allowedTransitions()` is not.)*

---

## Ground rules for whoever builds this

- **Conducted tests (#624):** real screens, plain form POSTs with the CSRF scraped from
  the page, every claim re-read from the database BY COLUMN, self-created data, and
  every case carrying a row that must NOT change. **#627:** never `see()` a bare number
  or a bare word that occurs elsewhere on the page, and pair every absence assertion
  with a positive control.
- **A green suite is NOT evidence about migrations.** Both suites build schema from
  Doctrine metadata and never replay the chain. Only `bin/ci-migration-replay` proves
  one, and only if its fixture holds rows in the table being touched. This work should
  need no migration — the column is already a string. If you think it needs one, stop
  and say why.
- **PHPUnit exits 1 on clean main** — `failOnDeprecation` plus three PHP 8.4
  `ProductImportService` deprecations. That file is the only offender, so a targeted
  run excluding it exits 0.
- **Targeted verification only.** Run what your change can reach. No full suite; one
  worker runs a checkpoint suite for the fleet.
- **The core freeze in `docs/QUEUE.md` is real and per-change.** This touches core by
  necessity. Say what you took and why. **The owner has named what this work may take:
  `CommercialDocument` and the two abstract document classes (`AbstractSalesDocument`
  and `AbstractPurchaseDocument`).** That is the list.
- **Say plainly if anything here is wrong.** Nine agents corrected this design's author
  in one evening and every correction was worth more than the work it interrupted.
