<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * What a line denominated in a global term looks like to somebody OUTSIDE the company (#659, #624).
 *
 * `AdminUomLineEntryAndDisplayCest` conducts the entry and the admin documents. These are the four
 * surfaces that leave the building, and they are the ones most easily missed for exactly that
 * reason — nobody in the office opens them:
 *
 *  - the customer order detail page
 *  - the customer estimate detail page
 *  - the customer quote PDF
 *  - the order and quote summary partials the shipped emails include
 *
 * All four printed `CASE(12)` before #659 and print `BOX-12` after it. The emails are covered beside
 * the rest of their template in `SalesDocumentNotifierTest`; the three pages are here.
 *
 * ## Why the data is built through the entity rather than the order form
 *
 * The subject is the PRINTED PAGE, and the entry path that produces the row is already conducted end
 * to end against the real admin form in `AdminUomLineEntryAndDisplayCest`. What is used here is
 * `setEnteredQuantity()` — the one writer of the entered/unit/base triple, the same method the
 * controller calls — so the row under the assertion is the row the form would have written, and the
 * assertion is still on the CELL rather than on the page text.
 *
 * ## Every assertion names a cell
 *
 * Never `see('40')` (#627). This whole feature is numbers, and 40 appears in a price, a subtotal and
 * a product code on the same page; `see('40')` also matches 1400 and $240.00. Each cell is grabbed
 * by its column position and compared whole.
 *
 * ## The row that must not have changed
 *
 * Every case carries a second line entered in base units, and asserts it prints exactly what it
 * printed before any of this existed. A change that re-expressed every line rather than the one
 * naming a term would pass a one-line test.
 */
final class CustomerDocumentsPrintTheLineUnitCest
{
    /** 40 boxes of twelve at fifty cents a unit: 480 base units, $6.00 per box, $240.00 the line. */
    private const ENTERED = '40';
    private const FACTOR = '12';
    private const PER_BASE = '0.50';

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Outside The Building Co')
            ->setCode('CDU-' . strtoupper(substr(uniqid(), -6)))
            ->setPrimaryEmail('buyer@customer-uom.example');
        $I->haveInRepository($company);

        return $company;
    }

    private function loginAs(FunctionalTester $I, Company $company): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('cdu-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    private function unit(FunctionalTester $I, string $code, string $name, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }

        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName($name)
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision('1');
        $I->haveInRepository($unit);

        return $unit;
    }

    private function makeProduct(FunctionalTester $I, string $sku, UnitOfMeasure $base): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($sku . ' Widget')
            ->setUnit($base->getCode())
            ->setCostPrice('0.25')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setBaseUnit($base);
        $I->haveInRepository($product);

        return $product;
    }

    /**
     * An approved order with a boxed line and a base-unit control line.
     *
     * @return array{0: SalesOrder, 1: UnitOfMeasure}
     */
    private function orderWithABoxedLine(FunctionalTester $I, Company $company): array
    {
        $each = $this->unit($I, 'EA', 'Each', '1');
        $box = $this->unit($I, 'CDU-BOX-12', 'Box of 12', self::FACTOR);
        $product = $this->makeProduct($I, 'CDU-BOXED', $each);
        $control = $this->makeProduct($I, 'CDU-PLAIN', $each);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-CDU-' . uniqid())
            ->setSubtotal('262.75')
            ->setTax('0.00')
            ->setTotal('262.75');

        $boxed = (new SalesOrderLine())
            ->setName('Boxed Widget')
            ->setSku('CDU-BOXED')
            ->setProduct($product)
            ->setPrice(self::PER_BASE)
            ->setSubtotal('240.00');
        $boxed->setEnteredQuantity(self::ENTERED, $box, $each);
        $order->addLine($boxed);

        $plain = (new SalesOrderLine())
            ->setName('Plain Widget')
            ->setSku('CDU-PLAIN')
            ->setProduct($control)
            ->setQuantity('7.0000')
            ->setPrice('3.25')
            ->setSubtotal('22.75');
        $order->addLine($plain);

        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return [$order, $box];
    }

    /** The estimate equivalent, priced so the customer sees a quote rather than a TBD. */
    private function estimateWithABoxedLine(FunctionalTester $I, Company $company): Estimate
    {
        $each = $this->unit($I, 'EA', 'Each', '1');
        $box = $this->unit($I, 'CDU-BOX-12', 'Box of 12', self::FACTOR);
        $product = $this->makeProduct($I, 'CDU-Q-BOXED', $each);
        $control = $this->makeProduct($I, 'CDU-Q-PLAIN', $each);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-CDU-' . uniqid())
            ->setSource('Customer')
            ->setPoNumber('PO-CDU-1')
            ->setSubtotal('262.75')
            ->setTax('0.00')
            ->setTotal('262.75');
        $estimate->setStatus('Priced', DocumentActor::system());

        $boxed = (new EstimateLine())
            ->setName('Boxed Widget')
            ->setSku('CDU-Q-BOXED')
            ->setProduct($product)
            ->setCost('0.25')
            ->setPrice(self::PER_BASE)
            ->setSubtotal('240.00');
        $boxed->setEnteredQuantity(self::ENTERED, $box, $each);
        $estimate->addLine($boxed);

        $plain = (new EstimateLine())
            ->setName('Plain Widget')
            ->setSku('CDU-Q-PLAIN')
            ->setProduct($control)
            ->setQuantity('7.0000')
            ->setCost('1.00')
            ->setPrice('3.25')
            ->setSubtotal('22.75');
        $estimate->addLine($plain);

        $I->haveInRepository($estimate);
        $I->grabService(EntityManagerInterface::class)->flush();

        return $estimate;
    }

    /**
     * The customer's own order page prints the line in the term it was ordered in.
     *
     * The Quantity cell is the assertion that matters. It read `line.quantity` — the BASE figure —
     * while the U/M cell beside it read the line's term and the Price cell read the rate per that
     * term, so this line printed `480  CDU-BOX-12  $6.00  $240.00`: forty-eight times six is not two
     * hundred and forty, and nothing on the page said which of the two numbers was the lie. That is
     * a #644 defect rather than a #659 one — the cell is identical on main — found by conducting the
     * page rather than by reading it.
     */
    public function theCustomerOrderPagePrintsTheTermTheLineWasOrderedIn(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        [$order] = $this->orderWithABoxedLine($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $row = '.customer-order-items-table tbody tr:first-child ';
        $I->assertSame('CDU-BOX-12', trim($I->grabTextFrom($row . 'td:nth-child(2)')), 'U/M is the term the line names');
        $I->assertSame('40', trim($I->grabTextFrom($row . 'td:nth-child(5)')), 'the quantity is in that term, not in base units');
        $I->assertSame('$6.00', trim($I->grabTextFrom($row . 'td:nth-child(6)')), 'the price is per box, resolved from the stored per-EA rate');
        $I->assertSame('$240.00', trim($I->grabTextFrom($row . 'td:nth-child(7)')), '40 x $6.00 checks out on the face of the page');

        // The row that must not have changed: a base-unit line prints exactly as it always did.
        $control = '.customer-order-items-table tbody tr:nth-child(2) ';
        $I->assertSame('EA', trim($I->grabTextFrom($control . 'td:nth-child(2)')));
        $I->assertSame('7', trim($I->grabTextFrom($control . 'td:nth-child(5)')));
        $I->assertSame('$3.25', trim($I->grabTextFrom($control . 'td:nth-child(6)')));
        $I->assertSame('$22.75', trim($I->grabTextFrom($control . 'td:nth-child(7)')));

        // The bracketed pack size is gone from what the customer receives: a term carries its own
        // count now, so printing the ratio beside the code would state it twice.
        $I->dontSee('CASE(12)');
        $I->dontSee('(480 EA)');
    }

    /** The same three cells on the quote the customer is asked to accept. */
    public function theCustomerQuotePagePrintsTheTermTheLineWasQuotedIn(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->estimateWithABoxedLine($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $row = '.customer-order-items-table tbody tr:first-child ';
        $I->assertSame('CDU-BOX-12', trim($I->grabTextFrom($row . 'td:nth-child(2)')));
        $I->assertSame('40', trim($I->grabTextFrom($row . 'td:nth-child(5)')));
        $I->assertSame('$6.00', trim($I->grabTextFrom($row . 'td:nth-child(6)')));
        $I->assertSame('$240.00', trim($I->grabTextFrom($row . 'td:nth-child(7)')));

        $control = '.customer-order-items-table tbody tr:nth-child(2) ';
        $I->assertSame('EA', trim($I->grabTextFrom($control . 'td:nth-child(2)')));
        $I->assertSame('7', trim($I->grabTextFrom($control . 'td:nth-child(5)')));
        $I->assertSame('$3.25', trim($I->grabTextFrom($control . 'td:nth-child(6)')));

        $I->dontSee('CASE(12)');
    }

    /**
     * The quote PDF renders at all.
     *
     * Asserted as a PDF rather than as cells because `quote_pdf.html.twig` goes through Dompdf and
     * comes back as a binary stream — the cell text is not addressable on the other side. What it
     * therefore proves is the thing that was actually at risk: the template reads `unitOfMeasure`
     * and `displayUnitLabel`, and a template still reaching for the retired `packagingUnit` would
     * throw on render rather than print the wrong number. Nothing else in the suite opens this
     * route with a line that names a term.
     */
    public function theCustomerQuotePdfStillRenders(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->estimateWithABoxedLine($I, $company);
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates/' . $estimate->getId() . '/quote');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource());
    }
}
