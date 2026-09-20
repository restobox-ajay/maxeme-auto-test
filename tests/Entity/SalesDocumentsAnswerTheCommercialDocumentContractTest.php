<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Contract\Document\CommercialDocument;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesReturn;
use PHPUnit\Framework\TestCase;

/**
 * The sell side's `CommercialDocument` methods answer with the document's own stored state (#636).
 *
 * EveryDocumentDeclaresItsContractTest proves each document declares a contract; declaring one is
 * not answering it. Three of the six methods are adapters over fields that already existed under
 * side-specific names, and an adapter is exactly the kind of one-liner that can point at the wrong
 * field and be caught by nothing: `SalesOrder::getDocumentNumber()` has to return the order number
 * and `getCounterpartyName()` has to return the frozen snapshot rather than the live company.
 *
 * No database and no kernel: these are questions about an object's state, and every one of them is
 * answerable in memory.
 */
final class SalesDocumentsAnswerTheCommercialDocumentContractTest extends TestCase
{
    /** @return iterable<string, array{CommercialDocument, string}> */
    public static function salesDocuments(): iterable
    {
        yield 'invoice' => [(new Invoice())->setDocumentNumber('INV-1001'), 'INV-1001'];
        yield 'estimate' => [(new Estimate())->setDocumentNumber('QUO-1001'), 'QUO-1001'];
        yield 'credit memo' => [(new CreditMemo())->setDocumentNumber('CM-1001'), 'CM-1001'];
        // The one that stores its number under another name, and so the one adapter that can be wrong.
        yield 'sales order' => [(new SalesOrder())->setOrderNumber('SO-1001'), 'SO-1001'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('salesDocuments')]
    public function testTheDocumentNumberIsTheDocumentsOwnNumber(CommercialDocument $document, string $expected): void
    {
        self::assertSame($expected, $document->getDocumentNumber());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('salesDocuments')]
    public function testTheDocumentDateIsTheStoredCalendarDate(CommercialDocument $document, string $number): void
    {
        // Nothing has dated it yet: '' and not null, because the contract promises a string. The
        // stamp that fills the column tests for exactly this with trim().
        self::assertSame('', $document->getDocumentDate());

        $document->setDocumentDate('2026-03-04');

        self::assertSame('2026-03-04', $document->getDocumentDate());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('salesDocuments')]
    public function testTheCounterpartyIsTheFrozenSnapshotAndNotTheLiveCompany(CommercialDocument $document, string $number): void
    {
        self::assertInstanceOf(\App\Entity\AbstractSalesDocument::class, $document);

        $company = (new Company())->setName('Acme Distributing Ltd');
        $document->setCompany($company);

        self::assertSame('Acme Distributing Ltd', $document->getCounterpartyName());

        // The whole reason the snapshot exists: renaming the customer must not rewrite the document.
        $company->setName('Acme Holdings Inc');

        self::assertSame(
            'Acme Distributing Ltd',
            $document->getCounterpartyName(),
            'getCounterpartyName() read the live company, which would rewrite historical documents on a rebrand.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('salesDocuments')]
    public function testTheSellSideStatesNoCurrencyOfItsOwn(CommercialDocument $document, string $number): void
    {
        // Null, not 'CAD' and not 'USD'. The sell side is single-currency and names that currency
        // once in the base_currency AppSetting; a constant here would state a currency the document
        // was never raised in. A consumer resolves the null against the setting.
        self::assertNull($document->getCurrency());
    }

    public function testAQuoteWithNothingPricedReportsNoTotalRatherThanZero(): void
    {
        $estimate = new Estimate();

        self::assertNull($estimate->getTotal(), 'TBD and $0.00 are different states on a quote, and the contract keeps them apart.');

        $estimate->setTotal('149.50');

        self::assertSame('149.50', $estimate->getTotal());
    }

    public function testARaisedDocumentReportsItsTotalAsATwoPlaceDecimalString(): void
    {
        $invoice = (new Invoice())->setTotal('1250.00');

        self::assertSame('1250.00', $invoice->getTotal());
    }

    public function testAnRmaAnswersTheContractFromTheStateAnRmaActuallyHas(): void
    {
        $return = (new SalesReturn())->setDocumentNumber('RMA-1001');
        $return->setCompany((new Company())->setName('Acme Distributing Ltd'));

        self::assertInstanceOf(CommercialDocument::class, $return);
        self::assertSame('RMA-1001', $return->getDocumentNumber());
        self::assertSame('Acme Distributing Ltd', $return->getCounterpartyName());
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $return->getDocumentDate(), 'The RMA is dated the day it was requested.');
        self::assertCount(0, $return->getLines());
    }

    public function testAnRmaStatesNoMoney(): void
    {
        $return = new SalesReturn();

        // The whole reason #596 exists: goods can come back before, without, or in a different
        // period from the money. '0.00' would make a declined return indistinguishable from a
        // credited one, so the RMA says nothing and the credit memo says everything.
        self::assertNull($return->getTotal());
        self::assertNull($return->getCurrency());
    }
}
