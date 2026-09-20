<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\RegionSeedData;

/**
 * The document's province is a real, recognised jurisdiction, and no installed tax calculator
 * claims it (queue item 66).
 *
 * ## Why this is its own type
 *
 * `TaxBundle\Tax\TaxCalculatorResolver::calculate()` has always thrown when nothing supported a
 * province, and both breakdown services have always caught it, logged a warning and returned no tax
 * lines — so the document printed **$0.00, indistinguishable from a genuine zero**. That was the
 * right shape for one of the two things that reach that catch and the wrong shape for the other:
 *
 *  - a calculator that is installed and fell over is an incident, and $0 with a log line is a
 *    defensible way to keep a document saveable while somebody looks at the log;
 *  - a jurisdiction NOBODY covers is not an incident at all. It is a permanent, correct, knowable
 *    fact about this installation — the warehouse is telling the truth and the app has no rule for
 *    where it is — and the person reading the document is the one who needs to know it.
 *
 * A separate type is what lets the second case be answered differently from the first without
 * guessing from an exception message. It extends `\RuntimeException` deliberately, so every
 * existing `catch (\RuntimeException)` still catches it and nothing that used to stay saveable
 * starts throwing.
 *
 * ## What the app does about it, and why not a refusal
 *
 * The document is still produced, and the breakdown carries one zero-amount line SAYING no rule
 * applies — see `App\Service\OrderTaxBreakdownService::notCoveredLine()`. Refusing instead was
 * considered and is wrong here, for two reasons:
 *
 *  1. **There is no remedy to name.** Item 61's refusals — `PurchaseOrder`'s and `VendorBill`'s —
 *     refuse a province that is MISSING, and the message can say "record the warehouse's address".
 *     Here the address is complete and correct, and the only fix is installing a calculator that
 *     does not exist. A refusal whose remedy the operator cannot perform makes an honestly
 *     addressed warehouse unusable.
 *  2. **Zero may be the right answer.** Delaware, Montana, New Hampshire and Oregon levy no state
 *     sales tax. Refusing a document whose correct tax is zero would be a false refusal. What was
 *     wrong was never the figure; it was that the figure said nothing about where it came from.
 *
 * So the zero is charged deliberately and the document says so, which is the honest reading of a
 * genuine zero and the thing item 37 exists to demand.
 */
final class TaxJurisdictionNotCovered extends \RuntimeException
{
    private function __construct(public readonly string $province, string $message)
    {
        parent::__construct($message);
    }

    public static function forProvince(string $province): self
    {
        return new self($province, sprintf(
            'No active tax calculator claims %s. The province is recognised; no installed tax bundle covers it.',
            self::describe($province),
        ));
    }

    /**
     * How the jurisdiction is named to a person: "California (United States)", never a bare code.
     *
     * A bare code is exactly the trap that makes this defect class hard to read — `CA` is both the
     * country code for Canada and the state code for California, and
     * `RegionSeedData::resolveProvinceAnyCountry('CA')` resolves it to California. A message saying
     * "no calculator for CA" beside a Canadian-tax application is the most misleading sentence this
     * could produce, so the country is always spelled out with the name.
     */
    public static function describe(string $province): string
    {
        foreach (RegionSeedData::PROVINCES as $countryCode => $provinces) {
            if (isset($provinces[$province])) {
                return sprintf(
                    '%s (%s)',
                    $provinces[$province],
                    RegionSeedData::COUNTRIES[$countryCode] ?? $countryCode,
                );
            }
        }

        return $province;
    }
}
