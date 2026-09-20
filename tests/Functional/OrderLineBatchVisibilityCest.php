<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\TrackingPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The order line's Batch cell only offers its lot/serial capture UI when there is something to
 * capture (#670).
 *
 * Batch/lot (or serial) tracking is a per-PRODUCT attribute (TrackingPolicy, #573), not a line-row
 * default: a blank row has no product to track anything for, and a product row with no tracking
 * policy — or an explicit `none` one — has nothing to capture either. Before this fix every row,
 * blank or not, offered an editable date input regardless.
 *
 * Read off the rendered page rather than the entity layer: the claim is about what the ADMIN sees,
 * and `sales_line_row.html.twig`/app.js could agree with each other while still both disagreeing
 * with `OrderController::productTracksBatch()`.
 */
final class OrderLineBatchVisibilityCest
{
    private const REGION = 'Main';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-batch-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())->setName('Batch Visibility Co')->setCode('BV-' . uniqid());
        $I->haveInRepository($company);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => self::REGION]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName(self::REGION)->setStatus('Active');
            $I->haveInRepository($region);
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($region)->setStatus('Active'),
        );

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku, ?string $mode = null): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Batch test ' . $sku)
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        // Both persisted through the SAME grabbed manager, with one explicit flush at the end —
        // not $I->haveInRepository($product), which flushes through the Doctrine module's own
        // (separately cached) manager and would commit $product without ever having seen $policy
        // as a managed entity. Same reasoning as ReceivingCapturesTheIdentityCest::product().
        $entityManager = $I->grabService(EntityManagerInterface::class);

        if ($mode !== null) {
            $policy = (new TrackingPolicy())
                ->setName('Policy ' . $sku)
                ->setMode($mode)
                ->setTrackIn(true)
                ->setTrackOut(true);
            $entityManager->persist($policy);
            $product->setTrackingPolicy($policy);
        }

        $entityManager->persist($product);
        $entityManager->flush();

        $I->haveStockFor($product, 100, 'Main');

        return $product;
    }

    /**
     * Finds the batch cell's "+" button and its (possibly empty) `.js-batch-rows` container for the
     * line whose SKU box carries $sku, by walking the parsed DOM rather than a flat CSS selector —
     * there is no other way to scope "this row" without depending on line index, which the save is
     * free to reassign.
     *
     * @return array{addButtonHidden: bool, rowsContainerHidden: bool}
     */
    private function batchCellState(FunctionalTester $I, string $sku): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($I->grabPageSource());
        $xpath = new \DOMXPath($dom);

        $skuInputs = $xpath->query('//input[contains(@name, "[sku]")][@value="' . $sku . '"]');
        $I->assertGreaterThan(0, $skuInputs->length, 'no line renders SKU ' . $sku);

        $row = $skuInputs->item(0);
        while ($row !== null && !($row instanceof \DOMElement && $row->tagName === 'tr')) {
            $row = $row->parentNode;
        }
        $I->assertNotNull($row, 'the SKU box for ' . $sku . ' is not inside a <tr>');

        $addButtons = $xpath->query('.//button[contains(concat(" ", normalize-space(@class), " "), " js-batch-toggle ")]', $row);
        $rowsContainers = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " js-batch-rows ")]', $row);

        $I->assertSame(1, $addButtons->length, $sku . '\'s row does not carry exactly one batch add button');
        $I->assertSame(1, $rowsContainers->length, $sku . '\'s row does not carry exactly one batch rows container');

        return [
            'addButtonHidden' => $addButtons->item(0)->hasAttribute('hidden'),
            'rowsContainerHidden' => $rowsContainers->item(0)->hasAttribute('hidden'),
        ];
    }

    public function aProductWithNoTrackingPolicyHidesTheBatchUi(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'BV-NONE-' . uniqid());

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $state = $this->batchCellState($I, $product->getSku());
        $I->assertTrue($state['addButtonHidden'], 'an untracked product should not offer to add a batch row');
        $I->assertTrue($state['rowsContainerHidden'], 'an untracked product should not show the batch rows container');
    }

    public function aProductThatTracksLotsShowsTheBatchUi(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'BV-LOT-' . uniqid(), TrackingPolicy::MODE_LOT);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $state = $this->batchCellState($I, $product->getSku());
        $I->assertFalse($state['addButtonHidden'], 'a lot-tracked product should offer to add a batch row');
        $I->assertFalse($state['rowsContainerHidden'], 'a lot-tracked product should show the batch rows container');
    }

    /**
     * A stray batch value on a line whose product does not track lots/serials is legacy data from
     * before TrackingPolicy existed — the grandfathered "show it anyway" exception this test used
     * to assert was itself the placeholder, not the rule. The UI now goes purely off the product's
     * own tracking policy, same as every other case in this file: no policy, no capture UI,
     * regardless of what the line's `batch` column happens to hold.
     */
    public function anExistingBatchValueWithNoTrackingPolicyStaysHidden(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'BV-STALE-' . uniqid());

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);

        // Written straight onto the line, bypassing the form — the point is a line that already
        // holds data from before its product's policy (or the policy itself) existed at all.
        $order->getLines()->first()->setBatch('LOT-9 08/01/2026');
        $entityManager->flush();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $state = $this->batchCellState($I, $product->getSku());
        $I->assertTrue($state['rowsContainerHidden'], 'no tracking policy means no capture UI, even for a line that already has a stray batch value');
    }

    /** A blank line (no product at all) never offers the batch UI — there is nothing to track. */
    public function aBlankLineHidesTheBatchUi(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['name' => 'A soft-note line', 'qty' => '0', 'sku' => 'BV-BLANK-' . uniqid()],
            ],
            'save_mode' => 'order',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $order = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);
        $I->assertInstanceOf(SalesOrder::class, $order);
        $sku = $order->getLines()->first()->getSku();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $state = $this->batchCellState($I, (string) $sku);
        $I->assertTrue($state['addButtonHidden'], 'a blank line has no product and so nothing to track');
        $I->assertTrue($state['rowsContainerHidden'], 'a blank line has no product and so nothing to track');
    }
}
