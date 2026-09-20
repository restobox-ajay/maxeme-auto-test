<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A company's current AR exposure against its credit limit (#724).
 *
 * ## What counts as "outstanding"
 *
 * Every invoice belonging to the company for which `Invoice::isFullyPaid()` is false — the same
 * predicate `SalesOrderStatusDeriver` already asks when deciding whether an order's invoices have
 * settled it, so "is this still owed" is answered identically everywhere it is asked. That predicate
 * is `paymentCoversTotal() === false`, EXCEPT a Cancelled invoice, which is always settled — it is
 * owed nothing and never will be, whatever its payments say (see `Invoice::isFullyPaid()`'s own
 * docblock).
 *
 * A Draft is additionally excluded, on top of `isFullyPaid()`. Per `Invoice::isDraft()`'s own
 * docblock a draft is "written but not issued — sent to nobody, holding nothing, counting for
 * nothing" — the same reasoning `AdminOrderStockValidator` uses to never measure a Draft order
 * against the shelf. A figure nobody has been billed for yet is not a receivable.
 *
 * ## What is summed
 *
 * `Invoice::getBalance()` — total minus amount paid — for every invoice that survives the filter
 * above. Summed in whole cents and formatted back, the same way `Invoice::getAmountPaid()` itself
 * sums payment rows, so a company with many small invoices does not accumulate binary-float drift.
 *
 * ## What this is NOT
 *
 * Not a query. Every invoice for the company is loaded and filtered in PHP, matching how
 * `CompanyController::detail()` already loads a company's whole document list for its tabs — the
 * same unbounded-but-consistent tradeoff, not a new one. A company with genuinely many invoices
 * deserves a real aggregate query; nothing about this class rules one out later.
 */
final class CompanyCreditExposureCalculator
{
    /** Outstanding invoice balances for $company, summed, as a decimal string. */
    public function exposureFor(Company $company, EntityManagerInterface $entityManager): string
    {
        $cents = 0;

        foreach ($this->outstandingInvoices($company, $entityManager) as $invoice) {
            $cents += self::cents($invoice->getBalance());
        }

        return self::money($cents);
    }

    /**
     * What this company still owes, ONE addition wide of a company's current exposure.
     *
     * `null` when the company has no credit limit set (unlimited, per `Company::getCreditLimit()`'s
     * own docblock) or when the added amount does not push exposure past it — the ordinary case,
     * and the one that costs a caller nothing to check.
     */
    public function overageFor(Company $company, string $additionalAmount, EntityManagerInterface $entityManager): ?CreditExposure
    {
        $rawLimit = $company->getCreditLimit();
        if ($rawLimit === null) {
            return null;
        }

        // Re-formatted rather than trusted as typed: SQLite's NUMERIC affinity silently collapses a
        // whole-dollar decimal like "500.00" to the integer 500 in storage, and Doctrine's decimal
        // type does not re-pad it on the way back out (DecimalType::convertToPHPValue() is a bare
        // (string) cast) — so a limit read back after any entity refresh can lose its trailing
        // zeros. Normalizing through the same cents/money round-trip every other figure here uses
        // keeps this one typed identically to $exposure and $projected regardless of what the
        // driver handed back.
        $limit = self::money(self::cents($rawLimit));

        $exposure = $this->exposureFor($company, $entityManager);
        $projected = self::money(self::cents($exposure) + self::cents($additionalAmount));

        if (self::cents($projected) <= self::cents($limit)) {
            return null;
        }

        return new CreditExposure($limit, $exposure, $additionalAmount, $projected);
    }

    /** @return list<Invoice> */
    private function outstandingInvoices(Company $company, EntityManagerInterface $entityManager): array
    {
        $invoices = $entityManager->getRepository(Invoice::class)->findBy(['company' => $company]);

        return array_values(array_filter(
            $invoices,
            static fn (Invoice $invoice): bool => !$invoice->isDraft() && !$invoice->isFullyPaid(),
        ));
    }

    private static function cents(?string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
