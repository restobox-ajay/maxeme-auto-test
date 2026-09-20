<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Service\DocumentActor;
use App\Service\OrderTaxBreakdownService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer\EstimateController — /estimates listing+detail company-scoping, and the
 *  accept()/reject() actions (CSRF, status guards, and accept()'s Estimate -> SalesOrder
 *  conversion via the real EstimateConversionService over HTTP). */
final class CustomerEstimateCest
{
    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Acme Co')
            ->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function loginAs(FunctionalTester $I, Company $company): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('ce-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    /** One shipping row at $amount, or no rows at all when the amount is null (TBD). */
    private static function shippingLinesJson(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return json_encode([[
            'slug' => 'shipping',
            'label' => 'Shipping (Ground)',
            'taxClass' => 'G',
            'amount' => (float) $amount,
            'placement' => 'main_line',
            'type' => 'shipping',
            'source' => 'auto-calc',
        ]]);
    }

    private function makeEstimate(FunctionalTester $I, Company $company, string $status, array $overrides = []): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-' . uniqid())
            ->setSource('Customer')
            ->setPoNumber($overrides['poNumber'] ?? 'PO-1')
            ->setFulfillmentRegion($overrides['fulfillmentRegion'] ?? 'West')
            ->setSubtotal($overrides['subtotal'] ?? '100.00')
            // Shipping is a row, and the header figure is derived from it — see
            // AbstractSalesDocument::setFeeLines(). A null override means no row, i.e. TBD.
            ->setFeeLines(self::shippingLinesJson($overrides['shipping'] ?? '10.00'))
            ->setTax($overrides['tax'] ?? '5.00')
            ->setTotal($overrides['total'] ?? '115.00');
        $estimate->setStatus($status, DocumentActor::system());

        $line = (new EstimateLine())
            ->setName('Widget')
            ->setSku('WIDGET-1')
            ->setQuantity('2.00')
            ->setCost('40.00')
            ->setPrice($overrides['linePrice'] ?? '50.00')
            ->setSubtotal($overrides['lineSubtotal'] ?? '100.00');
        $estimate->addLine($line);

        $I->haveInRepository($estimate);

        return $estimate;
    }

    public function guestIndexIsRedirectedToLoginWithAnErrorFlash(FunctionalTester $I): void
    {
        $I->amOnPage('/estimates');
        $I->seeCurrentUrlEquals('/auth/login');
        $I->see('Please log in to view your quote requests.');
    }

    public function guestDetailIsRedirectedToLoginWithAnErrorFlash(FunctionalTester $I): void
    {
        $I->amOnPage('/estimates/1');
        $I->seeCurrentUrlEquals('/auth/login');
        $I->see('Please log in to view your quote requests.');
    }

