<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SalesOrder;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Model\CompanyIdentity;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * An invoice records a transaction between two named parties. The buyer used to be read straight
 * through the live company foreign key, so renaming an account — rebrand, acquisition, or simply a
 * correction — rewrote the buyer's name, email and phone on every historical document.
 *
 * Same defect DocumentAddressSnapshotTest covers for addresses, on the header fields.
 */
final class CompanyIdentitySnapshotTest extends DoctrineIntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())
            ->setName('Acme Corp')
            ->setCode('ACME')
            ->setTradeName('Acme Trading')
            ->setPrimaryEmail('ap@acme.example')
            ->setPhoneNumber('+1 604 555 0101');

        $this->em->persist($this->company);
        $this->em->flush();
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

    private function estimate(): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('QT-' . uniqid());

        $this->em->persist($estimate);

        return $estimate;
    }

    public function testAttachingACompanyFreezesItsIdentity(): void
    {
        $order = $this->order();

        // Seeded by setCompany() rather than by each creation path, so a new one cannot forget.
        self::assertSame([
            'name' => 'Acme Corp',
            'tradeName' => 'Acme Trading',
            'email' => 'ap@acme.example',
            'phone' => '+1 604 555 0101',
        ], $order->getCompanySnapshot());
    }

    public function testRenamingTheCompanyDoesNotRewriteAnExistingOrder(): void
    {
        $order = $this->order();
        $this->em->flush();

        // The account is rebranded long after the order shipped.
        $this->company->setName('Globex Incorporated')->setTradeName('Globex');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());

        self::assertSame('Acme Corp', $reloaded->getCompanyIdentity()->getName(), 'the invoice must still name who was billed');
        self::assertSame('Acme Trading', $reloaded->getCompanyIdentity()->getTradeName());
        self::assertSame('Globex Incorporated', $reloaded->getCompany()->getName(), 'the live company is still there for links and filters');
    }

    public function testCorrectingContactDetailsDoesNotRewriteAnExistingOrder(): void
    {
        $order = $this->order();
        $this->em->flush();

        $this->company->setPrimaryEmail('accounts@globex.example')->setPhoneNumber('+1 778 555 9999');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());

        self::assertSame('ap@acme.example', $reloaded->getCompanyIdentity()->getEmail());
        self::assertSame('+1 604 555 0101', $reloaded->getCompanyIdentity()->getPhone());
    }

    public function testEstimatesFreezeIndependentlyOfOrders(): void
    {
        $estimate = $this->estimate();
        $this->em->flush();

        $this->company->setName('Globex Incorporated');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Estimate::class)->find($estimate->getId());

        self::assertSame('Acme Corp', $reloaded->getCompanyIdentity()->getName());
    }

    public function testReassigningTheCompanyLeavesTheSnapshotAlone(): void
    {
        $order = $this->order();
        $other = (new Company())->setName('Someone Else Ltd')->setCode('OTHER');
        $this->em->persist($other);

        // Moving an order between accounts is a correction to the relationship, not a claim that the
        // document originally named the other party.
        $order->setCompany($other);

        self::assertSame('Acme Corp', $order->getCompanyIdentity()->getName());
        self::assertSame('Someone Else Ltd', $order->getCompany()->getName());
    }

    public function testReFreezingIsExplicit(): void
    {
        $order = $this->order();
        $this->company->setName('Globex Incorporated');

        $order->snapshotCompany();

        self::assertSame('Globex Incorporated', $order->getCompanyIdentity()->getName());
    }

    public function testAnUnfrozenDocumentFallsBackToTheLiveCompany(): void
    {
        // Rows written before this column existed. The document must render, not blank out.
        $order = $this->order();
        $order->setCompanySnapshot(null);

        self::assertSame('Acme Corp', $order->getCompanyIdentity()->getName());
    }

    public function testAPartialSnapshotDegradesToNullRatherThanBreaking(): void
    {
        // The migration hand-writes this JSON, so a row may predate a field.
        $order = $this->order();
        $order->setCompanySnapshot(['name' => 'Legacy Co']);

        $identity = $order->getCompanyIdentity();

        self::assertSame('Legacy Co', $identity->getName());
        self::assertNull($identity->getTradeName());
        self::assertNull($identity->getEmail());
        self::assertNull($identity->getPhone());
    }

    public function testBlankFieldsAreStoredAsNullNotEmptyStrings(): void
    {
        $sparse = (new Company())->setName('Sparse Co')->setCode('SPARSE')->setTradeName('');
        $this->em->persist($sparse);

        $identity = CompanyIdentity::fromCompany($sparse);

        // Templates test these with {% if %}, so '' and null must not behave differently.
        self::assertNull($identity->getTradeName());
        self::assertNull($identity->getEmail());
    }

    public function testDisplayNamePrefersTheTradingName(): void
    {
        $order = $this->order();
        self::assertSame('Acme Trading', $order->getCompanyIdentity()->getDisplayName());

        $plain = (new Company())->setName('No DBA Ltd')->setCode('NODBA');
        self::assertSame('No DBA Ltd', CompanyIdentity::fromCompany($plain)->getDisplayName());
    }

    public function testTheSnapshotSurvivesARoundTripThroughTheDatabase(): void
    {
        $order = $this->order();
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(SalesOrder::class)->find($order->getId());

        // Guards the json column type: a string round-tripping as a string would break every read.
        self::assertIsArray($reloaded->getCompanySnapshot());
        self::assertSame('Acme Corp', $reloaded->getCompanyIdentity()->getName());
    }
}
