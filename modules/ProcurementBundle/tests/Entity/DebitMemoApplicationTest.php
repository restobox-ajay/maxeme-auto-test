<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Entity;

use App\Service\DocumentActor;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;

/**
 * DebitMemo::applyTo() and VendorBill::getBalance() (#771) — the buy-side mirror of
 * App\Tests\Entity\CreditMemoBalanceTest and the two new cases it grew for #770.
 *
 * Before this, `VendorBill::getBalance()` only looked at payment rows, so a bill fully debited by
 * a memo still read its full total owed, and `DebitMemo::applyTo()` capped an application only at
 * the memo's OWN remaining balance — never the bill's — so a second memo could be booked against a
 * bill already fully covered.
 *
 * Plain entities, no database: the rules under test are the two objects' own arithmetic and their
 * gate, not persistence.
 */
final class DebitMemoApplicationTest extends TestCase
{
    public function testApplyingAMemoInFullReducesTheBillsBalanceToZero(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, '100.00');
        $memo = $this->openMemo($vendor, '100.00');

        $memo->applyTo($bill, '100.00');

        self::assertSame('100.00', $bill->getAmountDebited());
        self::assertSame('0.00', $bill->getBalance());
        self::assertTrue($bill->isSettled());
        self::assertTrue($bill->hasPaidAnything(), 'applied debit counts as money for Partially/Not Paid purposes');
    }

    public function testAPartialPaymentAndAPartialDebitBothCountTowardTheBalance(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, '100.00');
        $bill->recordPayment(DocumentActor::system(), $this->payment('30.00'));
        $this->openMemo($vendor, '100.00')->applyTo($bill, '40.00');

        self::assertSame('30.00', $bill->getAmountPaid());
        self::assertSame('40.00', $bill->getAmountDebited());
        self::assertSame('30.00', $bill->getBalance());
        self::assertFalse($bill->isSettled());
        self::assertTrue($bill->hasPaidAnything());
    }

    /** #771's second symptom: a second memo could still be booked against an already-covered bill. */
    public function testAMemoCannotApplyMoreThanTheBillHasLeft(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, '50.00');
        $memo = $this->openMemo($vendor, '1000.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Bill BILL-1 has a balance of $50.00. It cannot take a $100.00 debit.');

        $memo->applyTo($bill, '100.00');
    }

    public function testASecondMemoIsRefusedOnceTheBillIsFullyCovered(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, '100.00');
        $this->openMemo($vendor, '100.00')->applyTo($bill, '100.00');

        self::assertSame('0.00', $bill->getBalance());

        $second = $this->openMemo($vendor, '100.00');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('It cannot take a $100.00 debit');

        $second->applyTo($bill, '100.00');
    }

    private function vendor(): Vendor
    {
        return (new Vendor())->setName('Acme Supply')->setPaymentTerm('Net 30');
    }

    private function bill(Vendor $vendor, string $total): VendorBill
    {
        return (new VendorBill())->setBillNumber('BILL-1')->setVendor($vendor)->setVendorName($vendor->getName())->setTotal($total);
    }

    private function openMemo(Vendor $vendor, string $total): DebitMemo
    {
        $memo = (new DebitMemo())->setDocumentNumber('DM-' . uniqid())->setVendor($vendor)->setVendorName($vendor->getName())->setTotal($total);
        $memo->issue();

        return $memo;
    }

    private function payment(string $amount): \ProcurementBundle\Entity\VendorBillPayment
    {
        return (new \ProcurementBundle\Entity\VendorBillPayment())
            ->setPaidAt(new \DateTimeImmutable('2026-01-01'))
            ->setMethod('EFT')
            ->setAmount($amount);
    }
}