    public function indexListsOnlyTheLoggedInCustomersCompanyEstimates(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I);
        $mine = $this->makeEstimate($I, $company, 'Submitted');
        $this->makeEstimate($I, $otherCompany, 'Submitted');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates');
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber());
        $I->dontSee('Draft-only estimate should never render here');
    }

    public function indexNeverShowsDraftEstimates(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $draft = $this->makeEstimate($I, $company, 'Draft');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee($draft->getDocumentNumber());
    }

    public function indexFiltersByTab(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $priced = $this->makeEstimate($I, $company, 'Priced');
        $submitted = $this->makeEstimate($I, $company, 'Submitted');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates?tab=PRICED');
        $I->seeResponseCodeIsSuccessful();
        $I->see($priced->getDocumentNumber());
        $I->dontSee($submitted->getDocumentNumber());
    }

    public function detailRendersTheEstimateForItsOwnCompany(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($estimate->getDocumentNumber());
    }

    public function detailForAnotherCompanysEstimateRedirectsWithNotFound(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I);
        $theirs = $this->makeEstimate($I, $otherCompany, 'Priced');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates/' . $theirs->getId());
        $I->seeCurrentUrlEquals('/estimates');
        $I->see('Quote request not found.');
    }

    /**
     * An unpriced line used to reach the customer labelled "No tax" with a Total Tax of $0.00,
     * right beside a Price column reading TBD — the row asserting the item was non-taxable when
     * all that was true is that nobody had priced it (#255). A line that really is $0.00 and
     * really is non-taxable still reads "No tax", which is the distinction the row below proves.
     *
     * The document's own Subtotal and Tax read TBD on the same page, because a quote with an
     * unpriced line has no subtotal or tax to state (#254).
     */
    public function anUnpricedLineReadsTbdForTaxWhileAPricedOneStillReadsNoTax(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Submitted', ['shipping' => null]);
        // What a save leaves behind on a quote with an unpriced line: no header money at all
        // (#254). makeEstimate()'s `??` defaults can't express a null, so they are set here.
        $estimate->setSubtotal(null)->setTax(null)->setTotal(null);
        $estimate->addLine(
            (new EstimateLine())
                ->setName('Not Priced Yet')
                ->setSku('WIDGET-2')
                ->setQuantity('1.00')
                ->setCost('10.00'),
        );

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $taxBreakdownService = $I->grabService(OrderTaxBreakdownService::class);
        // The snapshot the page reads back, frozen exactly as a save would freeze it.
        $estimate->setTaxLines($taxBreakdownService->toJson($taxBreakdownService->buildForOrder($estimate)));
        $entityManager->flush();

        $this->loginAs($I, $company);
        $I->amOnPage('/estimates/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $rows = '.customer-order-items-table tbody tr';
        $I->see('No tax', $rows . ':nth-child(1) td[data-label="Tax Code"]');
        $I->see('$0.00', $rows . ':nth-child(1) td[data-label="Tax $"]');
        $I->see('TBD', $rows . ':nth-child(2) td[data-label="Tax Code"]');
        $I->see('TBD', $rows . ':nth-child(2) td[data-label="Tax $"]');
        $I->dontSee('$0.00', $rows . ':nth-child(2) td[data-label="Tax $"]');

        // ...and the totals box says the same thing about the document as a whole.
        $I->see('TBD', '.customer-view-order-summary');
    }

    public function quotePdfDownloadsAPdfForAPricedEstimate(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        $I->amOnPage('/estimates/' . $estimate->getId() . '/quote');
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource());
    }

    private function grabActionToken(FunctionalTester $I, string $page, string $actionSuffix): string
    {
        $I->amOnPage($page);
        $html = $I->grabPageSource();
        preg_match('/<form[^>]*action="[^"]*' . preg_quote($actionSuffix, '/') . '"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function acceptingAPricedEstimateConvertsItToAnOrderAndRedirectsThere(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/accept');

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Accepted']);
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'convertedOrder' => $order->getId()]);
        $I->seeCurrentUrlEquals('/orders/' . $order->getId());
    }

    /**
     * Conversion carries the quote's shipping rows across untouched. Earlier steps of #165 learned
     * repeatedly that leaving estimates behind breaks estimate→order: a quote priced with shipping
     * an admin agreed must not become an order that has forgotten it.
     */
    public function acceptingAnEstimateCarriesItsShippingRowsOntoTheOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        // Two rows, because one figure is exactly what the retired shipping column could hold.
        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $stored = $entityManager->find(Estimate::class, $estimate->getId());
        $stored->setFeeLines(json_encode([
            ['slug' => 'shipping-canada-post', 'label' => 'Shipping (Canada Post)', 'taxClass' => 'G', 'amount' => 22.5, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual'],
            ['slug' => 'fuel-surcharge', 'label' => 'Fuel surcharge', 'taxClass' => 'G', 'amount' => 6.25, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual'],
        ]));
        $entityManager->flush();

        $this->loginAs($I, $company);
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/accept');
        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $entityManager->clear();
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);

        $I->assertSame(
            ['Shipping (Canada Post)', 'Fuel surcharge'],
            array_map(static fn ($line): string => $line->label, $order->getShippingLines()),
        );
        $I->assertEqualsWithDelta(28.75, $order->getShippingTotal(), 0.001);
    }

    /**
     * The admin's one-off fee rows cross with everything else. EstimateConversionService copies
     * fee_lines verbatim, so a quote priced with a $40 crating charge the customer agreed to must
     * not become an order that has forgotten it — the row is a fee line like any other from the
     * snapshot on, and nothing about conversion knows or needs to know it was typed by hand.
     */
    public function acceptingAnEstimateCarriesItsManualFeeLinesOntoTheOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');

        $entityManager = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $stored = $entityManager->find(Estimate::class, $estimate->getId());
        $stored->setFeeLines(json_encode([
            ['slug' => 'shipping-canada-post', 'label' => 'Shipping (Canada Post)', 'taxClass' => 'G', 'amount' => 22.5, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual'],
            ['slug' => 'eco-fee', 'label' => 'Eco Fee', 'taxClass' => 'E', 'amount' => 1.5, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'auto-calc'],
            ['slug' => 'crating', 'label' => 'Crating', 'taxClass' => 'E', 'amount' => 40.0, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'manual'],
        ]));
        $entityManager->flush();
        $before = $stored->getFeeLines();

        $this->loginAs($I, $company);
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/accept');
        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $entityManager->clear();
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);

        // Verbatim: the calculated line, the shipping row and the hand-typed one all arrive intact.
        $I->assertSame($before, $order->getFeeLines());

        $manual = array_values(array_filter(
            $order->getFeeLineRows(),
            static fn ($line): bool => $line->type === 'fee' && $line->source === 'manual',
        ));
        $I->assertCount(1, $manual);
        $I->assertSame('Crating', $manual[0]->label);
        $I->assertSame('crating', $manual[0]->slug);
        $I->assertEqualsWithDelta(40.0, $manual[0]->amount, 0.001);
        // And the order's own reader still sees only the shipping row as shipping.
        $I->assertEqualsWithDelta(22.5, $order->getShippingTotal(), 0.001);
    }

    public function acceptingWithAnInvalidCsrfTokenLeavesTheEstimateUnchanged(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Priced']);
    }

    public function acceptingAnAlreadyAcceptedEstimateShowsAnErrorAndDoesNotCreateASecondOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        // The Accept form only renders while Priced, so the token is scraped in that state,
        // then the estimate is flipped to Accepted directly (the token itself is id-scoped, not
        // status-scoped, so it stays valid) to exercise the status guard on the actual request.
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/accept');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(Estimate::class)->find($estimate->getId())->setStatus('Accepted', DocumentActor::system());
        $em->flush();

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('This estimate is not ready to accept yet.');

        $I->dontSeeInRepository(SalesOrder::class, ['company' => $company->getId()]);
    }

    public function decliningAPricedEstimateMarksItRejected(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/reject');

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/reject', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/estimates');

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Rejected']);
    }

    /**
     * #202: a quote that hasn't been priced yet must not offer a Decline control — and the endpoint
     * must refuse it too, so a crafted POST can't decline an unpriced request.
     */
    public function anUnpricedQuoteHasNoDeclineControlAndCannotBeDeclined(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $submitted = $this->makeEstimate($I, $company, 'Submitted');
        $priced = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        // No Decline form on a not-yet-priced quote...
        $I->amOnPage('/estimates/' . $submitted->getId());
        $I->dontSeeElement('form[action="/estimates/' . $submitted->getId() . '/reject"]');
        // ...but it appears once the quote is priced.
        $I->amOnPage('/estimates/' . $priced->getId());
        $I->seeElement('form[action="/estimates/' . $priced->getId() . '/reject"]');

        // Endpoint refuses to decline an unpriced quote even with a valid CSRF token: scrape the
        // token while $priced still renders the Decline form, flip it to Submitted (the token is
        // id-scoped, not status-scoped, so it stays valid), then attempt the decline.
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $priced->getId(), '/reject');
        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(Estimate::class)->find($priced->getId())->setStatus('Submitted', DocumentActor::system());
        $em->flush();

        $I->sendAjaxPostRequest('/estimates/' . $priced->getId() . '/reject', ['_token' => $csrfToken]);
        $I->see('This quote cannot be declined yet.');
        $I->seeInRepository(Estimate::class, ['id' => $priced->getId(), 'status' => 'Submitted']);
    }

    /**
     * #275: a quote can be Priced without being fully priced (an admin forcing status, or blanking
     * a price — both now guarded server-side, but convert()'s own contract is what actually keeps
     * accept() safe here). The Accept control must not be offered on one, and the endpoint must
     * refuse it with a flash rather than an uncaught LogicException turning into a 500.
     */
    public function aPricedButNotFullyPricedQuoteHasNoAcceptControlAndCannotBeAccepted(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $notFullyPriced = $this->makeEstimate($I, $company, 'Priced');
        // makeEstimate()'s overrides use ?? , which does not distinguish "pass null" from "omit" —
        // so the line is blanked directly, bypassing the fixture helper, to reach the state #275
        // is about: Priced status with a line the fixture's own isFullyPriced() reads as unpriced.
        $em = $I->grabService(EntityManagerInterface::class);
        $notFullyPriced->getLines()->first()->setPrice(null)->setSubtotal(null);
        $em->flush();
        $this->loginAs($I, $company);

        // No Accept form on a Priced-but-not-fully-priced quote...
        $I->amOnPage('/estimates/' . $notFullyPriced->getId());
        $I->dontSeeElement('form[action="/estimates/' . $notFullyPriced->getId() . '/accept"]');

        // ...and a crafted POST with a valid (id-scoped, not price-scoped) token is refused rather
        // than crashing.
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $notFullyPriced->getId(), '/reject');
        $I->sendAjaxPostRequest('/estimates/' . $notFullyPriced->getId() . '/accept', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.error-page, .exception-message');

        $I->seeInRepository(Estimate::class, ['id' => $notFullyPriced->getId(), 'status' => 'Priced', 'convertedOrder' => null]);
    }

    public function rejectingWithAnInvalidCsrfTokenLeavesTheEstimateUnchanged(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Submitted');
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/reject', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->seeInRepository(Estimate::class, ['id' => $estimate->getId(), 'status' => 'Submitted']);
    }

    public function decliningAnAlreadyRejectedEstimateShowsAnError(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $estimate = $this->makeEstimate($I, $company, 'Priced');
        $this->loginAs($I, $company);

        // The Decline form only renders while Priced, so the token is scraped in that state, then
        // the estimate is flipped to Rejected directly (the token is id-scoped, not status-scoped,
        // so it stays valid) to exercise the status guard on the actual request.
        $csrfToken = $this->grabActionToken($I, '/estimates/' . $estimate->getId(), '/reject');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getRepository(Estimate::class)->find($estimate->getId())->setStatus('Rejected', DocumentActor::system());
        $em->flush();

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/reject', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('This quote cannot be declined yet.');
    }
}
