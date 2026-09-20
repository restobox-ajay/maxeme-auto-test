<?php

declare(strict_types=1);

namespace ShippingArrangementBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;
use App\Repository\CustomFieldValueRepository;

final class ArrangementShippingCalculator implements ShippingOptionInterface
{
    private const SLUG = 'arrangement_shipping_eligible';

    public function __construct(
        private readonly CustomFieldValueRepository $customFieldValueRepo,
    ) {}

    public function supports(AbstractSalesDocument $document): bool
    {
        $companyId = $document->getCompany()?->getId();
        if ($companyId !== null
            && ($this->customFieldValueRepo->getValuesForObject('company', $companyId)[self::SLUG] ?? '') === '1'
        ) {
            return true;
        }

        $addressId = self::shippingAddressBookId($document);
        if ($addressId !== null
            && ($this->customFieldValueRepo->getValuesForObject('company_address', $addressId)[self::SLUG] ?? '') === '1'
        ) {
            return true;
        }

        return false;
    }

    /**
     * The address-BOOK row's id, not the document's own snapshot row's.
     *
     * The eligibility flag is a custom field owned by a `company_address` record, so the book id is
     * the only one that can ever match; a document address row's id could match only by coincidence,
     * and on a document being created it does not exist yet. Reading it off the effective address is
     * what makes a cart (which links to the book without freezing it) and a saved order (which froze
     * a copy and kept the link) answer the same way.
     */
    private static function shippingAddressBookId(AbstractSalesDocument $document): ?int
    {
        return $document->getEffectiveShippingAddress()?->getSourceAddress()?->getId();
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        return [
            new ShippingOption(
                id:           'existing-arrangement',
                label:        'Shipping Upon Existing Arrangement',
                description:  'Shipped under your company\'s existing arrangement — no charge.',
                amount:       0.0,
                deliveryDays: null,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }
}
