<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Fee;

use App\Contract\Cart\CartInfoField;
use App\Contract\Cart\CartInfoFieldInterface;
use App\Contract\Fee\FeeCalculatorInterface;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeFieldProviderInterface;
use App\Contract\Fee\FeeImportColumnProviderInterface;
use App\Contract\Fee\FeeImportDefaultProviderInterface;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeOrderSnapshotProviderInterface;
use App\Entity\Company;
use App\Entity\SalesOrder;
use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCore;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Repository\FeeRepository;
use App\Repository\ProductFeeRepository;
use FeeBCTireBundle\EventSubscriber\BCTireCategoryEligibilityFieldSubscriber;
use FeeBCTireBundle\EventSubscriber\BCTireNumberFieldSubscriber;
use Symfony\Component\HttpFoundation\Request;

final class BCTireFeeCalculator implements FeeCalculatorInterface, FeeFieldProviderInterface, FeeImportDefaultProviderInterface, FeeImportColumnProviderInterface, FeeOrderSnapshotProviderInterface, CartInfoFieldInterface
{
    public const SOURCE = 'FeeBCTireBundle';

    private const BC_PROVINCE_NAMES = ['BC', 'British Columbia'];

    private const IMPORT_COLUMN = 'fee__BC-tsbc';

    public const FEES = [
        ['slug' => 'BC-tsbc', 'name' => 'BC Tire Stewardship (TSBC)', 'taxClass' => 'S', 'defaultValue' => 5.0],
    ];

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly ProductFeeRepository $productFeeRepo,
        private readonly CustomFieldValueRepository $customFieldValueRepo,
        private readonly CustomFieldDefinitionRepository $customFieldDefinitionRepo,
    ) {}

    public function supports(FeeContext $context): bool
    {
        return $context->province === 'BC';
    }

    public function calculate(FeeContext $context): array
    {
        if ($this->hasOwnTsbcNumber($context->companyId)) {
            return [];
        }

        $lines = [];

        foreach (self::FEES as $def) {
            $fee = $this->feeRepo->ensureBySlug($def['slug'], $def + ['source' => self::SOURCE]);

            if ($fee->getPlacement() === 'main_line') {
                foreach ($context->cartItems as ['product' => $product, 'qty' => $qty]) {
                    if ($qty <= 0 || $this->productFeeRepo->getValueForProduct($product, $fee) == 0.0) {
                        continue;
                    }
                    $amount = round($qty * $fee->getDefaultValue(), 2);
                    if ($amount > 0) {
                        $label = sprintf('%s for %s (%s)', $fee->getName(), $product->getName(), $product->getSku());
                        $lines[] = new FeeLine($fee->getId(), $fee->getSlug(), $label, $fee->getTaxClass(), $amount, $fee->getPlacement());
                    }
                }
            } else {
                $total = 0.0;
                foreach ($context->cartItems as ['product' => $product, 'qty' => $qty]) {
                    if ($qty <= 0 || $this->productFeeRepo->getValueForProduct($product, $fee) == 0.0) {
                        continue;
                    }
                    $total += $qty * $fee->getDefaultValue();
                }
                if ($total > 0) {
                    $lines[] = new FeeLine($fee->getId(), $fee->getSlug(), $fee->getName(), $fee->getTaxClass(), round($total, 2), $fee->getPlacement());
                }
            }
        }

        return $lines;
    }

    /**
     * A company with its own TSBC registration number on file is presumed to
     * self-remit — same empty/non-empty guard shape as PST's own exemption
     * (see TaxBCBundle\Tax\BCTaxCalculator), just sourced from this bundle's
     * own company custom field instead of Company::$pstNumber.
     */
    private function hasOwnTsbcNumber(?int $companyId): bool
    {
        return $companyId !== null && $this->companyTsbcNumber($companyId) !== '';
    }

    private function companyTsbcNumber(int $companyId): string
    {
        $companyFields = $this->customFieldValueRepo->getValuesForObject(
            CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            $companyId
        );

        return trim($companyFields[BCTireNumberFieldSubscriber::SLUG] ?? '');
    }

    /**
     * Called by FeeCalculatorResolver::applyOrderSnapshots() right after an order's
     * fee lines are finalized (create or edit) — copies the company's *current* TSBC #
     * onto the order as a permanent record, matching number1_inventory's own
     * Order.user_tsbc snapshot and this app's existing pattern of never recomputing
     * feeLines/taxLines on later views. Skipped for non-BC orders — there's nothing
     * to record if TSBC was never in play.
     */
    public function applyOrderSnapshot(SalesOrder $order, int $companyId): void
    {
        $province = trim((string) ($order->getEffectiveShippingAddress()?->getProvince() ?? ''));
        if (!in_array($province, self::BC_PROVINCE_NAMES, true)) {
            return;
        }

        $orderId = $order->getId();
        if ($orderId === null) {
            return;
        }

        $orderDefinition = $this->customFieldDefinitionRepo->findBySlug(
            CustomFieldDefinition::OBJECT_TYPE_ORDER,
            BCTireNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG
        );
        if ($orderDefinition === null) {
            // BCTireNumberFieldSubscriber registers this lazily on kernel.request; it will
            // already exist by the time any controller action runs, but fail safe if not.
            return;
        }

        $tsbcNumber = $this->companyTsbcNumber($companyId);

        $this->customFieldValueRepo->setValue($orderDefinition, $orderId, $tsbcNumber !== '' ? $tsbcNumber : null);
    }

    /**
     * Cart page "Your Profile" card display — a company with its own TSBC # on file
     * shows as not needing to pay, matching hasOwnTsbcNumber()'s fee-skip logic above.
     *
     * @return CartInfoField[]
     */
    public function getFields(Company $company): array
    {
        $companyId = $company->getId();
        if ($companyId === null) {
            return [];
        }

        $tsbcNumber = $this->companyTsbcNumber($companyId);

        return [
            new CartInfoField('TSBC #', $tsbcNumber !== '' ? $tsbcNumber : '-'),
            new CartInfoField('TSBC Status', $tsbcNumber !== '' ? 'No need to pay' : 'Need to pay'),
        ];
    }

    public function renderProductFields(ProductCore $product): string
    {
        $html = '';

        foreach (self::FEES as $def) {
            $fee     = $this->feeRepo->ensureBySlug($def['slug'], ['name' => $def['name'], 'taxClass' => $def['taxClass'], 'source' => self::SOURCE]);
            $label   = htmlspecialchars($fee->getName(), ENT_QUOTES);
            $rate    = number_format($fee->getDefaultValue(), 2);
            $checked = $this->productFeeRepo->getValueForProduct($product, $fee) == 1.0 ? ' checked' : '';
            $slug    = htmlspecialchars($def['slug'], ENT_QUOTES);

            $html .= <<<HTML
                <label class="check-row">
                    <input type="checkbox" name="fee[{$slug}]" value="1"{$checked}>
                    {$label} (rate: \${$rate} per eligible unit)
                </label>
                HTML;
        }

        return $html;
    }

    public function saveProductFields(ProductCore $product, Request $request): void
    {
        $feeInput = $request->request->all('fee');

        foreach (self::FEES as $def) {
            $fee   = $this->feeRepo->ensureBySlug($def['slug'], ['name' => $def['name'], 'taxClass' => $def['taxClass'], 'source' => self::SOURCE]);
            $value = isset($feeInput[$def['slug']]) ? 1.0 : 0.0;
            $this->productFeeRepo->setValue($product, $fee, $value);
        }
    }

    /**
     * Called once by ProductImportService for newly-created products only — seeds the
     * per-product checkbox from the product's category so staff isn't hand-toggling it
     * on every imported SKU. Never touches products that already exist.
     */
    public function applyImportDefault(ProductCore $product): void
    {
        $category = $product->getCategory();
        if ($category === null || $category->getId() === null) {
            return;
        }

        $categoryFields = $this->customFieldValueRepo->getValuesForObject(
            CustomFieldDefinition::OBJECT_TYPE_PRODUCT_CATEGORY,
            $category->getId()
        );

        if (($categoryFields[BCTireCategoryEligibilityFieldSubscriber::SLUG] ?? '') !== '1') {
            return;
        }

        foreach (self::FEES as $def) {
            $fee = $this->feeRepo->ensureBySlug($def['slug'], $def + ['source' => self::SOURCE]);
            $this->productFeeRepo->setValue($product, $fee, 1.0);
        }
    }

    /**
     * Lets a bulk CSV import set/clear TSBC eligibility per row — the same choice
     * saveProductFields() makes one product at a time from the manual form's
     * fee[BC-tsbc] checkbox. Runs for every row ProductImportService processes, new
     * or existing, so an explicit column value always wins over both the
     * category-seeded applyImportDefault() and whatever was stored before.
     */
    public function getImportColumnName(): string
    {
        return self::IMPORT_COLUMN;
    }

    public function applyImportValue(ProductCore $product, bool $enabled): void
    {
        $def = self::FEES[0];
        $fee = $this->feeRepo->ensureBySlug($def['slug'], $def + ['source' => self::SOURCE]);
        $this->productFeeRepo->setValue($product, $fee, $enabled ? 1.0 : 0.0);
    }
}
