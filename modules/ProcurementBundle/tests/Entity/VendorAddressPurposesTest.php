<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Entity;

use App\Service\DocumentActor;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Status\ProcurementStatusVocabularyProvider;

/**
 * The four address purposes and, more importantly, what happens when nobody has assigned one (#606).
 *
 * The fallback is the whole safety property of this change. Every existing `vendor_address` row has
 * all four flags at 0 and the migration leaves them there, so unless "no purpose assigned" resolves
 * to the default address, shipping this would silently stop freezing an address onto every purchase
 * order in every database that upgrades. Half of this file is about that case rather than about the
 * feature.
 */
final class VendorAddressPurposesTest extends TestCase
{
    /** No kernel here, so the vocabulary registry is primed by hand — see `PurchaseDocumentActionsTest`. */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new ProcurementStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private function address(string $label, string $street): VendorAddress
    {
        return (new VendorAddress())
            ->setLabel($label)
            ->setAddressLine1($street)
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setPostalCode('V6B 1A1')
            ->setCountry('CA');
    }

    private function orderFor(Vendor $vendor): PurchaseOrder
    {
        $order = (new PurchaseOrder())->setPoNumber('PO-PURPOSE-1')->setVendor($vendor);
        $order->addLine((new PurchaseOrderLine())->setName('Widget')->setQuantityOrdered('1')->setUnitCost('1.0000')->setSubtotal('1.00'));

        return $order;
    }

    // ------------------------------------------------------------------ the pre-purposes world

    /**
     * One address, no flags at all — the state of every row a database upgrading to #606 already has.
     */
    public function testAnAddressWithNoPurposeAnswersEveryPurpose(): void
    {
        $vendor = (new Vendor())->setName('One Location Supply');
        $only = $this->address('Head office', '1 Main St');
        $vendor->addAddress($only);

        self::assertSame($only, $vendor->getOrderToAddress());
        self::assertSame($only, $vendor->getShipFromAddress());
        self::assertSame($only, $vendor->getRemitToAddress());
        self::assertSame($only, $vendor->getReturnToAddress());
    }

    /** And a purchase order still freezes it, which is the property that must not regress. */
    public function testIssuingStillFreezesTheAddressWhenNoPurposeIsAssigned(): void
    {
        $vendor = (new Vendor())->setName('One Location Supply');
        $vendor->addAddress($this->address('Head office', '1 Main St')->setIsDefault(true));

        $order = $this->orderFor($vendor);
        $order->setStatus('Issued', DocumentActor::named('Priya'));

        self::assertStringContainsString('1 Main St', (string) $order->getVendorAddress());
    }

    /**
     * A bare address — no contact fields, which is every address that predates #605 — renders the
     * same snapshot it always did. The contact block is prepended only where somebody filled it in.
     */
    public function testABareAddressSnapshotIsUnchanged(): void
    {
        $bare = $this->address('Head office', '1 Main St');

        self::assertSame("1 Main St\nVancouver, BC V6B 1A1\nCA", $bare->toSnapshot());
    }

    // ------------------------------------------------------------------------- the purposes

    public function testTheOrderToAddressIsWhatAPurchaseOrderFreezes(): void
    {
        $vendor = (new Vendor())->setName('Two Location Supply');
        // The default is the warehouse, deliberately: before purposes existed, this is where the PO
        // would have gone, and it is the wrong place to send one.
        $vendor->addAddress($this->address('Warehouse', '900 Dock Rd')->setIsDefault(true)->setIsShipFrom(true));
        $vendor->addAddress($this->address('Orders desk', '5 Bay St')->setIsOrderTo(true));

        $order = $this->orderFor($vendor);
        $order->setStatus('Issued', DocumentActor::named('Priya'));

        self::assertStringContainsString('5 Bay St', (string) $order->getVendorAddress());
        self::assertStringNotContainsString('900 Dock Rd', (string) $order->getVendorAddress());
    }

