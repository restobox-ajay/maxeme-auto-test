<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The no-JS ✕ on a charge row, and the quote form's half of the no-JS Add Line control.
 *
 * A charge row's ✕ was a type="button" on both forms — visible, inert without JavaScript. An admin
 * could zero a shipping amount but never delete the row, so a quote or order carrying a charge it
 * should not have had no way to lose it. The Add Line control had the same shape, and the order
 * form gained a real submit for it; the quote form did not, which is the divergence this closes.
 *
 * Both buttons are ordinary saves: they adjust the posted charge rows, save and recalculate in one
 * POST, and land back on the form. Neither says anything about the document's status.
 */
final class AdminNoJsChargeRowRemovalCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nojs-charge-removal@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('No JS Charge Removal Co')
            ->setCode('NJCR-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('NJCR-SKU-' . uniqid())
            ->setName('No JS Charge Removal Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /** Shipping and a manual fee, so removing one leaves something to check the other survived. */
    private function makeOrderWithTwoCharges(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('NJCR-' . uniqid())
            ->setSubtotal('100.00')
            ->setFeeLines(json_encode([
                [
                    'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                    'amount' => 15.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
                ],
                [
                    'slug' => 'crating', 'label' => 'Crating', 'taxClass' => 'E',
                    'amount' => 25.0, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'manual',
                ],
            ]))
            ->setTotal('140.00');
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
        // A live order, through the one transition that makes one (#539 stage 2). It settles in
        // Approved: the fixture raises no invoices, so there is nothing for the deriver to move it on
        // to. What matters below is only that it is NOT a Draft.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return $order;
    }

    private function makeQuoteWithTwoCharges(FunctionalTester $I, Company $company, ProductCore $product): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('NJCRQ-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('100.00')
            ->setFeeLines(json_encode([
                [
                    'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                    'amount' => 15.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
                ],
                [
                    'slug' => 'crating', 'label' => 'Crating', 'taxClass' => 'E',
                    'amount' => 25.0, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'manual',
                ],
            ]))
            ->setTotal('140.00');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** @return list<array<string, mixed>> */
    private function feeLinesOf(FunctionalTester $I, string $class, int $id): array
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $document = $entityManager->find($class, $id);

        return json_decode((string) $document->getFeeLines(), true) ?: [];
    }

    // ---------------------------------------------------------------- markup

    public function bothFormsRenderARealSubmitAlongsideTheDeadRemoveButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithTwoCharges($I, $company, $product);
        $estimate = $this->makeQuoteWithTwoCharges($I, $company, $product);

        foreach ([
            '/admin/order/edit/' . $order->getId(),
            '/admin/estimate/edit/' . $estimate->getId(),
        ] as $page) {
            $I->amOnPage($page);
            $I->seeResponseCodeIsSuccessful();
            // The submit twin carries the row's own index, which is the only thing identifying
            // which row was pressed.
            $I->seeElement('button[type="submit"][name="remove_charge_line"][value="0"]');
            $I->seeElement('button[type="submit"][name="remove_charge_line"][value="1"]');
        }
    }

    /** The quote form's Add Line bar gains the posting select and real submit order already had. */
    public function theQuoteAddLineBarOffersAPostingSelectAndARealSubmit(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeQuoteWithTwoCharges($I, $company, $product);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('select[name="charge_line_type"]');
        $I->seeElement('button[type="submit"][name="add_charge_line"]');
    }

    // ---------------------------------------------------------------- order

    public function theNoJsRemoveButtonDropsThatOrderChargeAndKeepsTheOthers(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithTwoCharges($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // The whole form posts back, both rows included; the pressed button says which one goes.
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Crating', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'main_line'],
            ],
            'remove_charge_line' => '0',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $feeLines = $this->feeLinesOf($I, SalesOrder::class, (int) $order->getId());
        $labels = array_column($feeLines, 'label');
        $I->assertNotContains('Shipping (Ground)', $labels, 'the removed shipping row survived');
        $I->assertContains('Crating', $labels, 'the fee row was removed along with the shipping one');
    }

    /** Removing the last row really clears it — charge_lines_present says the UI was on the page. */
    public function removingTheLastOrderChargeLeavesNone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithTwoCharges($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Crating', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'main_line'],
            ],
            'remove_charge_line' => '0',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->assertSame([], $this->feeLinesOf($I, SalesOrder::class, (int) $order->getId()));
    }

    /** Removing a charge is not a statement about status, exactly as adding one is not. */
    public function theNoJsRemoveButtonLeavesTheOrdersStatusAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithTwoCharges($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
            ],
            'remove_charge_line' => '0',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        $I->seeCurrentUrlEquals('/admin/order/edit/' . $order->getId());
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $I->assertSame('Approved', $entityManager->find(SalesOrder::class, $order->getId())->getStatus());
    }

    // ---------------------------------------------------------------- quote

    public function theNoJsRemoveButtonDropsThatQuoteChargeAndKeepsTheOthers(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeQuoteWithTwoCharges($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Crating', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'main_line'],
            ],
            'remove_charge_line' => '1',
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => $product->getName(), 'qty' => '2', 'price' => '50.00'],
            ],
        ]);

        $feeLines = $this->feeLinesOf($I, Estimate::class, (int) $estimate->getId());
        $labels = array_column($feeLines, 'label');
        $I->assertNotContains('Crating', $labels, 'the removed fee row survived');
        $I->assertContains('Shipping (Ground)', $labels, 'the shipping row was removed along with the fee one');
    }

    /** The quote's no-JS Add Line submit, the half order already had. */
    public function theNoJsAddLineButtonAddsAQuoteChargeAndSavesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeQuoteWithTwoCharges($I, $company, $product);
        $line = $estimate->getLines()->first();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Crating', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'main_line'],
            ],
            'charge_line_type' => 'tax:Custom PST',
            'add_charge_line' => '1',
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => $product->getName(), 'qty' => '2', 'price' => '50.00'],
            ],
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->find(Estimate::class, $estimate->getId());
        $taxLines = json_decode((string) $saved->getTaxLines(), true) ?: [];
        $I->assertContains('Custom PST', array_column($taxLines['lines'] ?? $taxLines, 'label'));
    }

    /**
     * A quote's status is not the Add Line button's business either. EstimateController only
     * promotes a Draft on an explicit save_mode=submit, and this submit carries none — pinned here
     * because the order form needed an explicit fix for the same thing.
     */
    public function theNoJsChargeButtonsDoNotPromoteADraftQuote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $estimate = $this->makeQuoteWithTwoCharges($I, $company, $product);
        $line = $estimate->getLines()->first();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->find(Estimate::class, $estimate->getId())->setStatus('Draft', DocumentActor::system());
        $entityManager->flush();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
            ],
            'remove_charge_line' => '0',
            'lines' => [
                0 => ['id' => (string) $line->getId(), 'name' => $product->getName(), 'qty' => '2', 'price' => '50.00'],
            ],
        ]);

        $entityManager->clear();
        $I->assertSame(
            'Draft',
            $entityManager->find(Estimate::class, $estimate->getId())->getStatus()
        );
    }

    /**
     * An index that is not there drops nothing rather than refusing the save or deleting a
     * neighbour — a stale form re-submitted after the rows shifted must not destroy a charge.
     */
    public function anOutOfRangeRemovalIndexDropsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrderWithTwoCharges($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Shipping (Ground)', 'amount' => '15.00', 'type' => 'shipping'],
                ['label' => 'Crating', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'E', 'placement' => 'main_line'],
            ],
            'remove_charge_line' => '7',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
        ]);

        // The save has to have SUCCEEDED for "drops nothing" to mean anything (#594). An
        // out-of-range index that threw — an undefined array key, a TypeError — writes nothing at
        // all, so both fixture labels survive and the old assertContains() pair passed while the
        // admin's edit page was 500ing.
        $I->seeResponseCodeIsSuccessful();

        // And the rows are asserted whole, not by label alone: a save that kept both labels while
        // zeroing both amounts, or dropping the type, is the same charge row in name only.
        $amounts = [];
        foreach ($this->feeLinesOf($I, SalesOrder::class, (int) $order->getId()) as $row) {
            $amounts[(string) $row['label']] = number_format((float) $row['amount'], 2, '.', '');
        }

        $I->assertSame('15.00', $amounts['Shipping (Ground)'] ?? null, 'the shipping row kept its amount');
        $I->assertSame('25.00', $amounts['Crating'] ?? null, 'and so did the fee row');
    }
}
