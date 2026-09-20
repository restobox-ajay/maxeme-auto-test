<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The order's fulfillment region: preserved, required where it can be, refused where it cannot (#237).
 *
 * The region decides the company's price list (OrderController::orderProductRows()), so getting it
 * wrong is a money bug rather than a cosmetic one.
 *
 * Three rules, one field:
 *
 * ABSENT MEANS UNCHANGED. When the company has no active regions the form renders a nameless
 * disabled input in place of the select, so nothing posts — and the unguarded setter this replaces
 * read that as "clear it", wiping the region of an order placed long before the company's regions
 * were deactivated. Silent: the save reported success.
 *
 * PRESENT MEANS REQUIRED. Where the company has active regions a region is genuinely required,
 * refused server-side. The form's `required` attribute is a courtesy on top of that — it was pure
 * decoration while the order form carried novalidate, and #248 removed that attribute so the
 * browser now asserts the same rule the server enforces. Every POST below skips the browser, so
 * none of it depends on the attribute either way.
 *
 * AND CREATE IS REFUSED when the company has no active region at all: there is no price list, so
 * orderProductRows() would fall through to the product default and price the order off a list
 * nobody negotiated. Edit is deliberately NOT refused — an order already placed has to stay
 * correctable.
 */
final class AdminOrderFulfillmentRegionCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('order-fulfillment-region@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Region Rules Co')
            ->setCode('AOFR-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    /** Assigns $name to $company, active or not. Returns the region's name. */
    private function assignRegion(FunctionalTester $I, Company $company, string $name, string $status = 'Active'): string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
            $I->haveInRepository($region);
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus($status)
        );

        // Orders here are Approved, and since #326 a save into a reserving status is refused unless
        // the line is covered by stock IN THE REGION THE LINE RESOLVES TO. These tests move orders
        // between several named regions, so the stock follows the region rather than being guessed
        // per product. Nothing here is about scarcity.
        $I->haveStockInRegionForAllProducts($name);

        return $name;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('AOFR-SKU-' . uniqid())
            ->setName('Region Rules Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        return $product;
    }

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product, ?string $region): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('AOFR-' . uniqid())
            ->setFulfillmentRegion($region)
            ->setSubtotal('100.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
                ->setTaxCode('E')
        );
        $I->haveInRepository($order);
        // #539 stage 2: an already-placed order is an approved one, and approving is an action on a
        // persisted Draft rather than a status assignment. No invoices, so it settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function savePost(FunctionalTester $I, Company $company, ProductCore $product, array $extra = []): array
    {
        return array_merge([
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'draft_recalc',
        ], $extra);
    }

    private function reload(FunctionalTester $I, int $id): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(SalesOrder::class, $id);
    }

    // ------------------------------------------------ absent means unchanged

    /**
     * The original bug. The company's regions are deactivated after the order was placed, so the
     * form renders a nameless disabled input, nothing posts, and the region used to be wiped.
     */
    public function aSaveWithNoRegionFieldKeepsTheStoredRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR Retired', 'Inactive');
        $order = $this->makeOrder($I, $company, $product, 'AOFR Retired');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'po_number' => 'PO-KEEP-ME',
        ]));

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('AOFR Retired', $saved->getFulfillmentRegion());
        // And the order stayed editable — the whole reason edit is not refused.
        $I->assertSame('PO-KEEP-ME', $saved->getPoNumber());
    }

    /**
     * The same rule where it is load-bearing on its own: the company DOES have active regions, so
     * the "no regions" branch cannot be what saves the value — only the absent-field guard can.
     *
     * Absent and blank are different things. A POST that never carried the field (an API client, a
     * partial save, a form rendered before the select existed) leaves the region alone; a POST that
     * carries it blank is an admin clearing a required box and is refused, which the next test pins.
     */
    public function aSaveOmittingTheFieldKeepsTheRegionEvenWhenTheCompanyHasActiveOnes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');
        // Two active regions, so the single-region fallback cannot be what preserves the value —
        // only the absent-field guard can.
        $this->assignRegion($I, $company, 'AOFR East');
        $order = $this->makeOrder($I, $company, $product, 'AOFR East');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // No fulfillment_region key at all.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'po_number' => 'PO-ABSENT-FIELD',
        ]));

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('AOFR East', $saved->getFulfillmentRegion());
        $I->assertSame('PO-ABSENT-FIELD', $saved->getPoNumber());
    }

    // ------------------------------------------------ required where pickable

    public function aBlankRegionIsRefusedWhenTheCompanyHasActiveRegions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');
        // Two, so a blank has no unambiguous answer to fall back on.
        $this->assignRegion($I, $company, 'AOFR East');
        $order = $this->makeOrder($I, $company, $product, 'AOFR West');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'fulfillment_region' => '',
        ]));

        $I->see('Choose a fulfillment region before saving');
        // Refused at the door: the order is untouched, not half-written.
        $I->assertSame('AOFR West', $this->reload($I, (int) $order->getId())->getFulfillmentRegion());
    }

    /** `required` must check the VALUE, not just that the box was non-empty. */
    public function aRegionTheCompanyDoesNotHaveIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');
        $order = $this->makeOrder($I, $company, $product, 'AOFR West');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'fulfillment_region' => 'Some Region This Company Never Had',
        ]));

        $I->see('is not a fulfillment region this company is set up for');
        $I->assertSame('AOFR West', $this->reload($I, (int) $order->getId())->getFulfillmentRegion());
    }

    public function aRegionTheCompanyDoesHaveIsStored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');
        $this->assignRegion($I, $company, 'AOFR East');
        $order = $this->makeOrder($I, $company, $product, 'AOFR West');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'fulfillment_region' => 'AOFR East',
        ]));

        $I->assertSame('AOFR East', $this->reload($I, (int) $order->getId())->getFulfillmentRegion());
    }

    // ------------------------------------------------ stale region

    /**
     * The case where "required" and "keep the status quo" would otherwise collide: the order's
     * region is retired but the company still has others. The stale value is offered and selected,
     * so the order saves unchanged instead of forcing a re-region before a PO number can be fixed.
     */
    public function aStaleRegionIsOfferedSelectedAndSurvivesASave(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR Retired', 'Inactive');
        $this->assignRegion($I, $company, 'AOFR West');
        $order = $this->makeOrder($I, $company, $product, 'AOFR Retired');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('select[name="fulfillment_region"] option[selected]', ['value' => 'AOFR Retired']);
        $I->see('is no longer configured for this customer');
        $I->see('This order can be saved as usual');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'fulfillment_region' => 'AOFR Retired',
            'po_number' => 'PO-STALE-OK',
        ]));

        $saved = $this->reload($I, (int) $order->getId());
        $I->assertSame('AOFR Retired', $saved->getFulfillmentRegion());
        $I->assertSame('PO-STALE-OK', $saved->getPoNumber());
    }

    /** A live region raises no warning — a legal state must not look like a problem. */
    public function aLiveRegionShowsNoStaleWarning(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');
        $order = $this->makeOrder($I, $company, $product, 'AOFR West');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->dontSee('is no longer configured for this customer');
    }

    // ------------------------------------------------ create is refused

    public function creatingAnOrderIsRefusedWhenTheCompanyHasNoActiveRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR Retired', 'Inactive');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product));

        $I->see('has no active fulfillment region');
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull(
            $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]),
            'an order was created for a company with no active region'
        );
    }

    /**
     * create() mints the order, so it is the one path where a wrong region has no earlier correct
     * value to fall back on — and it used to read the field raw, skipping both the requirement and
     * the value check that edit() enforced.
     */
    public function creatingAnOrderIsRefusedWhenTheRegionIsOmittedAltogether(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');

        // TWO active regions, so there is no unambiguous answer to fall back on. With exactly one
        // the server picks it, matching what the form itself pre-selects and posts.
        $this->assignRegion($I, $company, 'AOFR East');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        // No fulfillment_region key at all. There is nothing stored to leave unchanged, so the
        // requirement applies to the empty result.
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product));

        $I->see('Choose a fulfillment region before saving');
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
    }

    public function creatingAnOrderIsRefusedWhenTheRegionIsNotOneTheCompanyHas(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product, [
            'fulfillment_region' => 'Region From Another Company',
        ]));

        $I->see('is not a fulfillment region this company is set up for');
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertNull($entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]));
    }

    public function creatingAnOrderIsAllowedWhenTheCompanyHasAnActiveRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR West');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->savePost($I, $company, $product, [
            'fulfillment_region' => 'AOFR West',
        ]));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $created = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertNotNull($created);
        $I->assertSame('AOFR West', $created->getFulfillmentRegion());
    }

    /** Editing is NOT refused for the same company — that is the whole point of the split. */
    public function editingIsStillAllowedWhenTheCompanyHasNoActiveRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $this->assignRegion($I, $company, 'AOFR Retired', 'Inactive');
        $order = $this->makeOrder($I, $company, $product, 'AOFR Retired');

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('has no active fulfillment region, so there is no price list');

        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), $this->savePost($I, $company, $product, [
            'po_number' => 'PO-EDIT-OK',
        ]));

        $I->assertSame('PO-EDIT-OK', $this->reload($I, (int) $order->getId())->getPoNumber());
    }
}
