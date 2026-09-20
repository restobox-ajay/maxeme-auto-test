<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Service;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\FeeRepository;
use App\Repository\ProductFeeRepository;
use App\Service\ProductImport\ProductImportResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backs the upload form's "Enable TSBC for Tire products" checkbox — marks every Tire
 * product an import creates as eligible for FeeBCTireBundle's BC Tire Stewardship fee,
 * so an order containing that product charges TSBC. Eligibility is a ProductFee row
 * against the BC-tsbc fee (value 1.0), the same storage the per-product checkbox on the
 * product edit form writes.
 *
 * Deliberately decoupled from FeeBCTireBundle: its fee is looked up by slug rather than
 * by importing that bundle's calculator, since no module in this app `use`s another
 * module's classes. If that bundle is absent or Inactive this is inert and the checkbox
 * isn't offered — see isAvailable().
 *
 * Only ever touches products this import created. Re-importing an existing SKU never
 * re-stamps eligibility, matching the contract FeeBCTireBundle's own category-driven
 * import default already follows (BCTireFeeCalculator::applyImportDefault) — an admin who
 * unticks TSBC on one product keeps that choice through every later upload.
 */
final class TireTsbcEligibility
{
    /**
     * A product qualifies by landing in this category, matched case-insensitively on name
     * — whether NAME's first colon segment matched it directly or the upload form's
     * fallback-category dropdown sent the row there. Rows that resolved to any other
     * category (or to none) are left alone.
     *
     * Public because the upload form's "Fallback category" dropdown defaults to this same
     * category, and both behaviours should agree on what counts as Tire — go through
     * matchesTireCategory() rather than comparing names by hand.
     */
    public const TIRE_CATEGORY_NAME = 'tire';

    /** Kept in sync with FeeBCTireBundle's BCTireFeeCalculator::FEES and ::SOURCE. */
    private const FEE_SLUG = 'BC-tsbc';
    private const FEE_BUNDLE_SOURCE = 'FeeBCTireBundle';

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly ProductFeeRepository $productFeeRepo,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /**
     * Whether the upload form should offer the checkbox at all.
     *
     * Both halves are load-bearing. BundleStatusRepository::isActive() answers the admin's
     * Active/Inactive choice, but defaults to Active for a bundle that was never installed,
     * so it can't tell "present and on" from "absent" on its own; the BC-tsbc row existing
     * is what confirms FeeBCTireBundle is actually here. That row is seeded lazily by that
     * bundle (its fee config screen, or any product add/edit form render), so on a brand-new
     * install the checkbox stays hidden until one of those has been opened once.
     */
    public function isAvailable(): bool
    {
        return $this->bundleStatusRepo->isActive(self::FEE_BUNDLE_SOURCE)
            && $this->feeRepo->findBySlug(self::FEE_SLUG) !== null;
    }

    /**
     * @return int how many products were marked eligible — 0 when the fee bundle is
     *     absent/Inactive, or when the upload created no Tire products
     */
    public function applyToNewProducts(ProductImportResult $result, EntityManagerInterface $entityManager): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $fee = $this->feeRepo->findBySlug(self::FEE_SLUG);
        if ($fee === null) {
            return 0;
        }

        $applied = 0;
        $productRepo = $entityManager->getRepository(ProductCore::class);

        foreach ($this->createdSkus($result) as $sku) {
            $product = $productRepo->findOneBy(['sku' => $sku]);
            if (!$product instanceof ProductCore || !$this->isTireProduct($product)) {
                continue;
            }

            $this->productFeeRepo->setValue($product, $fee, 1.0);
            $applied++;
        }

        if ($applied > 0) {
            $entityManager->flush();
        }

        return $applied;
    }

    /**
     * ProductImportService logs one rowLog entry per processed row, keyed by that import's
     * primary key — always 'sku' for this bundle — so the 'created' entries are exactly the
     * products this upload brought into existence, and nothing it merely updated.
     *
     * @return list<string>
     */
    private function createdSkus(ProductImportResult $result): array
    {
        $skus = [];
        foreach ($result->rowLog as $entry) {
            $sku = trim((string) ($entry['key'] ?? ''));
            if (($entry['action'] ?? '') === 'created' && $sku !== '') {
                $skus[$sku] = true;
            }
        }

        return array_keys($skus);
    }

    /**
     * The one place a category name is judged to be Tire or not, shared with the upload
     * form's Tire dropdown default so the category that gets preselected is exactly the
     * category whose products this marks eligible.
     */
    public function matchesTireCategory(?string $categoryName): bool
    {
        return $categoryName !== null
            && strtolower(trim($categoryName)) === self::TIRE_CATEGORY_NAME;
    }

    private function isTireProduct(ProductCore $product): bool
    {
        return $this->matchesTireCategory($product->getCategory()?->getName());
    }
}
