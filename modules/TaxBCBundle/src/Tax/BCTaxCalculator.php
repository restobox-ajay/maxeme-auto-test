<?php

declare(strict_types=1);

namespace TaxBCBundle\Tax;

use App\Contract\Cart\CartInfoField;
use App\Contract\Cart\CartInfoFieldInterface;
use App\Contract\Tax\TaxCalculatorInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Contract\Tax\TaxOrderSnapshotProviderInterface;
use App\Entity\SalesOrder;
use App\Entity\Company;
use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Repository\SalesTaxRepository;
use TaxBCBundle\EventSubscriber\BCPstNumberFieldSubscriber;

final class BCTaxCalculator implements TaxCalculatorInterface, TaxOrderSnapshotProviderInterface, CartInfoFieldInterface
{
    public const SOURCE = 'TaxBCBundle';

    private const BC_PROVINCE_NAMES = ['BC', 'British Columbia'];

    public function __construct(
        private readonly SalesTaxRepository $salesTaxRepo,
        private readonly CustomFieldValueRepository $customFieldValueRepo,
        private readonly CustomFieldDefinitionRepository $customFieldDefinitionRepo,
    ) {}

    public function supports(TaxContext $context): bool
    {
        return $context->province === 'BC';
    }

    public function calculate(TaxContext $context): array
    {
        $gst = $this->salesTaxRepo->getBySlug('gst', [
            'province_name' => 'Canada (Federal)',
            'abbreviation' => '',
            'tax_type' => 'GST',
            'rate' => 0.05,
            'status' => 'Active',
            'source' => self::SOURCE,
        ]);
        $pst = $this->salesTaxRepo->getBySlug('bc-pst', [
            'province_name' => 'British Columbia',
            'abbreviation' => 'BC',
            'tax_type' => 'PST',
            'rate' => 0.07,
            'status' => 'Active',
            'source' => self::SOURCE,
        ]);

        if ($context->taxClass === 'E') {
            return [];
        }

        $lines = [];
        if ($gst->getStatus() === 'Active') {
            $lines[] = new TaxLine('GST', $gst->getRate(), round($context->subtotal * $gst->getRate(), 2), $gst->getSlug());
        }

        if ($context->taxClass === 'S' && $this->companyPstNumber($context->companyId) === '' && $pst->getStatus() === 'Active') {
            $lines[] = new TaxLine('PST', $pst->getRate(), round($context->subtotal * $pst->getRate(), 2), $pst->getSlug());
        }

        return $lines;
    }

    /**
     * A company with its own PST registration number on file is presumed to
     * self-remit — mirrors FeeBCTireBundle\Fee\BCTireFeeCalculator::hasOwnTsbcNumber().
     */
    private function companyPstNumber(?int $companyId): string
    {
        if ($companyId === null) {
            return '';
        }

        $companyFields = $this->customFieldValueRepo->getValuesForObject(
            CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            $companyId
        );

        return trim($companyFields[BCPstNumberFieldSubscriber::SLUG] ?? '');
    }

    /**
     * Called by TaxCalculatorResolver::applyOrderSnapshots() right after an order's
     * tax lines are finalized (create or edit) — copies the company's *current* PST #
     * onto the order as a permanent record, same pattern as
     * FeeBCTireBundle\Fee\BCTireFeeCalculator::applyOrderSnapshot(). Skipped for
     * non-BC orders — there's nothing to record if PST was never in play.
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
            BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG
        );
        if ($orderDefinition === null) {
            // BCPstNumberFieldSubscriber registers this lazily on kernel.request; it will
            // already exist by the time any controller action runs, but fail safe if not.
            return;
        }

        $pstNumber = $this->companyPstNumber($companyId);

        $this->customFieldValueRepo->setValue($orderDefinition, $orderId, $pstNumber !== '' ? $pstNumber : null);
    }

    /**
     * Cart page "Your Profile" card display — a company with its own PST # on file
     * shows as not needing to pay, matching this calculator's own fee-skip logic above.
     *
     * @return CartInfoField[]
     */
    public function getFields(Company $company): array
    {
        $pstNumber = $this->companyPstNumber($company->getId());

        return [
            new CartInfoField('PST #', $pstNumber !== '' ? $pstNumber : '-'),
            new CartInfoField('PST Status', $pstNumber !== '' ? 'No need to pay' : 'Need to pay'),
        ];
    }
}