    public function testEachPurposeResolvesToItsOwnRow(): void
    {
        $vendor = (new Vendor())->setName('Split Supply');
        $orders = $this->address('Orders desk', '5 Bay St')->setIsOrderTo(true);
        $depot = $this->address('Depot', '900 Dock Rd')->setIsShipFrom(true);
        $factor = $this->address('Factor', '77 Finance Ave')->setIsRemitTo(true)->setCompanyName('Northbridge Factoring Inc.');
        $returns = $this->address('Returns', '12 Back Ln')->setIsReturnTo(true);

        foreach ([$orders, $depot, $factor, $returns] as $address) {
            $vendor->addAddress($address);
        }

        self::assertSame($orders, $vendor->getOrderToAddress());
        self::assertSame($depot, $vendor->getShipFromAddress());
        self::assertSame($factor, $vendor->getRemitToAddress());
        self::assertSame($returns, $vendor->getReturnToAddress());
    }

    /**
     * One row wearing several hats, which is the common case and the reason these are four flags
     * rather than one `purpose` column — an enum would need four duplicate rows to say this.
     */
    public function testOneRowMayWearSeveralPurposes(): void
    {
        $vendor = (new Vendor())->setName('Small Supply');
        $everything = $this->address('Shop', '3 Small St')
            ->setIsOrderTo(true)->setIsShipFrom(true)->setIsRemitTo(true)->setIsReturnTo(true);
        $vendor->addAddress($everything);

        self::assertSame($everything, $vendor->getOrderToAddress());
        self::assertSame($everything, $vendor->getRemitToAddress());
        self::assertSame(['Order to', 'Ship from', 'Remit to', 'Return to'], $everything->purposeLabels());
    }

    /**
     * The remit-to being a different legal entity is the case that costs money when it is wrong, so
     * the snapshot has to carry that entity's name and not the vendor's.
     */
    public function testARemitToSnapshotCarriesItsOwnEntityNameAndContact(): void
    {
        $factor = $this->address('Factor', '77 Finance Ave')
            ->setIsRemitTo(true)
            ->setCompanyName('Northbridge Factoring Inc.')
            ->setFirstName('Dana')
            ->setLastName('Okafor')
            ->setEmailPrimary('ar@northbridge.example')
            ->setPhone('604-555-0143');

        $snapshot = $factor->toSnapshot();

        self::assertStringContainsString('Northbridge Factoring Inc.', $snapshot);
        self::assertStringContainsString('Dana Okafor', $snapshot);
        self::assertStringContainsString('77 Finance Ave', $snapshot);
        self::assertStringContainsString('Tel 604-555-0143', $snapshot);
        self::assertStringContainsString('ar@northbridge.example', $snapshot);
    }

    /**
     * A purpose nobody assigned falls through to the default even when OTHER purposes are assigned —
     * the partial-adoption case, which is what a real vendor file looks like a week after this ships.
     */
    public function testAnUnassignedPurposeStillFallsBackToTheDefault(): void
    {
        $vendor = (new Vendor())->setName('Partly Configured Supply');
        $head = $this->address('Head office', '1 Main St')->setIsDefault(true);
        $depot = $this->address('Depot', '900 Dock Rd')->setIsShipFrom(true);
        $vendor->addAddress($head);
        $vendor->addAddress($depot);

        self::assertSame($depot, $vendor->getShipFromAddress());
        self::assertSame($head, $vendor->getOrderToAddress(), 'order-to was never assigned, so it must fall back to the default');
        self::assertSame($head, $vendor->getRemitToAddress());
        self::assertSame($head, $vendor->getReturnToAddress());
    }

    public function testAVendorWithNoAddressesResolvesEveryPurposeToNothing(): void
    {
        $vendor = (new Vendor())->setName('No Address Supply');

        self::assertNull($vendor->getOrderToAddress());
        self::assertNull($vendor->getShipFromAddress());
        self::assertNull($vendor->getRemitToAddress());
        self::assertNull($vendor->getReturnToAddress());
    }
}
