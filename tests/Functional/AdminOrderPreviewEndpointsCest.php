<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueCompanyAddress;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderAddress;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The order form's three preview endpoints answer about lines that have not been saved and may
 * never be. Since issue #165 step 6 they hydrate a transient SalesOrder and hand that to the
 * calculators, so no call site is left assembling calculator inputs by hand.
 *
 * That document must never reach the database. FeeRepository::ensureBySlug() and
 * CustomFieldDefinitionRepository::ensureBySlug() both flush() partway through a calculation, and
 * SalesOrder's lines and addresses cascade persist — so a stray persist() would write a whole order
 * from a GET request. Nothing in the code says so out loud, because what makes it safe is Doctrine
 * ignoring an object it was never given. These tests are that statement.
 */
final class AdminOrderPreviewEndpointsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-preview-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /** @return array{0: Company, 1: CompanyAddress, 2: ProductCore} */
    private function fixtures(FunctionalTester $I): array
    {
        $company = (new Company())
            ->setName('Order Preview Co')
            ->setCode('PREVIEW-' . uniqid());
        $I->haveInRepository($company);

        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel('Warehouse')
            ->setAddressLine1('1 Dock Road')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V5K0A1');
        $I->haveInRepository($address);

        $product = (new ProductCore())
            ->setSku('PREVIEW-SKU-' . uniqid())
            ->setName('Preview Widget')
            ->setDefaultPrice('25.00')
            ->setSalesTaxCode('G')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return [$company, $address, $product];
    }

    /**
     * @return array{orders: int, lines: int, addresses: int}
     */
    private function documentRowCounts(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();

        $count = static fn (string $class): int => (int) $em->getRepository($class)
            ->createQueryBuilder('r')->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return [
            'orders' => $count(SalesOrder::class),
            'lines' => $count(SalesOrderLine::class),
            'addresses' => $count(SalesOrderAddress::class),
        ];
    }

    public function theThreePreviewEndpointsAnswerWithoutWritingADocument(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $address, $product] = $this->fixtures($I);

        // One saved order, so the counts below are non-zero and a stray write is visible as a
        // change rather than as the difference between nothing and nothing.
        $existing = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('PREVIEW-EXISTING-' . uniqid());
        $existing->setShippingAddressFrom($address);
        $existing->addLine(
            (new SalesOrderLine())->setProduct($product)->setName('Preview Widget')
                ->setQuantity('1.00')->setPrice('25.00')->setSubtotal('25.00')->setTaxCode('G')
        );
        $I->haveInRepository($existing);
        // Live, not a draft — since #539 stage 2 that is approve() on the persisted order rather
        // than a status string. Done before the counts are taken, so it is part of the baseline.
        $existing->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $before = $this->documentRowCounts($I);

        $lines = json_encode([
            ['product_id' => $product->getId(), 'qty' => 4, 'subtotal' => 100.0, 'tax_code' => 'G'],
            // A typed blank row: no product, but it still carries money and must not shift the
            // per-line figures the browser maps back onto the form by position.
            ['product_id' => 0, 'qty' => 0, 'subtotal' => 30.0, 'tax_code' => 'G'],
        ]);
        $query = [
            'company_id' => (string) $company->getId(),
            'address_id' => (string) $address->getId(),
            'province' => 'BC',
            'shipping' => '15.00',
            'lines' => $lines,
        ];

        $I->haveHttpHeader('Host', 'admin.localhost');

        foreach (['/admin/order/shipping-options', '/admin/order/fee-lines', '/admin/order/tax-breakdown'] as $route) {
            $I->amOnPage($route . '?' . http_build_query($query));
            $I->seeResponseCodeIsSuccessful();
        }

        $I->assertSame($before, $this->documentRowCounts($I), 'a preview request wrote a document to the database');
    }

    public function theTaxBreakdownPreviewStillReturnsAFigurePerFormRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$company, $address, $product] = $this->fixtures($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/tax-breakdown?' . http_build_query([
            'company_id' => (string) $company->getId(),
            'address_id' => (string) $address->getId(),
            'province' => 'BC',
            'shipping' => '0',
            'lines' => json_encode([
                ['product_id' => 0, 'qty' => 0, 'subtotal' => 10.0, 'tax_code' => 'E'],
                ['product_id' => $product->getId(), 'qty' => 2, 'subtotal' => 50.0, 'tax_code' => 'G'],
            ]),
        ]));
        $I->seeResponseCodeIsSuccessful();

        $payload = json_decode($I->grabPageSource(), true);

        // Keyed by position, both rows present: the browser reads these back onto the form's rows
        // in order, so a dropped row would silently move every figure after it.
        $I->assertSame([0, 1], array_keys($payload['perLineTax']));
    }

    /**
     * #284: stale form state — the admin switches company on the order form while an address_id
     * from the previously-selected company is still in play — must not let the preview quote
     * against a different company's address. ArrangementShippingCalculator makes this a concrete,
     * dollar-value bug rather than a merely-plausible one: it grants a free "existing arrangement"
     * shipping option keyed on the address-book row's own id, with no company check of its own
     * (that guarantee is supposed to come from the caller never handing it a foreign address).
     */
    public function aForeignCompanysAddressIdDoesNotLeakItsShippingArrangementIntoThePreview(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$otherCompany, $otherAddress] = $this->fixtures($I);
        [$company, , $product] = $this->fixtures($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $definition = (new CustomFieldDefinition())
            ->setObjectType('company_address')
            ->setSlug('arrangement_shipping_eligible')
            ->setLabel('Arrangement shipping eligible')
            ->setFieldType('text');
        $em->persist($definition);
        $em->persist(
            (new CustomFieldValueCompanyAddress())->setDefinition($definition)->setCompanyAddress($otherAddress)->setValue('1')
        );
        $em->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/shipping-options?' . http_build_query([
            'company_id' => (string) $company->getId(),
            'address_id' => (string) $otherAddress->getId(),
            'province' => 'BC',
            'lines' => json_encode([
                ['product_id' => $product->getId(), 'qty' => 1, 'subtotal' => 25.0, 'tax_code' => 'G'],
            ]),
        ]));
        $I->seeResponseCodeIsSuccessful();

        $payload = json_decode($I->grabPageSource(), true);
        $labels = array_column($payload['options'], 'label');
        $I->assertNotContains(
            'Shipping Upon Existing Arrangement',
            $labels,
            'a mismatched company/address pair must not surface another company\'s free-shipping arrangement',
        );
    }
}
