<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Numbering;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;

/**
 * Purchase document numbering (#555) — the same per-(kind, prefix) counter the sales side uses.
 *
 * The property worth a test rather than a comment is that the three kinds do not share a sequence.
 * They have separately configurable prefixes, so a shared counter would leave holes in all three —
 * and a hole in a document sequence is a question an auditor has to ask.
 */
final class PurchaseDocumentNumberGeneratorTest extends DoctrineIntegrationTestCase
{
    private PurchaseDocumentNumberGenerator $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = self::getContainer()->get(PurchaseDocumentNumberGenerator::class);
        self::getContainer()->get(AppSettings::class)->clearCache();
    }

    public function testEachKindHasItsOwnDefaultPrefix(): void
    {
        self::assertSame('PO-', $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
        self::assertSame('RC-', $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_RECEIPT));
        self::assertSame('BILL-', $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_BILL));
    }

    public function testNumbersIncrementWithinAKind(): void
    {
        $first = $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER);
        $second = $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER);

        self::assertSame('PO-1', $first);
        self::assertSame('PO-2', $second);
    }

    /** Separately configurable prefixes must mean separately counted sequences. */
    public function testTheThreeKindsDoNotShareASequence(): void
    {
        $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER);
        $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER);

        self::assertSame('RC-1', $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RECEIPT));
        self::assertSame('BILL-1', $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_BILL));
        self::assertSame('PO-3', $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
    }

    public function testAConfiguredPrefixIsUsed(): void
    {
        $this->em->persist(
            (new AppSetting())
                ->setSettingKey('purchase_order_number_prefix')
                ->setName('Purchase Order Number Prefix')
                ->setSettingValue('BUY/')
        );
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        self::assertSame('BUY/', $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
        self::assertSame('BUY/1', $this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
    }

    /**
     * An empty stored prefix falls back to the default rather than numbering documents "1".
     *
     * Not hypothetical: a settings form that posts a cleared field stores '' unless something turns
     * it into null, and bare integers as document numbers are not recoverable once issued.
     */
    public function testAnEmptyStoredPrefixFallsBackToTheDefault(): void
    {
        $this->em->persist(
            (new AppSetting())
                ->setSettingKey('vendor_bill_number_prefix')
                ->setName('Vendor Bill Number Prefix')
                ->setSettingValue('')
        );
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        self::assertSame('BILL-', $this->numbers->prefixFor(PurchaseDocumentNumberGenerator::KIND_BILL));
    }

    public function testAnUnknownKindIsRefusedRatherThanSilentlyNumbered(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->numbers->next($this->em, 'not_a_kind');
    }

    /** The settings screen must display the value the next allocation will actually use. */
    public function testTheSettingKeyMatchesWhatThePrefixIsReadFrom(): void
    {
        self::assertSame('purchase_order_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER));
        self::assertSame('purchase_receipt_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_RECEIPT));
        self::assertSame('vendor_bill_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_BILL));
        self::assertSame('rfq_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_RFQ));
        self::assertSame('rfq_vendor_reply_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_RFQ_REPLY));
        self::assertSame('vendor_return_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_VENDOR_RETURN));
        self::assertSame('debit_memo_number_prefix', $this->numbers->settingKeyFor(PurchaseDocumentNumberGenerator::KIND_DEBIT_MEMO));
        self::assertSame(
            ['purchase_order', 'purchase_receipt', 'vendor_bill', 'rfq', 'rfq_vendor_reply', 'vendor_return', 'debit_memo'],
            PurchaseDocumentNumberGenerator::kinds(),
        );
    }
}
