<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Repository\BundleStatusRepository;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseFeeCalculatorInterface;
use ProcurementBundle\Contract\Purchase\PurchaseFeeContext;

/**
 * Every registered purchase fee calculator, asked in turn (#655, #658).
 *
 * Modelled on `TaxBundle\Tax\TaxCalculatorResolver` and `FeeBundle\Fee\FeeCalculatorResolver`: a
 * `!tagged_iterator` of calculators, each gated by its owning bundle's Active/Inactive switch
 * through `BundleStatusRepository::isActiveForInstance()`, which derives the bundle from the
 * service's own root namespace.
 *
 * ## Two deliberate differences from the tax resolver
 *
 *  - **Every supporting calculator contributes; there is no first-match-wins.** Tax returns on the
 *    first supporting calculator because a province has exactly one tax regime and two answering at
 *    once would double the tax. Charges stack: freight and brokerage and a fuel surcharge are three
 *    charges on one document, each its own row. That is how `FeeCalculatorResolver` behaves on the
 *    sell side, and a charge seam is a fee seam.
 *  - **Nothing supporting is not an error.** The tax resolver throws when no calculator claims a
 *    province, because an order with no tax rule is a misconfiguration. A purchase document with no
 *    automatic charges is the normal case and, today, the only case.
 *
 * A calculator that throws must not take the save down with it: a charge is an addition to a
 * document, and a broken third-party calculator must not make bills unenterable. That is the same
 * call `OrderTaxBreakdownService::safeCalculateTax()` makes on the other side. The document is then
 * short that charge — visibly, because the row simply is not on it.
 */
final class PurchaseFeeCalculatorResolver
{
    /** @param iterable<PurchaseFeeCalculatorInterface> $calculators */
    public function __construct(
        private readonly iterable $calculators,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /** @return PurchaseChargeLine[] */
    public function calculate(PurchaseFeeContext $context): array
    {
        $lines = [];
        foreach ($this->calculators as $calculator) {
            if (!$calculator instanceof PurchaseFeeCalculatorInterface) {
                continue;
            }

            if (!$this->bundleStatusRepo->isActiveForInstance($calculator)) {
                continue;
            }

            try {
                if (!$calculator->supports($context)) {
                    continue;
                }

                foreach ($calculator->calculate($context) as $line) {
                    if ($line instanceof PurchaseChargeLine) {
                        $lines[] = $line;
                    }
                }
            } catch (\RuntimeException) {
                // Deliberately swallowed — see the class docblock. A calculator that cannot price
                // itself contributes no row rather than refusing the save.
                continue;
            }
        }

        return $lines;
    }

    /** Whether anything at all is subscribed, for a screen that wants to say so. */
    public function hasCalculators(): bool
    {
        foreach ($this->calculators as $calculator) {
            if ($calculator instanceof PurchaseFeeCalculatorInterface && $this->bundleStatusRepo->isActiveForInstance($calculator)) {
                return true;
            }
        }

        return false;
    }
}
