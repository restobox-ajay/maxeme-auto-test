<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The full "shipping was TBD" cycle, driven through every real screen it touches: a customer's
 * request comes in priced on every line but with no shipping figure at all, an admin fills that
 * in, the customer approves what is now a fully priced quote — minting the Sales Order and the
 * Invoice that bills it in the same click — and an admin records the payment against it.
 *
 * One test, five screens, asserted on the database column at every step rather than on what a
 * page happens to say (#624): a screen that renders "Priced" proves nothing about whether the
 * estimate's own status column agrees.
 */
final class OrderShippingTbdToPaymentCest
{
    private const REGION = 'TBD Shipping Region';

    /** The admin logs back in for the payment step — created once, reused the second time. */
    private ?AdminUser $admin = null;

    public function _before(FunctionalTester $I): void
    {
        $this->admin = null;
    }

    public function theFullCycleFromShippingTbdThroughApprovalToPayment(FunctionalTester $I): void
    {
        [$company, $product, $estimate] = $this->customerRequestWithShippingTbd($I);

        // --- Step 1: the request arrived priced on its one line, with shipping unresolved -------
        $this->loginAsAdmin($I);
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('TBD');
        $I->assertFalse($this->reloadEstimate($I, (int) $estimate->getId())->isFullyPriced(), 'guard: shipping is genuinely unresolved');

        // --- Step 2: admin prices the shipping, through the real edit screen --------------------
        $line = $estimate->getLines()->first();
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendAjaxPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'product_id' => (string) $product->getId(), 'qty' => '5', 'price' => '20.00'],
            ],
            'charge_lines_present' => '1',
            'charge_lines' => [['label' => 'Custom Shipping', 'amount' => '15.00', 'type' => 'shipping']],
            'action' => 'save',
        ]);

        $priced = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame('Priced', $priced->getStatus(), 'filling in the last unresolved figure fully prices the quote on its own');
        $I->assertNotNull($priced->getShippingTotal(), 'the shipping row is on it now');
        $I->assertSame(15.0, $priced->getShippingTotal());
        $I->assertTrue($priced->isFullyPriced());

        // --- Step 3: the customer approves — one click mints the order AND the invoice ----------
        $this->loginAsCustomer($I, $company);
        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('TBD', '.is-total');
        $I->sendFormPostRequest('/estimates/' . $estimate->getId() . '/accept', [
            '_token' => $I->csrfToken(),
        ]);

        $accepted = $this->reloadEstimate($I, (int) $estimate->getId());
        $I->assertSame('Accepted', $accepted->getStatus());
        $I->assertNotNull($accepted->getConvertedOrder(), 'accepting a customer quote mints its order in the same click');
        $orderId = $accepted->getConvertedOrder()->getId();

        $order = $this->reloadOrder($I, $orderId);
        $I->assertCount(1, $order->getInvoices(), 'the same click bills the order it just raised');
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $order->getStatus(),
            'billed in full at creation, so the order already derives to Invoiced — just unpaid',
        );

        /** @var Invoice $invoice */
        $invoice = $order->getInvoices()->first();
        $invoiceId = $invoice->getId();
        // Numeric, not string: SQLite hands a decimal column back without its trailing zeros.
        $I->assertSame(100.0, (float) $invoice->getSubtotal(), '5 units at $20.00');
        $I->assertSame(15.0, $invoice->getShippingTotal(), 'the shipping figure the admin typed carried onto the invoice');
        $invoiceTotal = $invoice->getTotal();

        // --- Step 4: admin records the payment ----------------------------------------------------
        $this->loginAsAdmin($I);
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Not Paid');

        $token = $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => '',
            'received_at' => '2026-09-13',
            'method' => 'Bank Transfer',
            'amount' => $invoiceTotal,
            'comment' => 'Paid in full once shipping was finally on the books.',
        ]);

        $paidInvoice = $this->reloadInvoice($I, $invoiceId);
        $I->assertSame(InvoicePaymentStatus::Paid, $paidInvoice->getPaymentStatus());
        $I->assertSame('0.00', $paidInvoice->getBalance());

        $I->assertSame(
            SalesOrderStatus::Closed->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            'the order was never written by the payments screen — its status derives from the invoice, whose own derives from its payment rows',
        );
    }

    // ------------------------------------------------------------------------------------ fixture

    /**
     * A customer's request for one already-priced product, with no shipping charge at all — the
     * only thing standing between this and a real order.
     *
     * Built directly, the same way AdminQuoteAcceptAndConvertCest's own customer-path fixture is:
     * this file is about the pricing/approval/payment cycle, not about re-proving the cart/checkout
     * screen that decides an unpriced LINE becomes a quote (CustomerCheckoutOrderFirstCest already
     * does that, for a different reason entirely — a product with no catalogue price, not shipping).
     *
     * @return array{0: Company, 1: ProductCore, 2: Estimate}
     */
    private function customerRequestWithShippingTbd(FunctionalTester $I): array
    {
        $company = (new Company())
            ->setName('Shipping TBD Wholesale')
            ->setCode('TBDSHIP-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company, self::REGION);

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Main')
                ->setAddressLine1('88 Freight Lane')
                ->setCity('Calgary')
                ->setProvince('AB')
                ->setPostalCode('T2P0A1')
                ->setCountry('CA')
                ->setIsDefaultShipping(true)
                ->setIsDefaultBilling(true),
        );

        $product = (new ProductCore())
            ->setSku('TBDSHIP-SKU-' . uniqid())
            ->setName('Shipping TBD Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('8.00')
            ->setDefaultPrice('20.00')
            ->setOriginalPrice('20.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product, 1000, self::REGION);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('TBDSHIP-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION);
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setLocation(self::REGION)
                ->setQuantity('5.00')
                ->setPrice('20.00')
                ->setSubtotal('100.00'),
        );
        // No fee lines at all — see AbstractSalesDocument::getShippingTotal()'s own docblock: null
        // means genuinely unstated, which is exactly "TBD" and is not the same thing as a $0 row.
        $I->haveInRepository($estimate);

        return [$company, $product, $estimate];
    }

    /** Called twice in the same test — the admin steps back in to record the payment. */
    private function loginAsAdmin(FunctionalTester $I): void
    {
        if (!$this->admin instanceof AdminUser) {
            $hasher = $I->grabService(UserPasswordHasherInterface::class);
            $this->admin = (new AdminUser())->setEmail('tbd-shipping-admin@example.test');
            $this->admin->setPassword($hasher->hashPassword($this->admin, 'test-password-123'));
            $I->haveInRepository($this->admin);
        }

        $I->amLoggedInAs($this->admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function loginAsCustomer(FunctionalTester $I, Company $company): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        // grabEntityFromRepository(), not grabService(EntityManagerInterface::class): that grabs
        // whichever kernel the last HTTP request booted, a different EM instance than the one
        // haveInRepository() below uses, and Doctrine refuses to persist through a relationship to
        // an entity foreign to the unit of work it is about to flush.
        $customer = (new CustomerUser())
            ->setEmail('tbd-shipping-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Priya')
            ->setLastName('Buyer')
            ->setCompany($I->grabEntityFromRepository(Company::class, ['id' => $company->getId()]));
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
        $I->haveHttpHeader('Host', '127.0.0.1');
    }

    /**
     * find() through a freshly grabbed service, never refresh() on an object a prior request
     * built: that request wrote through its OWN entity manager, and this one's identity map knows
     * nothing about it — see AdminInvoicePaymentsCest::reloadInvoice()'s own docblock for the same
     * rule.
     */
    private function reloadEstimate(FunctionalTester $I, int $id): Estimate
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(Estimate::class)->find($id);
    }

    private function reloadOrder(FunctionalTester $I, int $id): SalesOrder
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(SalesOrder::class)->find($id);
    }

    private function reloadInvoice(FunctionalTester $I, int $id): Invoice
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(Invoice::class)->find($id);
    }
}
