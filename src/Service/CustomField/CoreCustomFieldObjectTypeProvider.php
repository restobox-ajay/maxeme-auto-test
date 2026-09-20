<?php

declare(strict_types=1);

namespace App\Service\CustomField;

use App\Contract\CustomField\CustomFieldObjectType;
use App\Contract\CustomField\CustomFieldObjectTypeProviderInterface;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomFieldValueCompany;
use App\Entity\CustomFieldValueCompanyAddress;
use App\Entity\CustomFieldValueEstimate;
use App\Entity\CustomFieldValueInvoice;
use App\Entity\CustomFieldValueOrder;
use App\Entity\CustomFieldValueProduct;
use App\Entity\CustomFieldValueProductCategory;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;

/**
 * Core's own seven object types, declared the same way a bundle declares its own (#745).
 *
 * This is `CustomFieldValueRepository::MAP` and `CustomFieldController::OBJECT_TYPES` — the two
 * hardcoded copies of the same seven mappings — collapsed into one. Core going through the seam
 * rather than around it is what makes "a type that forgets to register" a testable property:
 * without this, there would still be a hardcoded branch to special-case around, and a conformance
 * test could only ever prove bundles behave, never that core does.
 */
final class CoreCustomFieldObjectTypeProvider implements CustomFieldObjectTypeProviderInterface
{
    /** @return list<CustomFieldObjectType> */
    public function customFieldObjectTypes(): array
    {
        return [
            new CustomFieldObjectType('product', 'Product', CustomFieldValueProduct::class, ProductCore::class, 'product'),
            new CustomFieldObjectType('company', 'Customer', CustomFieldValueCompany::class, Company::class, 'company'),
            new CustomFieldObjectType('order', 'Order', CustomFieldValueOrder::class, SalesOrder::class, 'order'),
            // The two sell-side documents beside the order (#539/#586's numbering, extended to
            // custom fields): same shape in every respect — own value table, own FK — because an
            // admin who can hang a field off an order had no way to hang one off the quote it came
            // from or the invoice it became.
            new CustomFieldObjectType('invoice', 'Invoice', CustomFieldValueInvoice::class, Invoice::class, 'invoice'),
            new CustomFieldObjectType('estimate', 'Estimate', CustomFieldValueEstimate::class, Estimate::class, 'estimate'),
            // `selectable: false` — absent from the admin dropdown despite being mapped types: both
            // are registered by bundles (ShippingArrangementBundle, and the category scope on
            // product fields) rather than typed here. They stay registered so
            // CustomFieldValueRepository can still resolve them; CustomFieldController's dropdown
            // and filter bar read CustomFieldObjectTypeCatalogue::labels(), which filters to
            // selectable types only.
            new CustomFieldObjectType('company_address', 'Customer Address', CustomFieldValueCompanyAddress::class, CompanyAddress::class, 'companyAddress', selectable: false),
            new CustomFieldObjectType('product_category', 'Product Category', CustomFieldValueProductCategory::class, ProductCategory::class, 'productCategory', selectable: false),
        ];
    }
}
