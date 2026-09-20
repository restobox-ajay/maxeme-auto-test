<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use App\Service\Product\ProductPicker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The sell side's product cell, now rendered by the field every admin screen shares —
 * templates/admin/_partials/product_field.html.twig, reached through the shared line row.
 *
 * ## What this file is FOR
 *
 * The sell side invented the three tiers (#399) and then never adopted the partial they were
 * extracted into, because the partial emitted one `data-*` and the sell side needs eight: its line
 * JavaScript fills the whole row — SKU, weight, unit, tax code, cost, original price, price, and
 * whether the product is batch-tracked — off the chosen `<option>` without a round trip. The
 * partial now takes the payload and the option-text shape as ARGUMENTS, so the sell side gets
 * eight and the nineteen callers that want one are untouched.
 *
 * **The failure mode this exists for is silent.** A missed `data-*` throws nothing, logs nothing and
 * renders a `<select>` that looks perfect; the row simply stops filling itself in, and the first
 * person to notice is whoever finds a saved order line with a blank weight. A test that asserts
 * "the select is there" passes the whole way through that. So every one of the eight is asserted
 * INDIVIDUALLY here, by name, against its own expected value, read off the rendered page — and
 * proved by mutation: dropping any single attribute from the payload fails exactly one assertion
 * in `everyOptionCarriesAllEightAutofillAttributes()` and nothing else in the file.
 *
 * ## No bare numbers, no unpaired absences (#627)
 *
 * `see('12.5')` matches '112.50'. Every figure here is grabbed from a NAMED attribute of a named
 * element and compared as a whole string, and every absence is asserted on an element that the
 * assertion above it has just proved is on the page — `dontSeeElement` is equally happy against a
 * typo'd selector and against a 500.
 *
 * ## Conducted (#624)
 *
 * The saves drive the real screens with plain form posts and re-read `sales_order_line`,
 * `estimate_line` and `invoice_line` BY COLUMN afterwards, including the `<noscript>` id box that
 * is the only way to name a product past the inline limit with scripting off — the tier nobody
 * exercises by hand. Each POST is preceded by an assertion that the screen really renders a control
 * under the name being posted, because a POST of hand-written field names tests the controller and
 * not the screen.
 */
final class SellSideProductFieldCest
{
    /**
     * The eight `data-*` attributes the sell-side line JavaScript reads on change, and the product
     * column each one carries. Named here once; every screen is checked against all eight.
     *
     * The fixture below gives all eight pairwise-DIFFERENT values on purpose. Two attributes
     * carrying the same string would let a payload that reads the wrong column pass — which is the
     * defect `data-original-price`/`data-price` are one typo away from, since both are money on the
     * same product.
     *
     * `data-tracks-batch` is the odd one: only the ORDER's product rows carry a `tracksBatch` key
     * at all, so the order says '1' for a lot-tracked product and the quote and the invoice say ''
     * — which is what they said before this field was shared, and is the reason the payload has to
     * tolerate a key a given document's rows do not have.
     */
    private const AUTOFILL_ATTRIBUTES = [
        'data-sku', 'data-weight', 'data-unit', 'data-tax-code',
        'data-cost', 'data-original-price', 'data-price', 'data-tracks-batch',
    ];

    private ?Company $company = null;
    private ?ProductCore $product = null;
    private ?ProductCore $other = null;
    private ?UnitOfMeasure $each = null;
    private ?UnitOfMeasure $box = null;

    /**
     * Codeception reuses ONE Cest instance across every method in this file, so anything left on
     * $this by the previous test is a handle onto a row that has since been rolled back. All of it
     * is cleared here rather than reused.
     */
    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
        $this->other = null;
        $this->each = null;
        $this->box = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sell-side-product-field@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---------------------------------------------------------------------------- fixtures

    private function unit(FunctionalTester $I, string $code, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }
        $unit = (new UnitOfMeasure())->setCode($code)->setName($code)
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase($factor)->setRoundingPrecision('1');
        $I->haveInRepository($unit);

        return $unit;
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }
        $company = (new Company())->setName('Sell Side Picker Co')->setCode('SSPF-1')
            ->setPrimaryEmail('ap@sspf.example');
        $I->haveInRepository($company);
        $I->haveInRepository((new CompanyAddress())->setCompany($company)->setLabel('Bill')
            ->setCompanyName('Sell Side Picker Co')->setFirstName('Bill')->setLastName('Payer')
            ->setAddressLine1('1 Billing Way')->setCity('Vancouver')->setProvince('BC')
            ->setCountry('CA')->setPostalCode('V5K0A1')->setIsDefaultBilling(true));
        $I->haveInRepository((new CompanyAddress())->setCompany($company)->setLabel('Ship')
            ->setCompanyName('Sell Side Picker Co')->setFirstName('Ship')->setLastName('Receiver')
            ->setAddressLine1('2 Shipping Road')->setCity('Burnaby')->setProvince('BC')
            ->setCountry('CA')->setPostalCode('V5K0A2')->setIsDefaultShipping(true));
        $I->haveActiveFulfillmentRegionFor($company, 'Main');
        $this->company = $company;

        return $company;
    }

    /**
     * The product every autofill assertion is made against.
     *
     * Its eight values are deliberately unlike each other AND unlike the row the document already
     * holds, so no assertion can pass by matching the wrong column or the line's own stored figure.
     */
    private function makeProduct(FunctionalTester $I): ProductCore
    {
        if ($this->product instanceof ProductCore) {
            return $this->product;
        }
        $this->each = $this->unit($I, 'EA', '1');
        $this->box = $this->unit($I, 'BOX-12', '12');

        $product = (new ProductCore())
            ->setSku('SSPF-SKU-1')->setName('Sell Side Picker Widget')
            ->setUnit('EA')->setWeight('12.500')
            ->setCostPrice('30.55')->setDefaultPrice('65.25')->setOriginalPrice('99.99')
            ->setSalesTaxCode('G')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setBaseUnit($this->each);
        // Lot-tracked OUTBOUND, so the order's rows report data-tracks-batch="1" rather than the
        // empty string a product with no policy would report — an assertion against '' would pass
        // just as well against an attribute that was never rendered.
        $policy = (new TrackingPolicy())->setName('SSPF Lot Policy')->setMode(TrackingPolicy::MODE_LOT)
            ->setTrackIn(true)->setTrackOut(true);
        $I->haveInRepository($policy);
        $product->setTrackingPolicy($policy);
        $I->haveInRepository($product);
        $I->haveInRepository((new ProductAvailableUnit())->setProduct($product)->setUnit($this->box));
        $I->haveStockFor($product, 500, 'Main');
        $this->product = $product;

        return $product;
    }

    /** A second product, which exists to be the one the picker was NOT showing. */
    private function makeOtherProduct(FunctionalTester $I): ProductCore
    {
        if ($this->other instanceof ProductCore) {
            return $this->other;
        }
        $this->makeProduct($I);
        $other = (new ProductCore())
            ->setSku('SSPF-SKU-2')->setName('Sell Side Spare Widget')
            ->setUnit('EA')->setWeight('0.750')
            ->setCostPrice('1.10')->setDefaultPrice('2.20')->setOriginalPrice('3.30')
            ->setSalesTaxCode('E')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        // Re-resolved rather than reused off $this: a POST earlier in the same test runs a real
        // kernel request, which can clear the EntityManager and leave the cached handle detached —
        // and a detached UnitOfMeasure here reads to Doctrine as a brand-new unit.
        $other->setBaseUnit($this->unit($I, 'EA', '1'));
        $I->haveInRepository($other);
        $I->haveStockFor($other, 500, 'Main');
        $this->other = $other;

        return $other;
    }

    private function makeOrder(FunctionalTester $I): SalesOrder
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('SSPF-ORDER-1')
            ->setFulfillmentRegion('Main')->setSubtotal('0.00')->setTax('0.00')->setTotal('0.00');
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());
        $line = (new SalesOrderLine())->setProduct($product)->setName('Sell Side Picker Widget')
            ->setSku('SSPF-SKU-1')->setLocation('Main')->setWeight('12.500')->setUnit('EA')
            ->setTaxCode('G')->setCost('30.55')->setPrice('65.25');
        $line->setEnteredQuantity('2', $this->each, $this->each);
        $order->addLine($line);
        $I->haveInRepository($order);

        return $order;
    }

    private function makeEstimate(FunctionalTester $I): Estimate
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $estimate = (new Estimate())->setCompany($company)->setDocumentNumber('SSPF-EST-1')
            ->setSource('Admin');
        $estimate->setStatus('Draft', DocumentActor::system());
        $estimate->setFulfillmentRegion('Main');
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $line = (new EstimateLine())->setProduct($product)->setName('Sell Side Picker Widget')
            ->setSku('SSPF-SKU-1')->setLocation('Main')->setWeight('12.500')->setUnit('EA')
            ->setTaxCode('G')->setCost('30.55')->setPrice('65.25');
        $line->setEnteredQuantity('2', $this->each, $this->each);
        $estimate->addLine($line);
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /**
     * Enough catalogue to cross ProductPicker::INLINE_LIMIT, which is the only way to reach tier 2
     * and tier 3 — the js-only select and the <noscript> id box beside it.
     */
    private function crossTheInlineLimit(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = (int) $em->getRepository(ProductCore::class)->count(['deleted' => false]);
        for ($i = $existing; $i <= ProductPicker::INLINE_LIMIT; ++$i) {
            $em->persist((new ProductCore())
                ->setSku('SSPF-FILL-' . $i)->setName('Sell Side Filler ' . $i)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL));
        }
        $em->flush();

        $I->assertTrue(
            $I->grabService(ProductPicker::class)->isRemote(),
            'these assertions are about the past-the-limit tier, so the catalogue must be past it',
        );
    }

    // ------------------------------------------------------------------------------ tools

    /**
     * What the product cell's `<option>` for a given product carries, attribute by attribute.
     *
     * @return array<string, string>
     */
    private function optionAttributes(FunctionalTester $I, string $select, int $productId): array
    {
        $selector = sprintf('%s option[value="%d"]', $select, $productId);
        $found = [];
        foreach (self::AUTOFILL_ATTRIBUTES as $attribute) {
            $found[$attribute] = (string) $I->grabAttributeFrom($selector, $attribute);
        }

        return $found;
    }

    /**
     * The eight values the product's own columns say that option should be carrying.
     *
     * @param bool $carriesTracksBatch whether THIS document's product rows have the key at all
     */
    private function expectedAutofill(ProductCore $product, bool $carriesTracksBatch): array
    {
        return [
            'data-sku' => (string) $product->getSku(),
            'data-weight' => (string) $product->getWeight(),
            'data-unit' => (string) $product->getUnit(),
            // The stored short code, which TaxContext::mapTaxCode() passes through unchanged for
            // anything non-empty. Blank would map to 'E', which is a different assertion.
            'data-tax-code' => (string) $product->getSalesTaxCode(),
            'data-cost' => (string) $product->getCostPrice(),
            'data-original-price' => (string) $product->getOriginalPrice(),
            // No price list for this company/region, so the default price is what the row prices at.
            'data-price' => (string) $product->getDefaultPrice(),
            // '1' where the document states it and the product is lot-tracked outbound; '' where
            // the document's rows carry no such key, which is how the quote and the invoice have
            // always rendered this cell.
            'data-tracks-batch' => $carriesTracksBatch ? '1' : '',
        ];
    }

    /**
     * Asserts the screen really renders a control under each of these names before anything posts
     * them. Without it a POST proves only that the controller accepts a field.
     */
    private function seeTheFormPosts(FunctionalTester $I, string $form, array $names): void
    {
        $rendered = [];
        foreach (['input', 'select', 'textarea'] as $control) {
            $rendered = array_merge($rendered, $I->grabMultiple($form . ' ' . $control, 'name'));
        }
        $rendered = array_filter($rendered, static fn (?string $n): bool => (string) $n !== '');

        foreach ($names as $name) {
            $I->assertContains(
                $name,
                $rendered,
                sprintf('%s renders no control named %s — this POST would be testing the controller, not the screen', $form, $name),
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(FunctionalTester $I, string $table, string $fk, int $documentId): array
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY id', $table, $fk),
            [$documentId],
        );
    }

    // ------------------------------------------------- 1. the eight attributes, one at a time

    /**
     * Every sell-side product option carries all eight autofill attributes, each with the value its
     * own product column holds — on the order form, the quote form and the standalone invoice
     * create form.
     *
     * One assertion per attribute per screen, naming the attribute. That is the point: an assertion
     * over the set as a whole says "something is wrong with the payload", and this says which one.
     */
    public function everyOptionCarriesAllEightAutofillAttributes(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $screens = [
            'order form' => [
                '/admin/order/edit/' . $order->getId(),
                'table.order-lines-table tbody tr.order-line-row select[name="lines[0][product_id]"]',
                true,
            ],
            'quote form' => [
                '/admin/estimate/edit/' . $estimate->getId(),
                'table.estimate-line-table tbody tr.estimate-line-row select[name="lines[0][product_id]"]',
                false,
            ],
            'standalone invoice create form' => [
                '/admin/invoice/create?company_id=' . $company->getId(),
                'table.invoice-line-table tbody tr.invoice-line-row select[name="lines[0][product_id]"]',
                false,
            ],
        ];

        foreach ($screens as $screen => [$url, $select, $carriesTracksBatch]) {
            $expected = $this->expectedAutofill($product, $carriesTracksBatch);
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            // The positive control the eight assertions below need: this exact select, carrying an
            // option for this exact product, is on the page.
            $I->seeElement(sprintf('%s option[value="%d"]', $select, $product->getId()));

            $actual = $this->optionAttributes($I, $select, (int) $product->getId());
            foreach (self::AUTOFILL_ATTRIBUTES as $attribute) {
                $I->assertSame(
                    $expected[$attribute],
                    $actual[$attribute],
                    sprintf(
                        'the %s renders %s="%s" on its product option, so the row will not autofill from it',
                        $screen,
                        $attribute,
                        $actual[$attribute],
                    ),
                );
            }
            // Every one of the eight differs from every other, so none of the assertions above
            // could have passed by matching a neighbouring attribute's value.
            $I->assertSame(
                count(self::AUTOFILL_ATTRIBUTES),
                count(array_unique($actual)),
                'two autofill attributes carry the same string, so this screen cannot tell them apart',
            );
        }
    }

    /**
     * The option's visible text is the product NAME alone, and the SKU is still on the element —
     * in `data-sku`, where the JavaScript reads it.
     *
     * The absence (no SKU in the label) is only worth anything beside the presence (the SKU IS on
     * this option), and both are asserted on the same element.
     */
    public function theOptionReadsAsTheProductNameWhileTheSkuStaysInTheData(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $product = $this->makeProduct($I);
        $select = 'table.order-lines-table tbody tr.order-line-row select[name="lines[0][product_id]"]';

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $option = sprintf('%s option[value="%d"]', $select, $product->getId());

        $I->assertSame(
            (string) $product->getSku(),
            (string) $I->grabAttributeFrom($option, 'data-sku'),
            'the SKU is not on the option, so "the label omits it" would be hiding a real loss',
        );
        $I->assertSame(
            (string) $product->getName(),
            trim((string) $I->grabTextFrom($option)),
            'the sell-side option label is the product name and nothing else',
        );
        $I->assertStringNotContainsString(
            (string) $product->getSku(),
            trim((string) $I->grabTextFrom($option)),
            'the sell side shows the SKU in its own column, so the label must not repeat it',
        );
    }

    /**
     * The nineteen callers that only have to NAME a product still get exactly one `data-*` and the
     * SKU-and-name label. This is the additivity claim, asserted from the other side.
     *
     * Both bundles are checked: Inventory Depth calls core's partial directly, Procurement reaches
     * it through `@Procurement/_product_field.html.twig`, and the two paths could drift apart.
     */
    public function theBundleCallersStillGetOneAttributeAndTheSkuAndNameLabel(FunctionalTester $I): void
    {
        $product = $this->makeProduct($I);

        $screens = [
            '/admin/bundles/inventory-depth/lots' => 'select[name="product_id"]',
            '/admin/bundles/procurement/purchase-orders/new' => 'select[name="lines[0][product_id]"]',
        ];

        foreach ($screens as $url => $select) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $option = sprintf('%s option[value="%d"]', $select, $product->getId());

            // Present: the one attribute this tier of caller has always had.
            $I->seeElement($option . '[data-sku]');
            $I->assertSame(
                (string) $product->getSku(),
                (string) $I->grabAttributeFrom($option, 'data-sku'),
                $url . ' lost data-sku from its product option',
            );
            $I->assertSame(
                $product->getSku() . ' — ' . $product->getName(),
                trim((string) $I->grabTextFrom($option)),
                $url . ' no longer labels its options with the SKU and the name',
            );

            // Absent: the sell side's six, on the SAME element the assertions above just proved is
            // rendered and populated. A default that quietly grew would show up here.
            foreach (['data-weight', 'data-unit', 'data-tax-code', 'data-cost', 'data-original-price', 'data-price', 'data-tracks-batch'] as $extra) {
                $I->dontSeeElement($option . '[' . $extra . ']');
            }
        }
    }

    /**
     * The rest of the picker's wiring, which the payload assertions above would not notice at all:
     * each document's own js- hook and CSS class, the placeholder it has always shown, the search
     * endpoint tier 2 fetches from, and the aria-label it does NOT carry.
     *
     * The last one is pinned deliberately. Giving this select an accessible name would be an
     * improvement, and it is not this change's to make: adopting the shared field was meant to
     * leave every one of these screens rendering exactly what it rendered before, and an assertion
     * is the only thing that makes that a claim rather than an intention.
     */
    public function theSellSidePickerKeepsItsHooksItsPlaceholderAndItsOwnSearchEndpoint(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $screens = [
            '/admin/order/edit/' . $order->getId() => [
                ['js-order-product-select', 'order-line-product-select'],
                '/admin/order/products/search',
            ],
            '/admin/estimate/edit/' . $estimate->getId() => [
                ['js-estimate-product-select', 'estimate-line-product-select'],
                '/admin/estimate/products/search',
            ],
            // The invoice create screen binds no line JavaScript, so it has no js- hook — only its
            // CSS class — and it borrows the order's endpoint, deliberately: it is one catalogue.
            '/admin/invoice/create?company_id=' . $company->getId() => [
                ['invoice-line-product-select'],
                '/admin/order/products/search',
            ],
        ];

        $select = 'select[name="lines[0][product_id]"]';
        foreach ($screens as $url => [$classes, $searchPath]) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            foreach ($classes as $class) {
                $I->seeElement($select . '.' . $class);
            }
            $I->assertSame(
                'Select a product...',
                (string) $I->grabAttributeFrom($select, 'data-placeholder'),
                $url . ' changed the product picker\'s placeholder',
            );
            // The absence, on the element the two assertions above just proved is rendered and
            // populated.
            $I->dontSeeElement($select . '[aria-label]');
            // No data-search-url under the inline limit: a select carrying the whole catalogue has
            // nothing to fetch.
            $I->dontSeeElement($select . '[data-search-url]');
        }

        $this->crossTheInlineLimit($I);
        foreach ($screens as $url => [$classes, $searchPath]) {
            $I->amOnPage($url);
            foreach ($classes as $class) {
                $I->seeElement($select . '.' . $class);
            }
            $I->assertSame(
                $searchPath,
                (string) $I->grabAttributeFrom($select, 'data-search-url'),
                $url . ' no longer points tier 2 at its own product search endpoint',
            );
        }
    }

    /**
     * A line that already names a product renders that product as the CHOSEN option — and the other
     * product in the same select is not chosen.
     *
     * Without the negative half this would pass against a picker that marked every option selected,
     * and without the positive half it would pass against a page that failed to render.
     */
    public function aSavedLineRendersItsOwnProductAsTheChosenOption(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);
        $other = $this->makeOtherProduct($I);
        $select = 'select[name="lines[0][product_id]"]';

        foreach ([
            '/admin/order/edit/' . $order->getId(),
            '/admin/estimate/edit/' . $estimate->getId(),
        ] as $url) {
            $I->amOnPage($url);
            $I->seeElement(sprintf('%s option[value="%d"][selected]', $select, $product->getId()));
            // Both halves on the same select: the other product IS offered, and is NOT chosen.
            $I->seeElement(sprintf('%s option[value="%d"]', $select, $other->getId()));
            $I->dontSeeElement(sprintf('%s option[value="%d"][selected]', $select, $other->getId()));
        }
    }

    /**
     * The order's JS row <template> carries the same field the server-rendered rows do.
     *
     * It is the one place on the sell side that still held its own copy of the picker — the row
     * around it is hand-rolled and stays that way for now — and it is the copy that matters most
     * for autofill: a row an admin adds with the + button is built from THIS markup, so a payload
     * that differs here means every added line stops filling itself in while every line already on
     * the page keeps working. Nothing about that says which half is wrong.
     *
     * The quote's own product-line <template> has rendered through the shared row since that
     * refactor landed, so this is the order catching up with it.
     */
    public function theOrdersJsRowTemplateCarriesTheSameFieldTheServerRenderedRowsDo(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $product = $this->makeProduct($I);
        $expected = $this->expectedAutofill($product, true);
        $select = 'template#order-product-line-template select[name="lines[__INDEX__][product_id]"]';

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement($select . '.js-order-product-select.order-line-product-select');
        $I->seeElement(sprintf('%s option[value="%d"]', $select, $product->getId()));

        $actual = $this->optionAttributes($I, $select, (int) $product->getId());
        foreach (self::AUTOFILL_ATTRIBUTES as $attribute) {
            $I->assertSame(
                $expected[$attribute],
                $actual[$attribute],
                sprintf('a row added with the + button would not autofill: the template renders %s="%s"', $attribute, $actual[$attribute]),
            );
        }
        $I->assertSame(
            (string) $product->getName(),
            trim((string) $I->grabTextFrom(sprintf('%s option[value="%d"]', $select, $product->getId()))),
            'the template\'s option label disagrees with the server-rendered rows\'',
        );
    }

    // --------------------------------------------- 2. the manual-name derivation, all three

    /**
     * Past the inline limit each of the three screens renders the `<noscript>` id box under the name
     * its controller reads — `lines[N][product_id_manual]`, derived from `lines[N][product_id]`.
     *
     * The naming reconciliation that landed before this change is what makes one derivation enough
     * for all three: every sell-side form posts `lines[N][field]` now, so the partial's indexed rule
     * produces the right name unaided and no caller needs an override.
     *
     * Asserted against the SOURCE and not the DOM, and paired with the absence of the name the
     * derivation would have produced had it been applied to the whole bracketed field. Two opposite
     * assertions through one mechanism cannot both pass by accident.
     */
    public function pastTheLimitAllThreeScreensNameTheNoScriptBoxTheWayTheirControllerReadsIt(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);
        $this->crossTheInlineLimit($I);

        $urls = [
            '/admin/order/edit/' . $order->getId(),
            '/admin/estimate/edit/' . $estimate->getId(),
            '/admin/invoice/create?company_id=' . $company->getId(),
        ];

        foreach ($urls as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            // The positive control: the select this box stands in for IS on the page, and is
            // js-only, which is the whole reason the box exists.
            $I->seeElement('select[name="lines[0][product_id]"].js-only');
            $I->seeInSource('name="lines[0][product_id_manual]"');

            // The derivations that would have been wrong. Absences worth something because the
            // right name was just found in the same source.
            $I->dontSeeInSource('name="lines[0][product_id]_manual"');
            $I->dontSeeInSource('name="lines[0][product_manual]"');
        }
    }

    /**
     * Under the limit there is no id box at all, on any of the three — a plain select of the whole
     * catalogue needs no fallback, because a browser makes it type-to-searchable for free.
     */
    public function underTheLimitNoneOfTheThreeRendersAnIdBox(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->assertFalse(
            $I->grabService(ProductPicker::class)->isRemote(),
            'this assertion is about the under-the-limit tier, so the catalogue must be under it',
        );

        foreach ([
            '/admin/order/edit/' . $order->getId(),
            '/admin/estimate/edit/' . $estimate->getId(),
            '/admin/invoice/create?company_id=' . $company->getId(),
        ] as $url) {
            $I->amOnPage($url);
            // Positive control on the same control the absences are about.
            $I->seeElement('select[name="lines[0][product_id]"]');
            $I->dontSeeElement('select[name="lines[0][product_id]"].js-only');
            $I->dontSeeElement('select[name="lines[0][product_id]"][data-search-url]');
            $I->dontSeeInSource('name="lines[0][product_id_manual]"');
        }
    }

    // ------------------------------------------------------- 3. the saves, column by column

    /** The order still saves the product its picker names. */
    public function theOrderSavesTheProductThePickerNames(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $other = $this->makeOtherProduct($I);
        $lineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $this->seeTheFormPosts($I, 'form#order-form', ['lines[0][id]', 'lines[0][product_id]']);
        // And the product being posted is one the picker actually offers.
        $I->seeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $other->getId()));

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->makeCompany($I)->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => (string) $other->getId(),
                'location' => 'Main',
                'sku' => 'SSPF-POSTED',
                'qty' => '3',
                'weight' => '0.750',
                'unit_id' => '',
                'unit' => 'EA',
                'tax_code' => 'E',
                'cost' => '1.10',
                'price' => '2.20',
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        $rows = $this->lineRows($I, 'sales_order_line', 'order_id', (int) $order->getId());
        $I->assertCount(1, $rows, 'the order still has exactly the one line that was posted');
        $I->assertSame($lineId, (int) $rows[0]['id'], 'lines[0][id] named the existing row');
        $I->assertSame(
            (int) $other->getId(),
            (int) $rows[0]['product_id'],
            'sales_order_line.product_id is not the product the picker named',
        );
    }

    /** And past the limit it saves the product the `<noscript>` id box names, with scripting off. */
    public function theOrderSavesTheProductTheNoScriptIdBoxNames(FunctionalTester $I): void
    {
        $order = $this->makeOrder($I);
        $other = $this->makeOtherProduct($I);
        $lineId = (int) $order->getLines()->first()->getId();
        $this->crossTheInlineLimit($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeInSource('name="lines[0][product_id_manual]"');
        // The scenario, stated: past the limit the select is seeded with this line's own product
        // only, so the product being typed into the id box is NOT among its options. Nothing else
        // on the page could have supplied it.
        $I->dontSeeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $other->getId()));
        $I->seeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $this->makeProduct($I)->getId()));

        // What a no-JS browser actually posts: the hidden select still submits (empty, since its
        // seeded option is not what the admin chose) and the id box carries the answer.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->makeCompany($I)->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => '',
                'product_id_manual' => (string) $other->getId(),
                'location' => 'Main',
                'sku' => 'SSPF-NOJS',
                'qty' => '3',
                'weight' => '0.750',
                'unit_id' => '',
                'unit' => 'EA',
                'tax_code' => 'E',
                'cost' => '1.10',
                'price' => '2.20',
            ]],
            'save_mode' => 'draft_recalc',
        ]);

        $rows = $this->lineRows($I, 'sales_order_line', 'order_id', (int) $order->getId());
        $I->assertCount(1, $rows, 'the order still has exactly the one line that was posted');
        $I->assertSame($lineId, (int) $rows[0]['id'], 'lines[0][id] named the existing row');
        $I->assertSame(
            (int) $other->getId(),
            (int) $rows[0]['product_id'],
            'sales_order_line.product_id is not the product the no-JS id box named',
        );
        $I->assertSame('SSPF-NOJS', (string) $rows[0]['sku'], 'the rest of the no-JS row saved too');
    }

    /** The quote, both ways. */
    public function theQuoteSavesTheProductEitherControlNames(FunctionalTester $I): void
    {
        $estimate = $this->makeEstimate($I);
        $other = $this->makeOtherProduct($I);
        $lineId = (int) $estimate->getLines()->first()->getId();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $this->seeTheFormPosts($I, 'form#estimate-form', ['lines[0][id]', 'lines[0][product_id]']);
        $I->seeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $other->getId()));

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => (string) $other->getId(),
                'location' => 'Main', 'sku' => 'SSPF-Q', 'qty' => '3', 'weight' => '0.750',
                'unit_id' => '', 'unit' => 'EA', 'tax_code' => 'E', 'cost' => '1.10', 'price' => '2.20',
            ]],
        ]);

        $rows = $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId());
        $I->assertCount(1, $rows, 'the quote still has exactly the one line that was posted');
        $I->assertSame(
            (int) $other->getId(),
            (int) $rows[0]['product_id'],
            'estimate_line.product_id is not the product the picker named',
        );

        // Now the no-JS half, on the same document, past the limit.
        $this->crossTheInlineLimit($I);
        $back = $this->makeProduct($I);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeInSource('name="lines[0][product_id_manual]"');
        $I->dontSeeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $back->getId()));

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'action' => 'save',
            'fulfillment_region' => 'Main',
            'lines' => [0 => [
                'id' => (string) $lineId,
                'product_id' => '',
                'product_id_manual' => (string) $back->getId(),
                'location' => 'Main', 'sku' => 'SSPF-Q-NOJS', 'qty' => '3', 'weight' => '12.500',
                'unit_id' => '', 'unit' => 'EA', 'tax_code' => 'G', 'cost' => '30.55', 'price' => '65.25',
            ]],
        ]);

        $rows = $this->lineRows($I, 'estimate_line', 'estimate_id', (int) $estimate->getId());
        $I->assertCount(1, $rows, 'the quote still has exactly the one line that was posted');
        $I->assertSame(
            (int) $back->getId(),
            (int) $rows[0]['product_id'],
            'estimate_line.product_id is not the product the no-JS id box named',
        );
        $I->assertSame('SSPF-Q-NOJS', (string) $rows[0]['sku'], 'the rest of the no-JS row saved too');
    }

    /** The standalone invoice, both ways. */
    public function theStandaloneInvoiceSavesTheProductEitherControlNames(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        // Both products up front, before the first POST. A POST runs a real kernel request, and
        // Symfony's service resetter hands back a DIFFERENT EntityManager afterwards — an entity
        // built against that one is "new" to the Doctrine module's, which is what actually fails.
        $other = $this->makeOtherProduct($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $this->seeTheFormPosts($I, 'form#invoice-create-form', ['lines[0][product_id]']);
        $I->seeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $product->getId()));

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [0 => [
                'product_id' => (string) $product->getId(),
                'name' => '', 'location' => 'Main', 'sku' => 'SSPF-INV', 'qty' => '4',
                'unit' => 'EA', 'tax_code' => 'G', 'cost' => '30.55', 'price' => '65.25',
            ]],
            'save_mode' => 'draft',
        ]);

        $invoiceId = (int) $em->getConnection()->fetchOne('SELECT id FROM invoice ORDER BY id DESC LIMIT 1');
        $rows = $this->lineRows($I, 'invoice_line', 'invoice_id', $invoiceId);
        $I->assertCount(1, $rows, 'the invoice has exactly the one line that was posted');
        $I->assertSame(
            (int) $product->getId(),
            (int) $rows[0]['product_id'],
            'invoice_line.product_id is not the product the picker named',
        );

        // The no-JS half. Past the limit this screen names no product at all, so its select is
        // empty and the id box is the ONLY way a product can reach the save.
        $this->crossTheInlineLimit($I);
        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeElement('select[name="lines[0][product_id]"].js-only');
        $I->seeInSource('name="lines[0][product_id_manual]"');
        $I->dontSeeElement(sprintf('select[name="lines[0][product_id]"] option[value="%d"]', $other->getId()));

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [0 => [
                'product_id' => '',
                'product_id_manual' => (string) $other->getId(),
                'name' => '', 'location' => 'Main', 'sku' => 'SSPF-INV-NOJS', 'qty' => '4',
                'unit' => 'EA', 'tax_code' => 'E', 'cost' => '1.10', 'price' => '2.20',
            ]],
            'save_mode' => 'draft',
        ]);

        $newInvoiceId = (int) $em->getConnection()->fetchOne('SELECT id FROM invoice ORDER BY id DESC LIMIT 1');
        $I->assertNotSame($invoiceId, $newInvoiceId, 'the no-JS post raised a second invoice');
        $rows = $this->lineRows($I, 'invoice_line', 'invoice_id', $newInvoiceId);
        $I->assertCount(1, $rows, 'the second invoice has exactly the one line that was posted');
        $I->assertSame(
            (int) $other->getId(),
            (int) $rows[0]['product_id'],
            'invoice_line.product_id is not the product the no-JS id box named',
        );
        $I->assertSame('SSPF-INV-NOJS', (string) $rows[0]['sku'], 'the rest of the no-JS row saved too');
    }
}
