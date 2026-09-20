<?php

declare(strict_types=1);

namespace ProcurementBundle\Tax;

use App\Service\AppSettings;
use App\Service\OrderTaxBreakdownService;

/**
 * Tax on a buy-side document, computed by the SELL side's calculators.
 *
 * ## Why this is thirty lines and not a bundle
 *
 * Tax is shared between the two sides of this application by ruling, not by coincidence: the rates
 * live in `src/Contract/Tax/` and in the Tax* bundles that implement `TaxCalculatorInterface`, and a
 * buy-side tax model would be a second place holding the same fact — the shape behind #589, #590
 * and #591 every time it has appeared here. So this is a seam and not a model: it answers "which
 * province" and "which lines", and `App\Service\OrderTaxBreakdownService` — the same service the
 * admin order screens, the invoice PDFs and customer checkout already go through — does the rest.
 *
 * Nothing in this class knows a rate.
 *
 * ## Which province, and why it is ours and not the vendor's
 *
 * A sales document is taxed where the CUSTOMER takes delivery, so it reads the province off the
 * document's shipping address. A purchase document is the same rule seen from the other end: the
 * vendor charges us tax on delivery to US. There is no address on `warehouse` to read (it is a name
 * and a status), so the province comes from Settings → Company Information — `company_state`, the
 * same setting the purchase order print already puts in its "Deliver To" box.
 *
 * An unset `company_state` resolves to no province, no calculator supports it, and
 * `OrderTaxBreakdownService` treats that as $0.00 tax and logs it. That is the honest answer: a
 * database that has never said where this company is cannot be told what it owes in tax.
 *
 * ## Fees are absent on purpose
 *
 * `computeBreakdown()`'s fee argument is always `[]` here. Purchase fees are NOT shared with the
 * sell side — freight, brokerage and duty on a vendor's quote are separate new logic nobody has
 * built yet — and passing sell-side `FeeLine`s through would tax charges this document does not
 * have. When purchase fees arrive they attach here, as one more argument, and the calculators stay
 * untouched.
 */
final class PurchaseDocumentTax
{
    public function __construct(
        private readonly OrderTaxBreakdownService $breakdown,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * The tax breakdown for a purchase document's lines.
     *
     * `subtotal` is nullable and the null is not a zero: null means "this line has no price yet",
     * which on a vendor quote is the ordinary state of a half-recorded reply, and the breakdown
     * reports TBD for it rather than asserting it is tax-free. A real 0.00 is taxed as 0.00 and
     * reported as "No tax", which for it is true.
     *
     * @param array<int, array{subtotal: ?float, taxCode: ?string}> $lines keyed by row index
     *
     * @return array{lines: \App\Contract\Tax\TaxLine[], total: float, perLineTax: array<int, ?float>, perLineTaxLabel: array<int, string>}
     */
    public function forLines(array $lines): array
    {
        return $this->breakdown->computeBreakdown($this->buyerProvince(), null, $lines, []);
    }

    /** Where this company is, as Settings → Company Information states it. Empty when nobody has said. */
    public function buyerProvince(): string
    {
        return trim((string) $this->settings->get('company_state', ''));
    }
}
