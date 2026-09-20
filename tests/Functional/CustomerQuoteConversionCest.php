<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueOrder;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\SalesOrder;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use FeeBCTireBundle\EventSubscriber\BCTireNumberFieldSubscriber;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use TaxBCBundle\EventSubscriber\BCPstNumberFieldSubscriber;
use Tests\Support\FunctionalTester;

/**
 * The quote→order handover as the customer actually drives it, over real HTTP through
 * Customer\EstimateController::accept(). CustomerEstimateCest already covers what the copy carries;
 * this covers what the acceptance *does* around the copy — the tax-compliance snapshots every other
 * order-creation path takes (#249), the once-only guarantee (#274), and the audit entries the
 * conversion leaves on both documents (#277).
 *
 * These have to be functional rather than unit tests: the snapshots are applied by the controller
 * after the order is flushed (the writers no-op on an order with no id yet), and only a real
 * request registers the custom field definitions they write into — BCPstNumberFieldSubscriber and
 * BCTireNumberFieldSubscriber both register lazily on kernel.request.
 */
final class CustomerQuoteConversionCest
{
    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Quote Conversion Co')
            ->setCode('QCONV-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function loginAs(FunctionalTester $I, Company $company): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('qconv-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    /** A fully priced quote, optionally with a frozen shipping address in $province. */
    private function makePricedEstimate(FunctionalTester $I, Company $company, ?string $province = null): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('QCONV-' . uniqid())
            ->setSource('Customer')
            ->setPoNumber('PO-1')
            ->setFulfillmentRegion('West')
            ->setSubtotal('100.00')
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G',
                'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setTax('5.00')
            ->setTotal('115.00');
        $estimate->setStatus('Priced', DocumentActor::system());

        if ($province !== null) {
            $estimate->addressForWriting('shipping')
                ->setFirstName('Jane')
                ->setLastName('Doe')
                ->setAddressLine1('1 Dock Road')
                ->setCity('Test City')
                ->setProvince($province)
                ->setCountry('CA');
        }

        $estimate->addLine(
            (new EstimateLine())
                ->setName('Widget')
                ->setSku('WIDGET-1')
                ->setQuantity('2.00')
                ->setCost('40.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
        );

        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** The Accept form only renders while the quote is Priced, so the token is scraped from there. */
    private function grabAcceptToken(FunctionalTester $I, Estimate $estimate): string
    {
        $I->amOnPage('/estimates/' . $estimate->getId());
        $html = $I->grabPageSource();
        preg_match('/<form[^>]*action="[^"]*\/accept"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    /**
     * Seeds a company-scoped custom field value directly. Same reason AdminBcTaxOrderPstNumberCest
     * does it this way: the definitions exist only once a real request has been through the kernel,
     * and the value has to be fetched through Codeception's own entity manager handle to survive
     * the request boundary.
     */
    private function setCompanyFieldValue(FunctionalTester $I, Company $company, string $slug, string $value): void
    {
        $definition = $I->grabEntityFromRepository(CustomFieldDefinition::class, [
            'objectType' => CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            'slug' => $slug,
        ]);
        $I->assertNotNull($definition, sprintf('%s should have been registered by now', $slug));

        // setValue() resolves the company by id through its own EntityManager reference rather
        // than needing $company attached to Codeception's current EM handle — the same reason
        // $definition above is re-fetched instead of reused across the request boundary.
        $I->grabService(CustomFieldValueRepository::class)->setValue($definition, (int) $company->getId(), $value);
        $I->grabService(EntityManagerInterface::class)->flush();
    }

    private function orderSnapshotValue(FunctionalTester $I, int $orderId, string $slug): ?string
    {
        $definition = $I->grabEntityFromRepository(CustomFieldDefinition::class, [
            'objectType' => CustomFieldDefinition::OBJECT_TYPE_ORDER,
            'slug' => $slug,
        ]);

        try {
            $row = $I->grabEntityFromRepository(CustomFieldValueOrder::class, [
                'definition' => $definition->getId(),
                'order' => $orderId,
            ]);
        } catch (NoResultException) {
            return null;
        }

        return $row->getValue();
    }

    /**
     * #249: an order accepted from a quote used to be the only order in the system with no PST # /
     * TSBC # snapshot, because conversion never called applyOrderSnapshots(). These are frozen
     * tax-compliance records — they cannot be reconstructed after the fact — so a BC order created
     * this way printed no PST # on its invoice while the same basket through checkout printed one.
     */
    public function acceptingABcQuoteRecordsThePstAndTsbcSnapshotsOnTheOrder(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);
        // Routes a request through the kernel so both bundles' field definitions exist.
        $I->amOnPage('/estimates');

        $bundleStatus = $I->grabService(BundleStatusRepository::class);
        if (!$bundleStatus->isActive('TaxBCBundle') || !$bundleStatus->isActive('FeeBCTireBundle')) {
            return;
        }

        $this->setCompanyFieldValue($I, $company, BCPstNumberFieldSubscriber::SLUG, 'PST-424242');
        $this->setCompanyFieldValue($I, $company, BCTireNumberFieldSubscriber::SLUG, 'TSBC-909090');

        $estimate = $this->makePricedEstimate($I, $company, 'BC');
        $token = $this->grabAcceptToken($I, $estimate);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->assertSame('PST-424242', $this->orderSnapshotValue($I, (int) $order->getId(), BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG));
        $I->assertSame('TSBC-909090', $this->orderSnapshotValue($I, (int) $order->getId(), BCTireNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG));
    }

    /** The snapshots are BC-only on both writers, so a quote shipping elsewhere still records none. */
    public function acceptingANonBcQuoteRecordsNoSnapshots(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);
        $I->amOnPage('/estimates');

        $bundleStatus = $I->grabService(BundleStatusRepository::class);
        if (!$bundleStatus->isActive('TaxBCBundle') || !$bundleStatus->isActive('FeeBCTireBundle')) {
            return;
        }

        $this->setCompanyFieldValue($I, $company, BCPstNumberFieldSubscriber::SLUG, 'PST-424242');

        $estimate = $this->makePricedEstimate($I, $company, 'ON');
        $token = $this->grabAcceptToken($I, $estimate);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $I->assertNull($this->orderSnapshotValue($I, (int) $order->getId(), BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG));
    }

    /**
     * #274: the double-submit. The second POST must not mint a second order — each one would carry
     * its own inventory reservation, and only the last would be reachable from the quote, leaving
     * the other orphaned but still billable and still holding stock.
     */
    public function acceptingTwiceCreatesOneOrderAndForwardsToIt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);
        $estimate = $this->makePricedEstimate($I, $company);

        // Scraped once, replayed: the token is id-scoped rather than status-scoped, which is exactly
        // what a resubmitted request would carry.
        $token = $this->grabAcceptToken($I, $estimate);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);

        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        // Sent to the order the first submit produced rather than shown a failure — it did work.
        $I->seeCurrentUrlEquals('/orders/' . $order->getId());
        $I->see('was already accepted');
        $I->assertCount(1, $I->grabEntitiesFromRepository(SalesOrder::class, ['company' => $company->getId()]));
    }

    /**
     * #277: the conversion used to write nothing anywhere, so the new order's history tab opened
     * completely empty and gave no indication it came from a quote at all.
     *
     * The order's timeline carries more than this one entry since #539 stage 2 — approve() signs its
     * own acceptance, and the deriver records the move to Invoiced — so the conversion entry is
     * picked out of the order's log rather than assumed to be the only one. Its author and its
     * not-notified flag are asserted exactly as before.
     */
    public function acceptingWritesAConversionLogOnBothDocuments(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $customer = $this->loginAs($I, $company);
        $estimate = $this->makePricedEstimate($I, $company);

        $token = $this->grabAcceptToken($I, $estimate);
        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $expectedAuthor = sprintf('Jane Doe, %s (%d)', $customer->getEmail(), $customer->getId());

        // Named by its comment now: the quote's timeline also carries the Priced -> Accepted row
        // setStatus() writes during conversion, so "the one entry" is no longer unambiguous.
        $estimateLog = $I->grabEntityFromRepository(AuditLog::class, [
            'entityType' => 'Estimate',
            'entityId' => $estimate->getId(),
            'summary' => sprintf('Quote accepted; converted to order %s.', $order->getOrderNumber()),
            'actorType' => 'document',
        ]);
        $I->assertSame($expectedAuthor, $estimateLog->getActorName());
        $I->assertFalse($estimateLog->isRecipientNotified());

        // And the acceptance move itself is on the quote, signed by the same customer — the row the
        // quote never had before the status seam.
        $acceptanceLog = $I->grabEntityFromRepository(AuditLog::class, [
            'entityType' => 'Estimate',
            'entityId' => $estimate->getId(),
            'summary' => 'Estimate accepted and converted to an order.',
            'actorType' => 'document',
        ]);
        $I->assertSame($expectedAuthor, $acceptanceLog->getActorName());

        /** @var list<AuditLog> $orderLogs */
        $orderLogs = $I->grabEntitiesFromRepository(AuditLog::class, ['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document']);
        $conversionEntries = array_values(array_filter(
            $orderLogs,
            static fn (AuditLog $log): bool
                => $log->getSummary() === sprintf('Created from quote %s.', $estimate->getDocumentNumber()),
        ));

        $I->assertCount(1, $conversionEntries, 'the order must say, exactly once, which quote it came from');
        $I->assertSame($expectedAuthor, $conversionEntries[0]->getActorName());
        $I->assertFalse($conversionEntries[0]->isRecipientNotified());

        // The acceptance itself is on the timeline too, signed by the customer who performed it —
        // approve() writes it, and an order that reached Approved without one would mean the status
        // had been assigned behind the action's back (#539 stage 2).
        $approvalEntries = array_values(array_filter(
            $orderLogs,
            static fn (AuditLog $log): bool => $log->getSummary() === 'Order approved.',
        ));
        $I->assertCount(1, $approvalEntries, 'approve() must sign the acceptance on the order');
        $I->assertSame($expectedAuthor, $approvalEntries[0]->getActorName());
    }
}
