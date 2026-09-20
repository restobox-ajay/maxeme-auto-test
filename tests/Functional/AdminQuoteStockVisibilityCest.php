<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * What the admin quote form says about stock (#327).
 *
 * A quote reserves nothing, at any status, and not by configuration: the reconciliation subscriber
 * reacts to SalesOrder and SalesOrderLine and has never heard of Estimate. Inventory is touched for
 * the first time when an accepted quote becomes an order. So a quote can promise any quantity of
 * anything indefinitely — which is correct, and is also exactly why an admin needs telling.
 *
 * Purely visibility. Unlike #326 on the order side, nothing here refuses a save: a quote is not a
 * commitment, and being able to quote more than is on the shelf is the point.
 *
 * The figure shown is the same one the ORDER form shows and enforces, from the same service, because
 * this quantity becomes an order line the moment the quote is accepted — and that is where it will
 * be refused if it does not fit.
 *
 * It is worded "N available" on both forms since the line row became one shared partial
 * (templates/admin/_partials/sales_line_row.html.twig). The quote form used to say "N in stock" for
 * the identical figure: it is availability with this document's own holds netted back in, not what
 * is physically on a shelf, and "in stock" overstated it. One figure, one wording.
 */
final class AdminQuoteStockVisibilityCest
{
    /** The region the quotes below actually use. */
    private const REGION = 'Quote Stock Region';

    /**
     * Two decoys, stocked deliberately unlike REGION so no assertion here can be satisfied by the
     * wrong one. With a single region in the database every lookup finds the only row there is, and
     * a build that ignored regions entirely would pass every test in this file.
     *
     * RICH holds far more, so reading it would show a figure that flatters the quote. BARE holds
     * none, so reading it would show 0 where stock exists. Both are on the same company, and they
     * sort either side of REGION so ordering cannot accidentally pick correctly.
     */
    private const REGION_RICH = 'AAA Quote Rich Decoy';
    private const REGION_BARE = 'ZZZ Quote Bare Decoy';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-quote-stock-visibility@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Quote Stock Co')
            ->setCode('QSTOCK-' . uniqid());
        $I->haveInRepository($company);

        foreach ([self::REGION_RICH, self::REGION, self::REGION_BARE] as $name) {
            $entityManager = $I->grabService(EntityManagerInterface::class);
            $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
            if (!$region instanceof FulfillmentRegion) {
                $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
                $I->haveInRepository($region);
                // Every region has the warehouse serving it (#546) — a region with none is a
                // state no application path can produce, and stock resolves through the pair.
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

        return $company;
    }

    /**
     * $quantity in REGION, and deliberately different figures in the two decoys — so a figure that
     * matches an assertion below can only have come from the line's own region.
     */
    private function makeProductWithStock(FunctionalTester $I, int $quantity): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('QSTOCK-SKU-' . uniqid())
            ->setName('Quote Stock Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveStockFor($product, $quantity, self::REGION);
        $I->haveStockFor($product, $quantity + 1000, self::REGION_RICH);
        $I->haveStockFor($product, 0, self::REGION_BARE);

        return $product;
    }

    private function makeQuote(FunctionalTester $I, Company $company, ProductCore $product, string $qty): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QSTOCK-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal('10.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION)
                ->setQuantity($qty)
                ->setPrice('10.00')
                ->setSubtotal('10.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function theQuoteFormShowsStockOnHandBesideEachLineQuantity(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 42);
        $estimate = $this->makeQuote($I, $company, $product, '2.00');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        // Read out of the hint element, not off the page (#627). see('42 available') is a substring
        // match over the whole document, and '1042 available' contains '42 available' — proved by
        // mutation: rendering `available + 1000` in the hint left all eight tests in this file
        // green, so every stock figure on the quote form could have been wrong.
        $I->assertSame(
            ['42 available'],
            array_map(trim(...), $I->grabMultiple('.line-stock-hint')),
            'product_inventory.quantity 42 for the line region, one hint on the one line',
        );
    }

    public function theQuoteFormSaysQuotesHoldNoStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 42);
        $estimate = $this->makeQuote($I, $company, $product, '2.00');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('.quote-stock-notice');
        $I->see('Quotes do NOT hold any stock until it is converted into an order.');
    }

    /**
     * The rule the notice is describing, proved rather than asserted: a quote for more than exists
     * saves, and leaves availability untouched. If a quote ever started reserving, this fails and
     * the notice becomes a lie.
     */
    public function aQuoteBeyondStockSavesAndReservesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 5);
        $estimate = $this->makeQuote($I, $company, $product, '1.00');
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'location' => self::REGION, 'qty' => '500', 'price' => '10.00'],
            ],
            'action' => 'save',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $I->assertSame(500.0, (float) $saved->getLines()->first()->getQuantity(), 'a quote must be allowed to exceed stock');

        // And the shelf is untouched: 5, not -495. Read out of the cell (#627) — see('5 available')
        // is satisfied by '15 available', '45 available' and '1005 available' alike.
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame(
            ['5 available'],
            array_map(trim(...), $I->grabMultiple('.line-stock-hint')),
            'product_inventory.quantity still 5 after a quote for 500',
        );
    }

    /**
     * The figure shown belongs to the LINE's region, not the quote header's and not whichever
     * region happens to be first. Without this, a build that ignored regions would satisfy every
     * other assertion in this file.
     */
    public function theStockShownIsForTheLinesOwnRegion(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 42);

        // Header points at the 42-unit region; the line ships from the empty one.
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QSTOCK-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal('10.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION_BARE)
                ->setQuantity('1.00')
                ->setPrice('10.00')
                ->setSubtotal('10.00')
        );
        $I->haveInRepository($estimate);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // The header's region and the rich decoy must not be what is reported. Read out of the
        // hint (#627): see('0 available') is satisfied by '10 available' and '40 available' too.
        $I->assertSame(
            ['0 available'],
            array_map(trim(...), $I->grabMultiple('.line-stock-hint')),
            'the empty region the LINE ships from, not the 42 the quote header points at',
        );
    }

    /** Two lines, two regions, two different figures — one per line, not one for the document. */
    public function eachLineReportsItsOwnRegionsStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 42);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QSTOCK-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal('20.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        foreach ([self::REGION, self::REGION_RICH] as $location) {
            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setLocation($location)
                    ->setQuantity('1.00')
                    ->setPrice('10.00')
                    ->setSubtotal('10.00')
            );
        }
        $I->haveInRepository($estimate);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        // One hint per line, in line order, each read out of its own element (#627). The pair was
        // asserted with see('42 available') + see('1042 available'), and '1042 available' contains
        // '42 available' — so both hints rendering 1042 satisfied both calls and the count check.
        $I->assertSame(
            ['42 available', '1042 available'],
            array_map(trim(...), $I->grabMultiple('.line-stock-hint')),
            'line 1 ships from the 42-unit region, line 2 from the 1042-unit one',
        );
    }

    /** Under the ceiling reads plain; over it goes amber, the same signal the order form gives. */
    public function theStockFigureIsColouredOnlyWhenTheQuantityExceedsIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 10);

        $within = $this->makeQuote($I, $company, $product, '10.00');
        $I->amOnPage('/admin/estimate/edit/' . $within->getId());
        $I->seeElement('.line-stock-hint');
        $I->dontSeeElement('.line-stock-hint.line-stock-hint-over');

        $over = $this->makeQuote($I, $company, $product, '11.00');
        $I->amOnPage('/admin/estimate/edit/' . $over->getId());
        $I->seeElement('.line-stock-hint.line-stock-hint-over');
    }

    /** A line with no product has no stock figure rather than a misleading zero. */
    public function aLineWithNoProductShowsNoStockFigure(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QSTOCK-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion(self::REGION)
            ->setSubtotal('10.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Crating and handling')
                ->setLocation(self::REGION)
                ->setQuantity('1.00')
                ->setPrice('10.00')
                ->setSubtotal('10.00')
        );
        $I->haveInRepository($estimate);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.line-stock-hint');
        // The notice is about the document, not a line, so it is there regardless.
        $I->seeElement('.quote-stock-notice');
    }

    /** An unstocked product reads as zero, matching the order side rather than showing nothing. */
    public function aProductWithNoInventoryRowShowsZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);

        $product = (new ProductCore())
            ->setSku('QSTOCK-SKU-NONE-' . uniqid())
            ->setName('Never Stocked Quote Widget')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $estimate = $this->makeQuote($I, $company, $product, '3.00');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame(
            ['0 available'],
            array_map(trim(...), $I->grabMultiple('.line-stock-hint')),
            'no product_inventory row reads 0, not blank and not a stray figure from elsewhere',
        );
    }
}
