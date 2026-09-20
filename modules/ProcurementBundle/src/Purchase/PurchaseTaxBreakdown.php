<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Exception\TaxJurisdictionNotCovered;
use App\Service\OrderTaxBreakdownService;
use Psr\Log\LoggerInterface;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use TaxBundle\Tax\TaxCalculatorResolver;

/**
 * The tax on a purchase document, computed with the SELL SIDE'S OWN tax rules (#655, #658).
 *
 * There are two purchase documents and this is the tax rule for BOTH. `computeRows()` is the rule
 * itself and names neither of them: it takes a province, the per-line taxable figures and the
 * charges, which is all a tax calculation needs. `compute()` is the purchase order's adapter onto
 * it and `VendorBillController` builds the same rows off a bill. Typing the rule to one document
 * would mean a second copy for the other, which is how two documents come to disagree about the
 * same tax.
 *
 * ## The ruling this implements
 *
 * The owner, 2026-09-11: *"taxes is shared with sales since tax rules are exactly same based on tax
 * classes — so we want to pick up those bundles too."* So nothing here invents a buy-side tax
 * model. It calls `TaxBundle\Tax\TaxCalculatorResolver` — the same resolver the sell side calls,
 * finding the same `app.tax_calculator`-tagged bundles (`TaxBCBundle`, `TaxCanadaSimpleBundle`),
 * each still gated by its own Active/Inactive switch — with the same `App\Contract\Tax\TaxContext`,
 * and gets back the same `App\Contract\Tax\TaxLine`s.
 *
 * That replaces `purchase_order.tax` as something an admin TYPED. The column survives as the
 * derived sum of the lines below, which is exactly what `sales_order.tax` is.
 *
 * `PurchaseOrder::recalculateTotals()` used to carry the opposite decision in its docblock — *"a
 * purchase tax is a figure off the vendor's paperwork, not something this app calculates"* — and
 * that reasoning is now overruled, not forgotten: an admin who has a figure off the vendor's
 * paperwork types it as a manual tax charge row, which is a `TaxLine` of `SOURCE_MANUAL` sitting
 * beside the calculated ones rather than replacing them.
 *
 * ## `TaxContext` needed no change, and that is worth stating
 *
 * `new TaxContext($province, $subtotal, $taxClass, $companyId = null)` is a plain value object with
 * no document in it. Two of its four arguments are the ones a purchase has trivially; the third is
 * the shared 'E'/'G'/'S' class; and `$companyId` — the sell side's CUSTOMER, read only by
 * `TaxBCBundle` to find a PST exemption number — is correctly null on a purchase, because there is
 * no customer. It already defaults to null. Nothing in `src/Contract/Tax/` was touched.
 *
 * ## What is NOT reused, and why
 *
 * `OrderTaxBreakdownService::computeBreakdown()` does nearly this job and is document-agnostic in
 * everything but one parameter: it takes `App\Contract\Fee\FeeLine[]` for the charge half. Since
 * the same ruling made purchase FEES separate from sales fees, building sell-side `FeeLine`s here
 * purely as transport would blur the boundary the ruling drew. So the merge loop below is this
 * bundle's, and only the two genuinely shape-level methods — `toJson()` and
 * `tryDecodeTaxLinesJson()` — are called on that service, so that `purchase_order.tax_lines` and
 * `sales_order.tax_lines` hold byte-compatible JSON by construction rather than by two copies of a
 * format agreeing today.
 *
 * ## Rounding: once at the line, then sum the rounded lines
 *
 * The buy side's rule, and deliberately NOT the sell side's. `App\Service\SalesDocumentMoney`
 * carries sell-side intermediates at eight decimal places and rounds once at the grand total;
 * `AbstractPurchaseDocument` has summed whole cents since #555 and `PurchaseOrder::recalculateTotals()`
 * sums already-rounded line subtotals. Carrying six decimals into the total here would produce a
 * purchase order whose Total Tax did not equal the sum of the tax rows printed above it, which is
 * the one thing a buyer checks a vendor's paperwork against. The two flows disagreeing about the
 * rounding point is a known, filed thing (#258) — this side is not changed to match the other.
 */
final class PurchaseTaxBreakdown
{
    /** The per-line label a line with no taxable figure carries. */
    public const LABEL_NO_TAX = 'No tax';

