<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AbstractDocumentAddress;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderAddress;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The point of the snapshot: a document records what happened, and editing the address book
 * afterwards must not rewrite history.
 *
 * Before this, billing/shipping were a ManyToOne to CompanyAddress, so a customer correcting their
 * address changed every historical invoice — and getEffectiveBillingAddress() fell back to the
 * company's *current* default when the key was null, so a document could print an address the goods
 * never went to.
 */
final class DocumentAddressSnapshotTest extends DoctrineIntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Snapshot Co')->setCode('SNAP');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    private function bookAddress(string $city = 'Vancouver', string $province = 'BC'): CompanyAddress
    {
        $address = (new CompanyAddress())
            ->setCompany($this->company)
            ->setLabel('Main')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setCompanyName('Snapshot Receiving')
            ->setAddressLine1('1 Dock Road')
            ->setCity($city)
            ->setProvince($province)
            ->setCountry('CA')
            ->setPostalCode('V5K0A1')
            ->setDeliveryInstructions('Back entrance, closed after 3pm.');

        $this->em->persist($address);
        $this->em->flush();

        return $address;
    }

    private function order(): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setTotal('100.00');
        // A live order, which since #539 stage 2 means one that has been approved: there is no
        // status setter, and an unapproved order is a Draft nobody has committed to.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->em->persist($order);

        return $order;
    }

    public function testEditingTheAddressBookDoesNotRewriteAnExistingOrder(): void
    {
        $book = $this->bookAddress();
        $order = $this->order();
        $order->setShippingAddressFrom($book);
        $this->em->flush();

        // The customer moves across town, long after ordering.
        $book->setCity('Toronto')->setProvince('ON')->setAddressLine1('99 Elsewhere Ave');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());
        $shipping = $reloaded->getShippingAddress();

        self::assertInstanceOf(AbstractDocumentAddress::class, $shipping);
        self::assertSame('Vancouver', $shipping->getCity(), 'the order must still say where it shipped');
        self::assertSame('BC', $shipping->getProvince());
        self::assertSame('1 Dock Road', $shipping->getAddressLine1());
    }

    public function testDeliveryInstructionsAreFrozenToo(): void
    {
        $book = $this->bookAddress();
        $order = $this->order();
        $order->setShippingAddressFrom($book);
        $this->em->flush();

        $book->setDeliveryInstructions('Front desk now.');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());

        self::assertSame(
            'Back entrance, closed after 3pm.',
            $reloaded->getShippingAddress()?->getDeliveryInstructions(),
            'the warehouse should follow the instructions given at order time',
        );
    }

    public function testAnOrderWithNoRecordedAddressShowsNothingRatherThanTheCompanyDefault(): void
    {
        // The old getEffectiveBillingAddress() fell back to the company's current default here, so a
        // document asserted an address that was never chosen for it.
        $default = $this->bookAddress();
        $default->setIsDefaultBilling(true);
        $default->setIsDefaultShipping(true);
        $this->em->flush();

        $order = $this->order();
        $this->em->flush();

        self::assertNull($order->getBillingAddress());
        self::assertNull($order->getEffectiveBillingAddress());
        self::assertNull($order->getEffectiveShippingAddress());
    }

    public function testSourceAddressIsRecordedAsProvenanceOnly(): void
    {
        $book = $this->bookAddress();
        $order = $this->order();
        $order->setShippingAddressFrom($book);
        $this->em->flush();

        self::assertSame($book->getId(), $order->getShippingAddress()?->getSourceAddress()?->getId());
    }

    public function testDeletingTheSourceAddressLeavesTheSnapshotIntact(): void
    {
        $book = $this->bookAddress();
        $order = $this->order();
        $order->setShippingAddressFrom($book);
        $this->em->flush();

        $orderId = $order->getId();
        $bookId = $book->getId();

        // Cleared first so the delete happens the way it does in production — a separate request that
        // has not loaded the snapshot. source_address_id is nulled by the database (ON DELETE SET
        // NULL); Doctrine would otherwise be holding a stale in-memory reference to the removed row.
        $this->em->clear();
        $this->em->remove($this->em->getRepository(CompanyAddress::class)->find($bookId));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($orderId);
        $shipping = $reloaded->getShippingAddress();

        self::assertInstanceOf(AbstractDocumentAddress::class, $shipping, 'deleting a book entry must not delete order history');
        self::assertSame('Vancouver', $shipping->getCity());
        self::assertNull($shipping->getSourceAddress(), 'provenance is nulled, the copy survives');
    }

    public function testOnlyOneAddressPerTypeIsKept(): void
    {
        $order = $this->order();
        $order->setShippingAddressFrom($this->bookAddress('Vancouver'));
        $order->setShippingAddressFrom($this->bookAddress('Burnaby'));
        $this->em->flush();

        self::assertCount(1, $order->getAddresses());
        self::assertSame('Burnaby', $order->getShippingAddress()?->getCity());
    }

    public function testBillingAndShippingCoexist(): void
    {
        $order = $this->order();
        $order->setBillingAddressFrom($this->bookAddress('Victoria'));
        $order->setShippingAddressFrom($this->bookAddress('Kelowna'));
        $this->em->flush();

        self::assertCount(2, $order->getAddresses());
        self::assertSame('Victoria', $order->getBillingAddress()?->getCity());
        self::assertSame('Kelowna', $order->getShippingAddress()?->getCity());
    }

    public function testDeletingTheOrderRemovesItsSnapshots(): void
    {
        $order = $this->order();
        $order->setShippingAddressFrom($this->bookAddress());
        $this->em->flush();
        $orderId = $order->getId();

        $this->em->remove($order);
        $this->em->flush();

        self::assertCount(
            0,
            $this->em->getRepository(SalesOrderAddress::class)->findAll(),
            'snapshots belong to the order and go with it',
        );
        self::assertNull($this->em->getRepository(SalesOrder::class)->find($orderId));
    }

    public function testTheLegacyNameAccessorsNowReadAndWriteTheSnapshot(): void
    {
        // Kept as compatibility shims so the many existing call sites keep working while the data
        // lives in one place.
        $order = $this->order();
        $order->setShippingName('Grace Hopper');
        $order->setShippingCompanyName('Snapshot Receiving');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());

        self::assertSame('Grace Hopper', $reloaded->getShippingName());
        self::assertSame('Snapshot Receiving', $reloaded->getShippingCompanyName());
        self::assertSame('Grace', $reloaded->getShippingAddress()?->getFirstName());
        self::assertSame('Hopper', $reloaded->getShippingAddress()?->getLastName());
    }

    public function testEstimatesSnapshotIndependentlyOfOrders(): void
    {
        $book = $this->bookAddress();

        $estimate = (new Estimate())->setCompany($this->company)->setDocumentNumber('QT-' . uniqid());
        $estimate->setShippingAddressFrom($book);
        $this->em->persist($estimate);
        $this->em->flush();

        // The address moves after the quote was priced — the exact case that makes estimates need
        // this as much as orders.
        $book->setCity('Toronto')->setProvince('ON');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Estimate::class)->find($estimate->getId());

        self::assertSame('Vancouver', $reloaded->getShippingAddress()?->getCity());
        self::assertSame('BC', $reloaded->getShippingAddress()?->getProvince());
    }
}
