# Barcodes

**A product answers to many identifiers. This is where they live, and what draws them.**

Issues: [#607](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/607) (attach),
[#608](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/608) (generate),
[#609](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/609) (scan).

| | |
|---|---|
| Screen | `/admin/bundles/barcodes` — every barcode, filtered and paged |
| | `/admin/bundles/barcodes/product/{id}` — one product's barcodes |
| Sidebar | Apps › Inventory › **Barcodes** |
| Table | `product_barcode` |
| Depends on | core only |
| Depended on by | WarehouseOpsBundle (scan console, labels, dispatch scan), ProcurementBundle (receiving scan) |

---

## Why this is its own bundle

Two bundles need to turn a scanned string into a product: **WarehouseOpsBundle** at the scan console
and at dispatch, and **ProcurementBundle** at goods-in. They are peers — neither imports the other,
and `InventoryDepthBundle\Delete\ReferenceCounter` says so out loud — so a barcode owned by either
one would have made the other depend on it sideways for something that is not its business.

It is also the honest answer to what a barcode *is*. A barcode is catalogue data: the number printed
on the box, true whether or not anybody is running a warehouse. Putting it in the warehouse bundle
would have been putting product data in an operations app.

So: this bundle owns the table and the two symbology encoders, both consumers depend on it, and the
dependency graph stays a tree.

## The shape, and why it is not three columns on the product

```
product_barcode
  id
  product_id     NOT NULL, FK product_core(id) ON DELETE CASCADE
  code           VARCHAR(64)  the scannable string, as printed
  kind           upc | ean | gtin | vendor_part | customer_part | internal
  party          NULL         whose code it is, as a person reads it off the carton
  is_primary     the one a label encodes
  note           NULL
  created_at
  UNIQUE (product_id, code)
  INDEX (code)
```

A product carries **many** identifiers and they are not a fixed set: a UPC on the retail pack, an
EAN-13 on the European carton, a GTIN-14 on the case, and a different part number at each of three
suppliers who all sell the same tyre. Three columns named `upc`, `ean` and `barcode` cannot hold
three suppliers' part numbers for one product, which is the thing a distributor hits daily.

This is the right model wherever it lives, and it is worth saying why it is a different judgement
from the one #601 made about units of measure. A product has ONE selling unit, so a side table for
it would be a workaround for not being able to add a column. A product has MANY barcodes, so a side
table is the model — it would be the right shape with `product_core` wide open.

### `code` is not globally unique, on purpose

Two different vendors legitimately use one part number for two different things. A global unique
index would refuse to record that, which is refusing a true fact. What happens instead is that
scanning such a code **resolves to nothing and names both products** — the same treatment
`ScanResolver` has always given a value that is both a bin code and a SKU. Guessing between them
books stock against the wrong product about half the time, silently, and nobody finds out until a
count.

`(product_id, code)` IS unique: the same string twice on one product is a typo, never a fact.

### `party` is free text, and that is a decision

[#607](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/607) sketched `vendor_id` and
`company_id` foreign keys. Neither is here. `vendor` belongs to ProcurementBundle and `company` to
core, and a foreign key from a product's identifier into a bundle that can be switched off would
make catalogue data depend on that bundle surviving. `party` records whose code it is as the name a
person reads on the carton, which is what the receiving desk and the label actually need. Promoting
it to a real association later needs no change to what is stored.

## Nothing is ever invented for you

The migration creates `product_barcode` **empty** and it stays empty until a person puts something
in it. Nothing copies `product_core.sku` into it, nothing mints a code for the products that have
none, and nothing reads `purchase_order_line.vendor_sku`.

That matters because of what a barcode claims. A SKU is this business's own name for a thing; a
barcode is what is printed on the box. Asserting that every SKU is also a scannable barcode would be
false for every product that arrived with a real UPC and false again for every one that arrived with
none. And minting an internal number for the seven thousand products that have no barcode would put
a code on every shelf tag in the catalogue that matches nothing physically in the building.

**A product with no barcode behaves exactly as it did before this bundle existed.** Its label
encodes its SKU. It scans by its SKU. Every screen says so rather than showing a blank.

## Generating: prefix 2, and nothing else

The Generate button on a product mints a valid **EAN-13 beginning with `2`**.

GS1 reserves EAN-13 prefixes 02 and 20–29 for *restricted circulation within a company* — the range
every supermarket's own in-store labels live in. A number minted there can never collide with a real
manufacturer's GTIN anywhere in the world, because no manufacturer can be issued one.

That is the whole reason it starts with `2`. A generated code beginning `0` or `5` would be a claim
on a GS1 company prefix this business does not own: it would scan, it would look correct, and it
would be somebody else's number. Sortly generates its own codes the same way, for the same reason.

It is one product at a time and always will be. See above.

## Symbologies: Code 128 and QR, both hand-written, no dependency

| | Code 128 | QR (Model 2) |
|---|---|---|
| Shape | a line of bars | a square grid |
| Default | **yes** | no |
| Best at | a fixed receiving station with a keyboard-wedge scanner | a phone camera held over a shelf |
| Length cost | width | area |
| Damage | one check character | Reed-Solomon, level M, ~15% recoverable |

Both are written out in `src/Symbology/`. A symbology is a lookup table and some modular arithmetic;
the alternative was a Composer dependency whose release cycle this project would then own. `Deno
only, no npm` applies to JavaScript and the same instinct applies here.

**Code 128 stays the default and is not going anywhere.** A wedge scanner reads a linear code faster
and more reliably than a camera reads a QR, and the wedge is the hardware a receiving desk already
owns. The label screen chooses per print.

The QR encoder is deliberately limited to byte mode, error-correction level M, and versions 1 to 6
(21 × 21 up to 41 × 41 modules, 14 to 106 bytes). `product_barcode.code` is `VARCHAR(64)`, so
version 6 is well past anything this application can produce — and stopping at 6 is why there is no
version information block in the code, because that field only exists from version 7 up and a field
that cannot occur cannot be got wrong.

`QrTest` decodes what the encoder produced, with a reader that shares no code with it, and checks
the format information and the generator polynomial against the published tables. A snapshot test
would only prove the encoder still agrees with itself.

## The check digit is verified, and that is worth a retype

A UPC, EAN or GTIN carries its own proof: the last digit is a modulo-10 checksum of the ones before
it. A transposed pair — the commonest typing error there is — produces a number that fails
arithmetic rather than one that quietly names somebody else's product. The form refuses it.

Vendor and customer part numbers are **not** check-digit verified: they are whatever the other party
says they are and have no checksum to fail.

## Switching it off

App Management's Active/Inactive switch works the way every other bundle's does, through
`BarcodeDirectory` — the one door every other bundle reads barcodes through. With it Inactive:

- `ScanResolver` finds no barcodes and matches on `product_core.sku` alone
- `LabelCatalog` finds no primary and encodes the SKU
- the receiving and dispatch scan screens still resolve a SKU
- `/admin/bundles/barcodes` reads as 404 — absent, not forbidden

**Every row stays in the table.** Switching it back on restores every barcode. `bin/ci-bundles-off`
runs the whole functional suite with this bundle (and the other three) Inactive, which is what
proves the pre-barcode behaviour is unchanged rather than asserting it.

## What this bundle deliberately does not do

- **It does not touch `product_core`.** Not a column, not a comment. The FK points one way, with
  `ON DELETE CASCADE` from product to barcode and nothing pointing back.
- **It does not decide what a label looks like.** Geometry, stock and page breaks are
  WarehouseOpsBundle's `LabelTemplate` and `LabelSheetRenderer`; this bundle only says what a value
  looks like as bars or as squares.
- **It does not link to another bundle's routes.** Its two screens link only to each other. Traffic
  goes the other way: the label screen and the scan console link here, because they already depend
  on this bundle in PHP and a `path()` call to a deleted bundle's route is a 500 rather than a
  missing link.
