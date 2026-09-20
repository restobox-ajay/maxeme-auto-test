<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Fee\FeeLine;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\AbstractSalesDocument;
use App\Entity\DocumentLine;
use App\Exception\TaxJurisdictionNotCovered;
use Psr\Log\LoggerInterface;
use TaxBundle\Tax\TaxCalculatorResolver;

/**
 * Computes the full order tax breakdown: per order-line tax plus tax on every non-exempt fee row —
 * shipping among them, since shipping is rows now — all merged by label into a single breakdown for
 * display. Shared between admin order screens, the admin/customer invoice PDFs, and customer
 * checkout, so the tax logic lives in exactly one place.
 */
final class OrderTaxBreakdownService
{
    public function __construct(
        private readonly TaxCalculatorResolver $taxResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /** The per-line tax label an unpriced line carries — see the null branch in computeBreakdown(). */
    public const LABEL_UNPRICED = 'TBD';

    /**
     * A line's subtotal is nullable, and the null is not a zero: null means "not priced yet"
     * (quotes may be published half-priced), while 0.0 means a real, genuinely free line. They get
     * different labels, so the null has to survive this far rather than being flattened by the
     * caller.
     *
     * @param array<int, array{subtotal: ?float, taxCode: ?string}> $taxableLines keyed by line index
     * @param FeeLine[] $feeLines
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}
     */
    public function computeBreakdown(
        string $province,
        ?int $companyId,
        array $taxableLines,
        array $feeLines,
    ): array {
        /** @var array<string, TaxLine> $merged */
        $merged = [];
        $perLineTax = [];
        $perLineTaxLabel = [];
        $perFeeLineTax = [];

        $addLines = function (array $taxLines) use (&$merged): float {
            $sum = 0.0;
            foreach ($taxLines as $taxLine) {
                $sum += $taxLine->amount;
                // A manual adjustment is a row an admin typed and expects back exactly as typed,
                // so it stands on its own — it must not roll into a calculated line that happens
                // to share its label, nor into a second adjustment worded the same way.
                if ($taxLine->source === TaxLine::SOURCE_MANUAL) {
                    $merged[] = $taxLine;
                    continue;
                }

                $key = self::mergeKey($taxLine);
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
        };

        foreach ($taxableLines as $index => $line) {
            // An unpriced line has no tax to state, and saying "No tax" about it would assert
            // something false: that the item is non-taxable, next to a Price column reading TBD.
            // It is excluded from the breakdown entirely — there is no amount to merge — and its
            // own figures read TBD until it is priced (#255). A real $0.00 line falls through to
            // the calculation below and still reports "No tax", which for it is true.
            if ($line['subtotal'] === null) {
                $perLineTax[$index] = null;
                $perLineTaxLabel[$index] = self::LABEL_UNPRICED;
                continue;
            }

            $taxClass = TaxContext::mapTaxCode($line['taxCode']);
            $context = new TaxContext($province, $line['subtotal'], $taxClass, $companyId);
            $lineTaxLines = $this->safeCalculateTax($context);
            $perLineTax[$index] = round($addLines($lineTaxLines), 2);
            $perLineTaxLabel[$index] = $lineTaxLines === []
                ? 'No tax'
                : implode('+', array_map(static fn (TaxLine $l) => $l->label, $lineTaxLines));
        }

        // Shipping has no branch of its own any more: it is rows in $feeLines, taxed by the tax
        // class each row carries, which SalesDocumentChargeLines::toShippingLines() sets to the
        // highest class across the document's goods — the figure the retired shipping-only branch
        // computed for itself.
        foreach ($feeLines as $feeIndex => $feeLine) {
            // Negative fee lines are taxed, not skipped: a coupon is carried as a negative fee
            // (see FeeCouponBundle), and a discount that did not reduce tax would leave the
            // customer paying tax on money they were never charged. A zero line is skipped only
            // because it cannot produce a tax amount either way.
            if ($feeLine->taxClass === 'E' || $feeLine->amount === 0.0) {
                // Stated as 0.0 rather than left absent, so a caller reading perFeeLineTax[$feeIndex]
                // for the fee row it renders never has to fall back to a default for an exempt or
                // zero fee — every index this method saw a fee at gets an entry.
                $perFeeLineTax[$feeIndex] = 0.0;
                continue;
            }
            $feeContext = new TaxContext($province, $feeLine->amount, $feeLine->taxClass, $companyId);
            $feeTaxLines = $this->safeCalculateTax($feeContext);
            // This fee's OWN tax, in isolation — what a fee row states beside it (#667), same shape
            // as $perLineTax above for a product line. $addLines() below still runs on the same
            // $feeTaxLines to fold it into the document-wide merged total; the two reads are
            // independent uses of one calculation; the fee never appears in $perLineTax itself.
            $perFeeLineTax[$feeIndex] = round(array_sum(array_map(
                static fn (TaxLine $l): float => $l->amount,
                $feeTaxLines,
            )), 2);
            $addLines($feeTaxLines);
        }

        return [
            // The breakdown's own lines are figures the admin reads, and every calculator already
            // produces them to the cent — but their SUM is an intermediate on the way to the
            // document's grand total, so it is carried at SalesDocumentMoney::SCALE rather than
            // rounded a second time here (#257). In practice that changes nothing while the
            // calculators round their own lines; it stops this being a rounding point if one ever
            // stops.
            'lines' => array_values($merged),
            'total' => SalesDocumentMoney::intermediate((float) array_sum(array_map(fn(TaxLine $l) => $l->amount, $merged))),
            'perLineTax' => $perLineTax,
            'perLineTaxLabel' => $perLineTaxLabel,
            'perFeeLineTax' => $perFeeLineTax,
        ];
    }

    /**
     * One "GST" row per document, not one per order line, is the whole point of merging — so the
     * key is the calculator's slug, which is stable, rather than the label, which is display text.
     * Lines frozen before slugs reached TaxLine still key on label, which is how they were merged
     * when they were written.
     */
    private static function mergeKey(TaxLine $line): string
    {
        return ($line->slug ?? '') !== '' ? 'slug:' . $line->slug : 'label:' . $line->label;
    }

    /**
     * Folds the admin's ad-hoc tax rows into a computed breakdown. They are appended rather than
     * merged, and they move the total, so the document's tax figure is still the sum of its tax
     * lines — before this they were a number added to the tax total with no line behind it, which
     * is why they could never be reported on.
     *
     * perLineTax/perLineTaxLabel are untouched: an adjustment the admin typed against the whole
     * document does not belong to any one product line.
     *
     * @param array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>} $breakdown
     * @param TaxLine[] $manualLines
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}
     */
    public function withManualTaxLines(array $breakdown, array $manualLines): array
    {
        foreach ($manualLines as $line) {
            $breakdown['lines'][] = $line;
            $breakdown['total'] = SalesDocumentMoney::intermediate($breakdown['total'] + $line->amount);
        }

        return $breakdown;
    }

    /**
     * Turns the order/estimate form's type=tax charge rows into tax lines. Such a row is an amount
     * an admin typed with no rate, no tax class and no calculator behind it, so it gets no rate and
     * SOURCE_MANUAL rather than being disguised as a calculated one.
     *
     * The slug is whatever the admin typed, else the label sluggified, because reporting wants a
     * key that survives a relabel. Two rows are free to end up with the same slug — nothing keys
     * on uniqueness, and which rows an admin wants on their document is their business.
     *
     * The filter names the type it wants rather than skipping the one it doesn't. Reading "anything
     * that is not shipping" as tax was correct only while shipping and tax were the whole
     * vocabulary; the moment a third kind existed it silently turned every fee row into a tax line
     * — wrong bucket, wrong tax treatment, wrong totals, and no error to notice it by. A fourth
     * kind must not be able to do that again.
     *
     * @param list<array{label: string, amount: float, type: string, slug?: string}> $charges
     * @return TaxLine[]
     */
    public function manualTaxLinesFromCharges(array $charges): array
    {
        $lines = [];
        foreach ($charges as $row) {
            if (($row['type'] ?? '') !== SalesDocumentChargeLines::TYPE_TAX) {
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
     * Read-path counterpart of manualTaxLinesFromCharges(): the admin forms edit these adjustments
     * as charge rows, so a saved document's manual tax lines have to come back as rows or the next
     * save would silently drop them.
     *
     * @return list<array{label: string, amount: float, type: string, slug: string}>
     */
    public function manualTaxChargeRows(?string $taxLinesJson): array
    {
        $rows = [];
        foreach ($this->tryDecodeTaxLinesJson($taxLinesJson)['lines'] ?? [] as $line) {
            if ($line->source !== TaxLine::SOURCE_MANUAL) {
                continue;
            }
            $rows[] = ['label' => $line->label, 'amount' => $line->amount, 'type' => SalesDocumentChargeLines::TYPE_TAX, 'slug' => $line->slug ?? ''];
        }

        return $rows;
    }

    private static function sluggify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }

    /**
     * Recomputes the tax breakdown for a saved document (read-only display on admin/customer
     * detail, edit, and invoice pages) from what it already has stored — its lines and its frozen
     * fee rows, shipping included.
     *
     * Widened from SalesOrder because nothing here was ever about orders: it unpacks a document
     * into computeBreakdown()'s arguments, which is the same job an estimate or a cart needs doing
     * and could not reach.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}
     */
    public function buildForOrder(AbstractSalesDocument $document): array
    {
        return $this->computeBreakdownFor($document, $document->getFeeLineRows());
    }

    /**
     * The same unpacking, for a document mid-save whose shipping and fee lines have just been
     * recalculated and are not on it yet. Every caller that used to build a `taxableLines` array
     * beside a `cartItems` array is one of these.
     *
     * The line figures come off the rows as stored — rounded to the cent — rather than from the
     * caller's running float, so an in-flight breakdown agrees with the one buildForOrder()
     * recomputes from the same document a moment later.
     *
     * Shipping rides in $feeLines with everything else — it is rows now, not a scalar beside them.
     *
     * @param FeeLine[] $feeLines
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}
     */
    public function computeBreakdownFor(AbstractSalesDocument $document, array $feeLines): array
    {
        // Read in the document's own row order, not the collection's. perLineTax is keyed by
        // POSITION and is snapshotted into tax_lines for the detail page and the quote PDF to
        // render against — but mid-save the collection still holds a freshly added line at the end,
        // wherever the admin actually put it, so an unsorted read would freeze a Tax $ figure
        // against the wrong product. Sorting is stable, so a document whose rows all answer the
        // same sort order (a cart) is left exactly as it was.
        $lines = $document->getLines()->toArray();
        usort($lines, static fn (DocumentLine $a, DocumentLine $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        $taxableLines = [];
        foreach ($lines as $line) {
            // Cast only what is there: a null subtotal is "not priced yet" and stays null all the
            // way into computeBreakdown(), which is what tells a TBD line apart from a free one.
            $subtotal = $line->getSubtotal();
            $taxableLines[] = [
                'subtotal' => $subtotal === null ? null : (float) $subtotal,
                'taxCode' => $line->getTaxCode(),
            ];
        }

        return $this->computeBreakdown(
            $document->getProvince(),
            // Live company lookup, not a frozen one: PST # now lives in TaxBCBundle's own
            // custom field (#124), not on CompanyIdentity.
            $document->getCompany()?->getId(),
            $taxableLines,
            $feeLines,
        );
    }

    /**
     * Serializes a computeBreakdown()/buildForOrder() result for SalesOrder::$taxLines.
     * Called at the same save points that freeze fee_lines, so the itemized tax
     * breakdown is a snapshot of the rates in effect at save time.
     *
     * @param array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>} $breakdown
     */
    public function toJson(array $breakdown): string
    {
        return json_encode([
            'lines' => array_map(
                static fn (TaxLine $l) => [
                    'label' => $l->label,
                    'rate' => $l->rate,
                    'amount' => $l->amount,
                    'slug' => $l->slug,
                    'source' => $l->source,
                ],
                $breakdown['lines'],
            ),
            'total' => $breakdown['total'],
            'perLineTax' => $breakdown['perLineTax'],
            'perLineTaxLabel' => $breakdown['perLineTaxLabel'],
            // Absent rather than empty on a purchase document: PurchaseTaxBreakdown builds its own
            // breakdown array and has no per-fee tax to report, so it never sets the key. Defended
            // here the same way the read path at fromJson() defends it, because a caller that
            // computed no per-fee tax is a caller with none — not a caller that is broken.
            'perFeeLineTax' => $breakdown['perFeeLineTax'] ?? [],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Read-path counterpart of toJson(): returns the frozen tax_lines snapshot when
     * one exists, and only recomputes live (buildForOrder) for documents saved before
     * the snapshot column existed — those grandfather into the old behavior.
     *
     * Widened from SalesOrder to any AbstractSalesDocument for #539 stage 6, for the same reason
     * buildForOrder() above was: an invoice's totals are printed by the same document template the
     * order's used to be, and it needs the same answer. The only SalesOrder-specific thing here was
     * the type hint.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}
     */
    public function breakdownForOrder(AbstractSalesDocument $document): array
    {
        return $this->tryDecodeTaxLinesJson($document->getTaxLines()) ?? $this->buildForOrder($document);
    }

    /**
     * JSON-decode counterpart shared by any AbstractSalesDocument subtype (SalesOrder,
     * Estimate, ...) that persists a tax_lines snapshot — unlike breakdownForOrder(), this
     * has no SalesOrder-specific live-recompute fallback, so callers get null (not a
     * recomputed guess) when nothing has been priced/frozen yet.
     *
     * @return array{lines: TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>, perFeeLineTax: array<int, float>}|null
     */
    public function tryDecodeTaxLinesJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $lines = [];
        foreach ((array) ($decoded['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $lines[] = new TaxLine(
                    (string) ($line['label'] ?? ''),
                    // A missing or null rate means a manual adjustment, which has no percentage to
                    // state; every calculated line has always been written with one.
                    isset($line['rate']) ? (float) $line['rate'] : null,
                    (float) ($line['amount'] ?? 0),
                    isset($line['slug']) && (string) $line['slug'] !== '' ? (string) $line['slug'] : null,
                    // Lines frozen before this field existed were all calculator-produced.
                    (string) ($line['source'] ?? TaxLine::SOURCE_AUTO_CALC),
                );
            }
        }

        return [
            'lines' => $lines,
            'total' => (float) ($decoded['total'] ?? 0),
            // floatval() would turn an unpriced line's null back into 0.0 and lose the very
            // distinction the snapshot was written to carry — see computeBreakdown() (#255).
            'perLineTax' => array_map(
                static fn (mixed $amount): ?float => $amount === null ? null : (float) $amount,
                (array) ($decoded['perLineTax'] ?? []),
            ),
            'perLineTaxLabel' => array_map('strval', (array) ($decoded['perLineTaxLabel'] ?? [])),
            // Absent on a snapshot frozen before this key existed — an empty array here reads as
            // "no fee ever had a tax figure computed" to a template, which just shows nothing extra
            // beside those fee rows rather than a wrong number (#667).
            'perFeeLineTax' => array_map('floatval', (array) ($decoded['perFeeLineTax'] ?? [])),
        ];
    }

    /** @return TaxLine[] */
    private function safeCalculateTax(TaxContext $context): array
    {
        // Nothing to tax only when there is nothing there. A NEGATIVE amount is taxed like any
        // other: it is a credit, and tax on a credit is a credit. This used to short-circuit on
        // `<= 0`, which quietly contradicted the fee loop above — a coupon is carried as a
        // negative fee line, and skipping it left the customer paying tax on money they were
        // never charged. Admin order and quote lines can now carry a negative price too
        // (issue #227), so the same rule has to reach them: a credit line's tax follows it down
        // instead of being previewed and stored as zero.
        if ($context->subtotal === 0.0) {
            return [];
        }

        try {
            return $this->taxResolver->calculate($context);
        } catch (TaxJurisdictionNotCovered $e) {
            // Item 66. No installed calculator claims this jurisdiction — the address is complete
            // and correct, and the app simply has no rule for where it is. The tax really is zero,
            // and a bare 0.00 is the one thing this must not print, because it reads exactly like
            // an exempt line or a covered province charging nothing.
            //
            // An EXEMPT line is excluded and that is a decision rather than an oversight: with a
            // tax class of 'E' there is no rate to get wrong and the answer is $0 whatever the
            // jurisdiction says, so a notice on it would be noise about a calculation that was
            // never going to run. It is the same line PurchaseOrder::assertTaxProvinceKnownIfTaxable()
            // draws for the same reason.
            if ($context->taxClass === 'E') {
                return [];
            }

            // A document with NO province at all gets neither a notice nor a log line. There is no
            // jurisdiction here to report as uncovered, so there is no incident to record and
            // nothing for a notice to name. The decision lives in notCoveredLine() — read it there
            // — so that both breakdown services take it identically rather than by two copies of a
            // guard agreeing today.
            $notice = self::notCoveredLine($context->province);
            if ($notice === null) {
                return [];
            }

            $this->logger->warning('No installed tax calculator covers this jurisdiction; stating it on the document.', [
                'province' => $context->province,
                'exception' => $e->getMessage(),
            ]);

            return [$notice];
        } catch (\RuntimeException $e) {
            // A calculator that IS installed and fell over. Still $0 with a log line, unchanged:
            // that is an incident for somebody to look at, not a statement about this document's
            // jurisdiction, and inventing one would be a guess.
            $this->logger->warning('Order tax calculation failed, treating as $0 tax.', [
                'province' => $context->province,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The line a document carries instead of a silent zero when nothing covers its jurisdiction.
     *
     * A real TaxLine rather than a flag on the breakdown, for three things it buys and a flag does
     * not:
     *
     *  - **every screen already renders it.** The breakdown's lines are listed on the order and
     *    invoice detail screens, the printed documents and the customer's copy, and each already
     *    handles a null rate — so the statement reaches all of them with no template knowing about
     *    this case at all.
     *  - **it is FROZEN onto the document.** `tax_lines` is the snapshot a document keeps, so an
     *    order raised today still says why its tax was zero after somebody installs a US calculator
     *    tomorrow. That is what makes it a statement about the document rather than about the
     *    application's current configuration.
     *  - **`perLineTaxLabel` follows for free.** That column read "No tax" for an uncovered
     *    jurisdiction, which is the same words an exempt line gets; it now names the jurisdiction
     *    instead, because the label is built from the lines returned here.
     *
     * Amount 0.00, so it adds nothing to any sum and the document's total is unchanged. Rate null,
     * because there is no rate — the same null a manual adjustment carries, and every renderer
     * already omits the percentage when it sees one. SOURCE_AUTO_CALC rather than SOURCE_MANUAL so
     * the merge keys it by slug and one document gets ONE such row however many lines it has; a
     * manual line is deliberately never merged, and would produce one notice per line.
     *
     * ## Null when there is no province, and why that narrows the notice instead of removing it
     *
     * A document with an EMPTY province gets no notice row at all. "No tax rule for BC" is a
     * statement: there is a jurisdiction and no installed calculator claims it. "No tax rule for "
     * — which is what this produced, because `TaxJurisdictionNotCovered::describe('')` has nothing
     * to name and hands back the empty string — is not a statement about anything, and it was
     * frozen into `tax_lines`, so it reached the detail screens, the print/PDF output and the
     * customer's copy and stayed there.
     *
     * A document with no province has not had its tax worked out at all, which is a different fact
     * from "worked out, and nobody covers where you are". The screens already say that one in their
     * own words — `#po-total-tax-empty` and `#bill-total-tax-empty` name the missing province and
     * the step that would supply it — and since 614cb0c5 that sentence is conditioned on the
     * document's own frozen `tax_province` rather than on the tax list being empty, so it renders
     * in this state whether or not there are tax rows beside it. Two messages for one condition,
     * one of them malformed, is worse than the one that is already correct.
     *
     * Returning null rather than a blank-labelled line puts the decision in the one place that
     * words the notice, so `PurchaseTaxBreakdown` cannot word this case differently — the same
     * reason the notice itself is built here and not in two templates.
     */
    public static function notCoveredLine(string $province): ?TaxLine
    {
        if (trim($province) === '') {
            return null;
        }

        return new TaxLine(
            sprintf('No tax rule for %s', TaxJurisdictionNotCovered::describe($province)),
            null,
            0.0,
            'tax-jurisdiction-not-covered',
        );
    }
}
