<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Enum\SalesOrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * What acceptance does when the quote cannot actually be filled.
 *
 * Acceptance IS conversion — one path, EstimateConversionService::convert(), reached only from the
 * customer's own accept action. It used to mint a live order unconditionally, and a live order
 * reserves: a customer accepting a quote for 500 units against 5 in stock created a live order for
 * 500 and drove availability to -495, which then made the SKU unbuyable for every other customer.
 * That is the #326 defect arriving through the one path #326 never covered, since it goes nowhere
 * near the admin order form.
 *
 * Since #539 stage 2 "live" means Invoiced rather than Pending: convert() approves the order and
 * raises its shadow invoice for the whole of it, so the deriver has nothing left uninvoiced and
 * nothing paid. Held-for-stock still means Draft, unchanged — it was never approved, so no
 * derivation moves it and its invoice stays a Draft too.
 *
 * Refusing the acceptance was the alternative and is the wrong trade: the customer has agreed to a
 * price we quoted them, and telling them they cannot accept it — with nothing they can do about it
 * — is a worse failure than a slow order.
 *
 * So the acceptance always succeeds. The quote becomes Accepted, the order is created, the customer
 * is told it is placed. Only the ORDER waits: it is held as a Draft, which reserves nothing, and an
 * admin is emailed. Promoting it later runs into AdminOrderStockValidator, which is where the
 * oversell is actually refused — so nothing new had to be invented to stop it happening by hand.
 */
final class QuoteAcceptanceStockShortfallCest
{
    private const REGION = 'Acceptance Stock Region';

    /**
     * A second region, stocked in the opposite direction to the one under test, so no assertion here
     * can be satisfied by reading the wrong one. A single-region fixture would let a region-blind
     * build pass everything in this file.
     */
    private const REGION_OTHER = 'Acceptance Other Region';

    private function makeCompanyAndCustomer(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Acceptance Stock Co')
            ->setCode('ACCSTOCK-' . uniqid());
        $I->haveInRepository($company);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        foreach ([self::REGION, self::REGION_OTHER] as $name) {
            $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
            if (!$region instanceof FulfillmentRegion) {
                $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
                $I->haveInRepository($region);
                // The region has to have somewhere to draw stock from (#546).
                $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
                $entityManager->flush();
            }
            $I->haveInRepository(
                (new CompanyFulfillmentRegion())
                    ->setCompany($company)
                    ->setFulfillmentRegion($region)
                    ->setStatus('Active')
            );
        }

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('accstock-' . uniqid() . '@example.test')
            ->setFirstName('Ada')
            ->setLastName('Buyer')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);
        $I->amLoggedInAs($customer, 'main');