    public function __construct(
        private readonly TaxCalculatorResolver $taxResolver,
        private readonly OrderTaxBreakdownService $snapshotFormat,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The whole breakdown for a document mid-save, whose lines and charges have just been rebuilt.
     *
     * @param PurchaseChargeLine[] $chargeLines
     * @param TaxLine[]            $manualLines  admin-typed tax rows, appended rather than merged
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}
     */
    public function compute(PurchaseOrder $order, array $chargeLines, array $manualLines = []): array
    {
        // Read in the document's own row order rather than the collection's: perLineTax is keyed by
        // POSITION and is frozen into tax_lines for the detail and print pages to render against,
        // and mid-save the collection still holds a freshly added line at the end. The same reason
        // OrderTaxBreakdownService sorts before it reads.
        $lines = $order->getLines()->toArray();
        usort($lines, static fn (PurchaseOrderLine $a, PurchaseOrderLine $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return $this->computeRows(
            (string) ($order->getTaxProvince() ?? ''),
            array_map(
                static fn (PurchaseOrderLine $line): array => [
                    'subtotal' => (float) $line->getSubtotal(),
                    'taxClass' => $line->getTaxCode(),
                ],
                array_values($lines),
            ),
            $chargeLines,
            $manualLines,
        );
    }

    /**
     * The same breakdown, for a document this class does not name.
     *
     * A vendor bill is the other purchase document and its lines are not `PurchaseOrderLine`s, so
     * the rule takes rows: `{subtotal, taxClass}` in the document's own row order. `compute()`
     * above is the purchase order's adapter onto this, and `VendorBillController` builds the same
     * rows off a bill — one rule, two callers, rather than two copies that drift.
     *
     * @param list<array{subtotal: float, taxClass: ?string}> $lines       in the document's own row order
     * @param PurchaseChargeLine[]                            $chargeLines
     * @param TaxLine[]                                       $manualLines admin-typed rows, appended rather than merged
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}
     */
    public function computeRows(string $province, array $lines, array $chargeLines, array $manualLines = []): array
    {
        /** @var array<string, TaxLine> $merged */
        $merged = [];
        $perLineTax = [];
        $perLineTaxLabel = [];

        foreach (array_values($lines) as $index => $line) {
            $lineTaxLines = $this->safeCalculate(new TaxContext(
                $province,
                (float) ($line['subtotal'] ?? 0.0),
                TaxContext::mapTaxCode($line['taxClass'] ?? null),
            ));

            $perLineTax[$index] = round($this->mergeInto($merged, $lineTaxLines), 2);
            $perLineTaxLabel[$index] = $lineTaxLines === []
                ? self::LABEL_NO_TAX
                : implode('+', array_map(static fn (TaxLine $l): string => $l->label, $lineTaxLines));
        }

        // Freight has no branch of its own: it is a charge row like any other, taxed by the class
        // it carries — which toFreightLines() set to the highest class across the document's goods.
        foreach ($chargeLines as $charge) {
            if (!$charge instanceof PurchaseChargeLine || !$charge->isTaxable()) {
                continue;
            }

            $this->mergeInto($merged, $this->safeCalculate(new TaxContext($province, $charge->amount, $charge->taxClass)));
        }

        $breakdown = [
            'lines' => array_values($merged),
            'total' => 0.0,
            'perLineTax' => $perLineTax,
            'perLineTaxLabel' => $perLineTaxLabel,
        ];

        // Appended, not merged, and they move the total — so the document's tax figure stays the
        // sum of its tax lines. A manual "GST adjustment" must not roll into the real GST row:
        // same label, entirely different thing.
        foreach ($manualLines as $manual) {
            if ($manual instanceof TaxLine) {
                $breakdown['lines'][] = $manual;
            }
        }

        $breakdown['total'] = self::sumOfLines($breakdown['lines']);

        return $breakdown;
    }

    /**
     * A figure quoted by the vendor, as a breakdown of exactly one manual tax line.
     *
     * For the paths that carry a vendor's own stated tax onto a purchase order without any of our
     * calculators having a view — today that is `RfqConversionService`, which copies an accepted
     * quote's tax across verbatim.
     *
     * It has to be a LINE and not just a figure, because since #655 `purchase_order.tax` is derived
     * from this snapshot: writing the scalar alone would have `recalculateTotals()` read an empty
     * snapshot and quietly zero a tax the vendor actually quoted. Carrying it as a manual line
     * keeps the number, and improves on what it replaced — the figure now prints as its own row,
     * marked as quoted rather than calculated.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}
     */
    public function quotedTaxBreakdown(?string $amount, string $label = 'Tax (as quoted)'): array
    {
        $value = round((float) $amount, 2);

        return [
            'lines' => $value === 0.0 ? [] : [new TaxLine($label, null, $value, 'quoted-tax', TaxLine::SOURCE_MANUAL)],
            'total' => $value,
            'perLineTax' => [],
            'perLineTaxLabel' => [],
        ];
    }

    /**
     * The frozen snapshot, or null when nothing has been computed yet.
     *
     * No live-recompute fallback, deliberately. `OrderTaxBreakdownService::breakdownForOrder()` has
     * one because sales documents predate its snapshot column; `purchase_order.tax_lines` arrives
     * with this change, so every purchase order that has one was saved by code that wrote it, and a
     * document without one genuinely has no tax — recomputing behind the reader's back would make
     * a printed PO disagree with its own stored total.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}|null
     */
    public function frozen(PurchaseOrder $order): ?array
    {
        return $this->frozenFromJson($order->getTaxLines());
    }

    /**
     * The same, for a document this class does not name — the bill hands its own column across.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}|null
     */
    public function frozenFromJson(?string $json): ?array
    {
        return $this->snapshotFormat->tryDecodeTaxLinesJson($json);
    }

    /**
     * A tax row an admin typed off the vendor's paperwork, or null when they typed nothing.
     *
     * Rate null, source MANUAL: there is no percentage behind it and nothing to recompute it from —
     * which is exactly what `TaxLine` documents those two fields to mean. A bill states the tax the
     * vendor charged, and that is a fact about their document rather than a calculation of ours.
     */
    public function manualLine(string $label, string $amount): ?TaxLine
    {
        $cents = (int) round((float) $amount * 100);
        if ($cents === 0) {
            return null;
        }

        $label = trim($label) !== '' ? trim($label) : 'Tax charged by the vendor';

        return new TaxLine(
            $label,
            null,
            $cents / 100,
            self::sluggify($label) ?: 'manual-tax',
            TaxLine::SOURCE_MANUAL,
        );
    }

    /** Whole cents, so the stored column agrees with the sum of the rows it is printed beside. */
    public static function totalCents(array $breakdown): int
    {
        return (int) round(((float) ($breakdown['total'] ?? 0.0)) * 100);
    }

    /**
     * @param array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>} $breakdown
     */
    public function toJson(array $breakdown): string
    {
        return $this->snapshotFormat->toJson($breakdown);
    }

    /**
     * Turns the form's `type=tax` charge rows into manual tax lines.
     *
     * Such a row is an amount an admin read off the vendor's paperwork: no rate, no class, no
     * calculator behind it. So it gets a null rate and SOURCE_MANUAL rather than being disguised as
     * a calculated line. Byte-identical in intent to
     * `OrderTaxBreakdownService::manualTaxLinesFromCharges()`; it is here because that method reads
     * the SALES charge vocabulary's type constant and this one reads the purchase vocabulary's.
     *
     * @param list<array{label: string, amount: float, type: string, slug?: string}> $charges
     * @return TaxLine[]
     */
    public function manualTaxLinesFromCharges(array $charges): array
    {
        $lines = [];
        foreach ($charges as $row) {
            if (($row['type'] ?? '') !== PurchaseDocumentChargeLines::TYPE_TAX) {
                continue;
            }

            $label = (string) ($row['label'] ?? '');
            $slug = self::sluggify((string) ($row['slug'] ?? '')) ?: self::sluggify($label);
            $lines[] = new TaxLine(
                $label,
                null,
                (float) ($row['amount'] ?? 0),
                $slug !== '' ? $slug : 'tax-adjustment',
                TaxLine::SOURCE_MANUAL,
            );
        }

        return $lines;
    }

    /**
     * Read-path counterpart: a saved document's manual tax lines as charge rows, so the form can
     * hand them back and the next save does not silently drop them.
     *
     * @return list<array{label: string, amount: float, type: string, slug: string}>
     */
    public function manualTaxChargeRows(PurchaseOrder $order): array
    {
        return $this->manualTaxChargeRowsFrom($this->frozen($order));
    }

    /**
     * The same read-path rows as manualTaxChargeRows(), for a document this class does not name.
     *
     * VendorBill has no `frozen(PurchaseOrder)`-shaped reader of its own — its frozen breakdown
     * comes back through frozenFromJson($bill->getTaxLines()) instead — so this takes the already-
     * unpacked breakdown array either of them produces, rather than a second copy of the loop below
     * keyed to a different entity type. `compute()`/`computeRows()` already split the same way.
     *
     * @param array{lines: TaxLine[], ...}|null $breakdown
     *
     * @return list<array{label: string, amount: float, type: string, slug: string}>
     */
    public function manualTaxChargeRowsFrom(?array $breakdown): array
    {
        $rows = [];
        foreach ($breakdown['lines'] ?? [] as $line) {
            if (!$line instanceof TaxLine || $line->source !== TaxLine::SOURCE_MANUAL) {
                continue;
            }

            $rows[] = [
                'label' => $line->label,
                'amount' => $line->amount,
                'type' => PurchaseDocumentChargeLines::TYPE_TAX,
                'slug' => $line->slug ?? '',
            ];
        }

        return $rows;
    }

    /**
     * The sum of a set of tax lines, in whole cents.
     *
     * Cents rather than a float sum so that the Total Tax printed on the document is exactly the
     * sum of the tax rows printed above it — see the rounding note on the class.
     *
     * @param TaxLine[] $lines
     */
    public static function sumOfLines(array $lines): float
    {
        $cents = 0;
        foreach ($lines as $line) {
            $cents += (int) round($line->amount * 100);
        }

        return $cents / 100;
    }

    /**
     * Merges calculated lines into the running set and returns what they came to.
     *
     * One "GST" row per document rather than one per purchase line is the point of merging, so the
     * key is the calculator's SLUG — stable — rather than the label, which is display text.
     *
     * @param array<string, TaxLine> $merged
     * @param TaxLine[]              $taxLines
     */
    private function mergeInto(array &$merged, array $taxLines): float
    {
        $sum = 0.0;
        foreach ($taxLines as $taxLine) {
            $sum += $taxLine->amount;
            $key = ($taxLine->slug ?? '') !== '' ? 'slug:' . $taxLine->slug : 'label:' . $taxLine->label;
            $existing = $merged[$key] ?? null;
            $merged[$key] = $existing === null
                ? $taxLine
                : new TaxLine(
                    $existing->label,
                    $existing->rate,
                    round($existing->amount + $taxLine->amount, 2),
                    $existing->slug,
                    $existing->source,
                );
        }

        return $sum;
    }

    /**
     * @return TaxLine[]
     */
    private function safeCalculate(TaxContext $context): array
    {
        // Nothing to tax only when there is nothing there. A NEGATIVE amount is taxed like any
        // other — a credit's tax is a credit — which is the call the sell side makes and the one a
        // buy-side credit line will need.
        if ($context->subtotal === 0.0) {
            return [];
        }

        try {
            return $this->taxResolver->calculate($context);
        } catch (TaxJurisdictionNotCovered $e) {
            // Item 66, and this is the side the item was found on: a warehouse with a complete,
            // valid US address passes every province gate and then no calculator claims the state.
            // Still $0 and still saveable — see the exception's docblock for why a refusal would be
            // wrong when the address is correct and the remedy does not exist — but the document
            // now SAYS so instead of printing a zero nobody can tell from a real one.
            //
            // Exempt goods are excluded, exactly as PurchaseOrder::assertTaxProvinceKnownIfTaxable()
            // lets an exempt order through: there is no rate to get wrong.
            if ($context->taxClass === 'E') {
                return [];
            }

            // The SAME line the sell side states, from the one place that builds it, so a purchase
            // order and an invoice cannot end up wording the same fact two ways — INCLUDING the
            // case where there is no fact to word. A document with no tax province at all gets no
            // notice row: "No tax rule for " names nothing, and it was frozen into `tax_lines`, so
            // it reached the detail screens and the print output and stayed there. That state is
            // already stated correctly by `#po-total-tax-empty` / `#bill-total-tax-empty`, which
            // since 614cb0c5 render off the document's own frozen `taxProvince` rather than off an
            // empty tax list — so they still appear here, and appear beside tax rows when there are
            // any. No log line either: a draft that has not named a warehouse yet is an ordinary
            // state of a draft, not an incident.
            $notice = OrderTaxBreakdownService::notCoveredLine($context->province);
            if ($notice === null) {
                return [];
            }

            $this->logger->warning('No installed tax calculator covers this jurisdiction; stating it on the document.', [
                'province' => $context->province,
                'exception' => $e->getMessage(),
            ]);

            return [$notice];
        } catch (\RuntimeException $e) {
            // A calculator that is installed and fell over. Logged and treated as $0, never fatal:
            // a purchase order must stay saveable in an instance that has no tax bundle switched on.
            $this->logger->warning('Purchase tax calculation failed, treating as $0 tax.', [
                'province' => $context->province,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private static function sluggify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }
}
