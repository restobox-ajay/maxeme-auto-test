<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Payment;

use App\Payment\PaymentApplicationGuard;
use App\Service\DocumentActor;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPayment;
use ProcurementBundle\Enum\VendorBillStatus;
use ProcurementBundle\Status\ProcurementStatusVocabularyProvider;
use ProcurementBundle\Status\VendorBillStatusDeriver;

/**
 * The rules a move has to pass, and what a move does to the two documents (queue item 34).
 *
 * A pure unit test — no database — because every rule here is a decision made in memory before
 * anything is written, which is the same reason `PurchaseDocumentActionsTest` next door is one. The
 * conducted half lives in `tests/Functional/VendorBillPaymentMoveCest.php` and proves the rows
 * actually move; this proves the refusals refuse for the stated reason and that nothing is touched
 * when one fires.
 */
final class PaymentMoveTest extends TestCase
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

    private function vendor(string $name = 'Acme Supply'): Vendor
    {
        return (new Vendor())->setName($name)->setCurrency('CAD');
    }

    private function bill(Vendor $vendor, string $number, string $total = '900.00', string $currency = 'CAD'): VendorBill
    {
        $bill = (new VendorBill())
            ->setBillNumber($number)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setTotal($total);
        $bill->setCurrency($currency);
        $bill->approve(DocumentActor::system());

        return $bill;
    }

    private function payment(string $amount = '900.00'): VendorBillPayment
    {
        return (new VendorBillPayment())
            ->setPaidAt(new \DateTimeImmutable('2026-09-15'))
            ->setMethod('Cheque')
            ->setAmount($amount)
            ->setComment('Cheque 8801');
    }

    /**
     * The move itself: same underlying payment, both documents recalculated in opposite
     * directions, two timeline entries.
     */
    public function testAMovePreservesThePaymentAndMovesBothDerivedStatuses(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $vendor = $this->vendor();
        $losing = $this->bill($vendor, 'BILL-1');
        $gaining = $this->bill($vendor, 'BILL-2');

        $payment = $this->payment();
        $losing->recordPayment(DocumentActor::system(), $payment);
        $application = $losing->getApplications()->first();
        $deriver->recalculate($losing);
        self::assertSame(VendorBillStatus::Paid, $losing->getStatusEnum());
        self::assertSame(VendorBillStatus::Open, $gaining->getStatusEnum());

        $losing->moveApplication(DocumentActor::named('Priya'), $application, $gaining, 'Wrong bill');
        $deriver->recalculate($losing);
        $deriver->recalculate($gaining);

        // The SAME underlying payment, not a copy of it. Identity is the whole feature — the claim
        // row itself is free to be a new one, since queue item 34 never promised that row's id, only
        // the payment's date, method, reference and actor.
        self::assertCount(0, $losing->getApplications());
        self::assertCount(1, $gaining->getApplications());
        $newApplication = $gaining->getApplications()->first();
        self::assertSame($payment, $newApplication->getPayment());
        self::assertSame($gaining, $newApplication->getDocument());
        self::assertSame('2026-09-15', $newApplication->getAppliedAt()->format('Y-m-d'));
        self::assertSame('Cheque', $payment->getMethod());
        self::assertSame('Cheque 8801', $payment->getComment());

        self::assertSame(VendorBillStatus::Open, $losing->getStatusEnum(), 'the bill that lost the money is still Paid');
        self::assertSame(VendorBillStatus::Paid, $gaining->getStatusEnum(), 'the bill that gained it did not become Paid');
        self::assertSame('900.00', $losing->getBalance());
        self::assertSame('0.00', $gaining->getBalance());

        // Both timelines say where it went, signed by the actor who did it. Searched rather than
        // read off last(): the deriver writes its own "Status changed" entry after the move, and a
        // test pinned to the final entry would be asserting about the deriver.
        $lost = $this->logNamed($losing, 'moved to bill BILL-2');
        self::assertNotNull($lost, 'the losing bill\'s timeline does not say where the payment went');
        self::assertStringContainsString('Wrong bill', $lost);
        self::assertSame('Priya', $this->logUserFor($losing, 'moved to bill BILL-2'));

        $gained = $this->logNamed($gaining, 'moved in from bill BILL-1');
        self::assertNotNull($gained, 'the gaining bill\'s timeline does not say where the payment came from');
        self::assertStringContainsString('2026-09-15', $gained, 'the receiving timeline does not record the original date the money left');
    }

    /** The first timeline comment on $bill containing $needle, or null. */
    private function logNamed(VendorBill $bill, string $needle): ?string
    {
        foreach ($bill->getLogs() as $log) {
            if (str_contains((string) $log->getComment(), $needle)) {
                return (string) $log->getComment();
            }
        }

        return null;
    }

    private function logUserFor(VendorBill $bill, string $needle): ?string
    {
        foreach ($bill->getLogs() as $log) {
            if (str_contains((string) $log->getComment(), $needle)) {
                return $log->getUserName();
            }
        }

        return null;
    }

    /** @return array<string, array{string, \Closure(Vendor): VendorBill}> */
    public static function refusedTargets(): array
    {
        return [
            'a void bill' => ['is Void; it is owed nothing and cannot take a payment', static function (Vendor $vendor): VendorBill {
                $bill = (new VendorBill())->setBillNumber('BILL-VOID')->setVendor($vendor)->setVendorName($vendor->getName())->setTotal('900.00');
                $bill->setStatus('Void', DocumentActor::system(), 'Bill voided: Duplicate');

                return $bill;
            }],
            'another vendor' => ["would leave both parties' balances wrong", static function (): VendorBill {
                $stranger = (new Vendor())->setName('Other Supply')->setCurrency('CAD');
                $bill = (new VendorBill())->setBillNumber('BILL-OTHER')->setVendor($stranger)->setVendorName('Other Supply')->setTotal('900.00');
                $bill->approve(DocumentActor::system());

                return $bill;
            }],
            'a different currency' => ['does not convert between currencies', static function (Vendor $vendor): VendorBill {
                $bill = (new VendorBill())->setBillNumber('BILL-USD')->setVendor($vendor)->setVendorName($vendor->getName())->setTotal('900.00');
                $bill->setCurrency('USD');
                $bill->approve(DocumentActor::system());

                return $bill;
            }],
        ];
    }

    /**
     * The attribute and not the `@dataProvider` annotation: PHPUnit 12 no longer reads metadata out
     * of doc comments, and an annotation there is silently ignored — the test then runs once with no
     * arguments and errors, which is how this file first failed.
     *
     * @param \Closure(Vendor): VendorBill $makeTarget
     */
    #[DataProvider('refusedTargets')]
    public function testAnIllegalTargetIsRefusedAndNothingMoves(string $expected, \Closure $makeTarget): void
    {
        $vendor = $this->vendor();
        $source = $this->bill($vendor, 'BILL-1');
        $target = $makeTarget($vendor);

        $payment = $this->payment();
        $source->recordPayment(DocumentActor::system(), $payment);
        $application = $source->getApplications()->first();
        $logsBefore = $source->getLogs()->count();

        try {
            $source->moveApplication(DocumentActor::system(), $application, $target);
            self::fail('the move onto ' . $target->getBillNumber() . ' was allowed');
        } catch (\DomainException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            // The refusal names the document the person chose, so they are not left reading a
            // general statement about a bill they did not name.
            self::assertStringContainsString($target->getBillNumber(), $e->getMessage());
        }

        self::assertCount(1, $source->getApplications(), 'the claim left the source despite the refusal');
        self::assertSame($source, $application->getDocument(), 'the claim was re-pointed despite the refusal');
        self::assertCount(0, $target->getApplications(), 'the target gained a claim despite the refusal');
        self::assertSame($logsBefore, $source->getLogs()->count(), 'a refused move still wrote a timeline entry');
    }

    public function testAMoveOntoTheSameBillIsRefusedRatherThanSilentlyDoingNothing(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, 'BILL-1');
        $payment = $this->payment();
        $bill->recordPayment(DocumentActor::system(), $payment);
        $application = $bill->getApplications()->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('That payment is already on BILL-1.');

        $bill->moveApplication(DocumentActor::system(), $application, $bill);
    }

    public function testAPaymentOnAnotherBillIsRefusedBeforeAnyRuleReadsTheTarget(): void
    {
        $vendor = $this->vendor();
        $mine = $this->bill($vendor, 'BILL-1');
        $theirs = $this->bill($vendor, 'BILL-2');
        $target = $this->bill($vendor, 'BILL-3');

        $payment = $this->payment();
        $theirs->recordPayment(DocumentActor::system(), $payment);
        $application = $theirs->getApplications()->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('That payment is not on bill BILL-1.');

        $mine->moveApplication(DocumentActor::system(), $application, $target);
    }

    /**
     * A DRAFT bill may receive a payment, and the sell side's draft invoice may not.
     *
     * Pinned rather than left implicit, because it is the asymmetry this feature was told not to
     * flatten. A bill folds fulfilment and money into one status column and refuses to derive a
     * Draft at all, so a draft bill holding payments still reads Draft; an invoice carries a
     * separate derived payment status and a draft holding payments would read Paid.
     */
    public function testADraftBillAcceptsAMovedPaymentAndStaysADraft(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $vendor = $this->vendor();
        $source = $this->bill($vendor, 'BILL-1');
        $draft = (new VendorBill())->setBillNumber('BILL-DRAFT')->setVendor($vendor)->setVendorName($vendor->getName())->setTotal('900.00');

        self::assertSame(VendorBillStatus::Draft, $draft->getStatusEnum());
        self::assertTrue($draft->getStatusEnum()->acceptsPayment());
        self::assertNull($draft->paymentRefusal());

        $payment = $this->payment();
        $source->recordPayment(DocumentActor::system(), $payment);
        $application = $source->getApplications()->first();
        $source->moveApplication(DocumentActor::system(), $application, $draft);
        $deriver->recalculate($draft);

        self::assertSame($draft, $draft->getApplications()->first()->getDocument());
        self::assertSame('900.00', $draft->getAmountPaid());
        self::assertSame(VendorBillStatus::Draft, $draft->getStatusEnum(), 'a draft bill holding payments must not derive itself out of Draft');
    }

    /**
     * Overpayment is allowed and announced, rather than refused.
     *
     * See PaymentApplicationGuard's docblock for why: recording an overpayment is already allowed,
     * so a move that refused one would leave delete-and-rekey as the only route to the same state —
     * and that route loses the date, method, reference and actor this feature exists to preserve.
     */
    public function testOverpayingTheTargetIsAllowedAndAnnouncedInMoney(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $vendor = $this->vendor();
        $source = $this->bill($vendor, 'BILL-1');
        $small = $this->bill($vendor, 'BILL-2', '100.00');

        $payment = $this->payment('900.00');
        $source->recordPayment(DocumentActor::system(), $payment);
        $application = $source->getApplications()->first();
        $source->moveApplication(DocumentActor::system(), $application, $small);
        $deriver->recalculate($small);

        self::assertSame(VendorBillStatus::Paid, $small->getStatusEnum());
        self::assertSame('-800.00', $small->getBalance());

        $notice = PaymentApplicationGuard::overpaymentNotice($small);
        self::assertNotNull($notice);
        self::assertStringContainsString('BILL-2 is now OVERPAID by CAD 800.00', $notice);
        // Two decimals with the currency, even for a round total: getTotal() returns the raw column.
        self::assertStringContainsString('CAD 900.00 applied against a total of CAD 100.00', $notice);

        self::assertNull(PaymentApplicationGuard::overpaymentNotice($source), 'a bill with nothing paid against it is not overpaid');
    }

    /**
     * Two unsaved vendors are two counterparties.
     *
     * Not a curiosity: `getPaymentCounterpartyKey()` reads an id that is null until the row is
     * persisted, so a naive implementation makes every unsaved vendor the same counterparty and the
     * rule against moving money between parties passes on a pair it should refuse.
     */
    public function testTwoUnsavedVendorsAreNotTheSameCounterparty(): void
    {
        $one = $this->bill($this->vendor('A'), 'BILL-1');
        $two = $this->bill($this->vendor('B'), 'BILL-2');

        self::assertNotSame($one->getPaymentCounterpartyKey(), $two->getPaymentCounterpartyKey());
    }
}