        return $company;
    }

    private function makeProduct(FunctionalTester $I, int $stockHere, int $stockOther): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('ACCSTOCK-SKU-' . uniqid())
            ->setName('Acceptance Stock Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveStockFor($product, $stockHere, self::REGION);
        $I->haveStockFor($product, $stockOther, self::REGION_OTHER);

        return $product;
    }

    /** A Priced, fully priced quote — the only state acceptance allows. */
    private function makePricedQuote(FunctionalTester $I, Company $company, ProductCore $product, string $qty): Estimate
    {
        $lineTotal = (float) $qty * 10.0;

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('ACCSTOCK-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal((string) $lineTotal)
            ->setTax('0.00')
            ->setTotal((string) $lineTotal);
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION)
                ->setQuantity($qty)
                ->setPrice('10.00')
                ->setSubtotal((string) $lineTotal)
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function accept(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->sendFormPostRequest('/estimates/' . $estimate->getId() . '/accept', [
            '_token' => $I->csrfToken(),
        ]);
    }

    private function availability(FunctionalTester $I, ProductCore $product, string $regionName = self::REGION): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $regionName]);
        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $entityManager->find(ProductCore::class, $product->getId()),
            'warehouse' => $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegion($region),
        ]);

        return $inventory?->getAvailableQuantity() ?? 0;
    }

    private function reloadOrderFor(FunctionalTester $I, Company $company): ?SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company->getId()]);
    }

    /**
     * The order was accepted rather than held back for stock.
     *
     * Invoiced, exactly: convert() approves it (there is no shortfall) and raises its shadow invoice
     * for every ordered unit, leaving nothing uninvoiced and nothing paid — which is what the #539
     * stage 2 deriver reads. Both halves are asserted rather than only "not Draft", because an order
     * stuck at Approved would mean the shadow invoice never counted, and an order at Partially
     * Invoiced would mean it billed less than the quote.
     */
    private function assertLiveRatherThanHeld(FunctionalTester $I, SalesOrder $order): void
    {
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $order->getStatus(),
            'a fillable quote must convert into a live order, not one held back',
        );
        $I->assertNotSame(SalesOrderStatus::Draft->value, $order->getStatus());
        $I->assertTrue($order->hasCountingInvoices(), 'the shadow invoice must be issued, not left a draft');
    }

    // ---------------------------------------------------------------- short of stock

    /**
     * The headline case, and the whole reason this exists: the acceptance succeeds, the order is
     * created, and availability is NOT driven negative.
     */
    public function acceptingAQuoteBeyondStockSucceedsAndHoldsTheOrderAsDraft(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 5, 9999);
        $estimate = $this->makePricedQuote($I, $company, $product, '500.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $I->assertNotNull($order, 'the acceptance must never be refused');
        $I->assertSame(SalesOrderStatus::Draft->value, $order->getStatus(), 'an unfillable order must not be minted live');
        // And its shadow invoice is held back with it. A live invoice under a held order would bill
        // — and from stage 3 hold stock for — goods that are not there, which is the whole thing
        // this test exists to prevent.
        $I->assertFalse($order->hasCountingInvoices(), 'a held order must not raise a live invoice');
        // The point of all of it: 5, not -495.
        $I->assertSame(5, $this->availability($I, $product));
    }

    /** And the customer's side of it still reads as a success, because it is one. */
    public function theCustomerIsToldTheOrderWasCreated(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 5, 9999);
        $estimate = $this->makePricedQuote($I, $company, $product, '500.00');

        $this->accept($I, $estimate);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame('Accepted', $saved->getStatus(), 'the quote must still be accepted');
        $I->assertNotNull($saved->getConvertedOrder());
    }

    /** Why it is a Draft has to survive on the documents, not only in an email that may not arrive. */
    public function bothDocumentsRecordWhyTheOrderIsHeld(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 5, 9999);
        $estimate = $this->makePricedQuote($I, $company, $product, '500.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $orderComments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $entityManager->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document'],
            ),
        );
        $I->assertNotEmpty(
            array_filter($orderComments, static fn (string $c): bool => str_contains($c, 'Held as Draft')),
            'the order should say why it is a draft'
        );
        // The arithmetic, not just the fact.
        $I->assertNotEmpty(
            array_filter($orderComments, static fn (string $c): bool => str_contains($c, 'short 495')),
            'the order log should name the shortfall'
        );

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $quoteComments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $entityManager->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'Estimate', 'entityId' => $estimate->getId(), 'actorType' => 'document'],
            ),
        );
        $I->assertNotEmpty(
            array_filter($quoteComments, static fn (string $c): bool => str_contains($c, 'held as Draft')),
            'the quote should say what happened to its order'
        );
    }

    // ---------------------------------------------------------------- enough stock

    /** The ordinary path is untouched: enough stock still produces a live, reserving order. */
    public function acceptingAQuoteWithinStockStillProducesALiveOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 50, 0);
        $estimate = $this->makePricedQuote($I, $company, $product, '5.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $I->assertNotNull($order);
        $this->assertLiveRatherThanHeld($I, $order);
        // A live order reserves, so the five are now held.
        $I->assertSame(45, $this->availability($I, $product));
    }

    /** Exactly enough is enough — the boundary is not off by one. */
    public function acceptingAQuoteForExactlyTheAvailableQuantityIsAcceptedLive(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 5, 0);
        $estimate = $this->makePricedQuote($I, $company, $product, '5.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $I->assertNotNull($order);
        $this->assertLiveRatherThanHeld($I, $order);
        $I->assertSame(0, $this->availability($I, $product));
    }

    /** One over is not. */
    public function acceptingAQuoteForOneMoreThanAvailableIsHeld(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        $product = $this->makeProduct($I, 5, 0);
        $estimate = $this->makePricedQuote($I, $company, $product, '6.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $I->assertSame(SalesOrderStatus::Draft->value, $order->getStatus());
        $I->assertSame(5, $this->availability($I, $product));
    }

    // ---------------------------------------------------------------- the right region

    /**
     * The line's region decides it, not the other one. Here the line's region is empty while the
     * other holds plenty — a region-blind check would let this through as a live order.
     */
    public function theShortfallIsJudgedAgainstTheLinesOwnRegion(FunctionalTester $I): void
    {
        $company = $this->makeCompanyAndCustomer($I);
        // Nothing where it ships from, thousands in the region it does not.
        $product = $this->makeProduct($I, 0, 9999);
        $estimate = $this->makePricedQuote($I, $company, $product, '10.00');

        $this->accept($I, $estimate);

        $order = $this->reloadOrderFor($I, $company);
        $I->assertSame(SalesOrderStatus::Draft->value, $order->getStatus(), 'stock in another region must not count');
        // And the untouched region is genuinely untouched.
        $I->assertSame(9999, $this->availability($I, $product, self::REGION_OTHER));
    }
}
