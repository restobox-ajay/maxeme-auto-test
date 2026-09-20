<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Inventory\ShippedQuantityProviderInterface;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\InvoiceShippingStatus;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\InvoiceShippingStatusDeriver;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What "shipping status is derived" means, one rule per test.
 *
 * A plain unit test, for the reason InvoicePaymentStatusDeriverTest is one: the rules are arithmetic
 * over quantities, and a database would add nothing but a way for the rules and the fixtures to
 * disagree about what was flushed. The collaborator that WOULD need a database — whoever knows what
 * shipped — is an interface here precisely so it does not, and the bundle's real implementation of
 * it is covered by ShippedQuantityProviderTest against the real schema.
 *
 * The degradation half is the point of the seam and gets the most cases: core alone answers from the
 * invoice's own status and must never invent Partially Shipped.
 */
final class InvoiceShippingStatusDeriverTest extends TestCase
{
    protected function setUp(): void
    {
        // No kernel here, and the fixtures below move an invoice's status through the gate, which
        // asks the vocabulary what exists. Primed with the real shipped provider, as
        // StatusVocabularyRegistry::loader()'s own message instructs.
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    // ── With nothing listening: core facts alone ────────────────────────────────────────────────

    public function testWithNoProviderAPendingInvoiceIsNotShipped(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        self::assertSame(InvoiceShippingStatus::NotShipped, $this->deriver()->statusFor($invoice));
    }

    public function testWithNoProviderACompletedInvoiceIsShipped(): void
    {
        $invoice = $this->completed($this->invoiceWithLine('10.0000'));

        self::assertSame(InvoiceShippingStatus::Shipped, $this->deriver()->statusFor($invoice));
    }

    public function testWithNoProviderNoInvoiceIsEverPartiallyShipped(): void
    {
        // The whole degradation contract in one assertion: core has no partial information, so it
        // must not produce a partial answer for ANY status it can reach. An invoice mid-fulfilment
        // reads Not Shipped rather than a guess at a middle value.
        foreach (['Draft', 'Pending', 'Processing', 'Completed', 'Cancelled'] as $status) {
            $invoice = $this->atStatus($status, '10.0000');

            self::assertSame($status, $invoice->getStatus());
            self::assertNotSame(
                InvoiceShippingStatus::PartiallyShipped,
                $this->deriver()->statusFor($invoice),
                sprintf('core alone must never derive Partially Shipped, and did for %s', $status),
            );
        }
    }

    public function testAProviderWhoseBundleIsInactiveIsNotAsked(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        // The provider would say the line is fully shipped. Its bundle is switched off, so the
        // answer is the degraded one — this is what App Management's Inactive has to mean, and the
        // gate is the only thing making it mean it.
        $deriver = new InvoiceShippingStatusDeriver(
            [$this->providerSaying(['10.0000'])],
            $this->bundleStatus(active: false),
        );

        self::assertSame(InvoiceShippingStatus::NotShipped, $deriver->statusFor($invoice));
    }

    // ── With a provider listening ───────────────────────────────────────────────────────────────

    public function testNothingShippedIsNotShipped(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        self::assertSame(
            InvoiceShippingStatus::NotShipped,
            $this->deriverWith(['0'])->statusFor($invoice),
        );
    }

    public function testSomeOfTheLineIsPartiallyShipped(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        self::assertSame(
            InvoiceShippingStatus::PartiallyShipped,
            $this->deriverWith(['4.0000'])->statusFor($invoice),
        );
    }

    public function testTheWholeLineIsShipped(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        self::assertSame(
            InvoiceShippingStatus::Shipped,
            $this->deriverWith(['10.0000'])->statusFor($invoice),
        );
    }

    public function testAPendingInvoiceFullyShippedReadsShippedWithoutTouchingItsStatus(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        self::assertSame(InvoiceShippingStatus::Shipped, $this->deriverWith(['10.0000'])->statusFor($invoice));
        // The two axes stay independent: deriving the goods answer never moved the lifecycle one.
        self::assertSame('Pending', $invoice->getStatus());
    }

    public function testACompletedInvoiceReadsShippedEvenWhenOnlyPartOfItWasPicked(): void
    {
        // Completed is a RULE, not a fallback: it is read before any provider is asked, so a bundle
        // being active cannot repeal core's own statement that the goods went. The shipment rows
        // refine the answer BELOW Completed — Not Shipped vs Partially Shipped — and never drag a
        // Completed invoice back down. Owner, 2026-09-17: "Bundle on or off completed is shipped."
        $invoice = $this->completed($this->invoiceWithLines(['5.0000', '5.0000']));

        self::assertSame(
            InvoiceShippingStatus::Shipped,
            $this->deriverWith(['5.0000', '0'])->statusFor($invoice),
        );
    }

    public function testACompletedInvoiceWithNoShipmentRowsAtAllStillReadsShippedWithAProviderActive(): void
    {
        // The back catalogue. Every invoice completed before Shipment existed carries no
        // ShipmentLine and never will, and a completion whose auto-shipment refused
        // (InvoiceShippingRemainderSubscriber logs and swallows a ShipmentException rather than
        // failing the status write) is in the same position. Asking the rows first would restate all
        // of them as unshipped the moment the bundle was switched on.
        $invoice = $this->completed($this->invoiceWithLines(['5.0000', '5.0000']));

        self::assertSame(
            InvoiceShippingStatus::Shipped,
            $this->deriverWith(['0', '0'])->statusFor($invoice),
        );
    }

    public function testOneLineOvershippedDoesNotCoverAnotherLineThatNeverLeft(): void
    {
        // Capped per line, not summed across the invoice: 12 + 0 against 6 + 6 totals to "enough"
        // and is not Shipped, because a whole line is still owed.
        $invoice = $this->invoiceWithLines(['6.0000', '6.0000']);

        self::assertSame(
            InvoiceShippingStatus::PartiallyShipped,
            $this->deriverWith(['12.0000', '0'])->statusFor($invoice),
        );
    }

    public function testEveryProviderIsSummedForTheSameLine(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');

        $deriver = new InvoiceShippingStatusDeriver(
            [$this->providerSaying(['6.0000']), $this->providerSaying(['4.0000'])],
            $this->bundleStatus(active: true),
        );

        self::assertSame(InvoiceShippingStatus::Shipped, $deriver->statusFor($invoice));
    }

    public function testFractionalQuantitiesSurviveTheComparison(): void
    {
        // 0.4 of a case shipped against 1 billed is partial, not "nothing". Rounded to whole units
        // this reads 0 and the invoice would sit on Not Shipped with goods gone — which is what a
        // shipment bundle used to do to its own guard, until it was moved onto the scaling this
        // class has always done.
        $invoice = $this->invoiceWithLine('1.0000');

        self::assertSame(
            InvoiceShippingStatus::PartiallyShipped,
            $this->deriverWith(['0.4000'])->statusFor($invoice),
        );
    }

    public function testAFractionalLineFullyShippedIsShippedRatherThanPartial(): void
    {
        // The other half of the same rule: 1.6 of 1.6 is done. Rounded, the shipped side reads 2
        // against a billed 2 and happens to agree — but 0.5 of 0.5 would round to 1 against 1 and
        // 0.4 of 0.4 to 0 against 0, so agreement there is luck, not arithmetic.
        $invoice = $this->invoiceWithLine('1.6000');

        self::assertSame(
            InvoiceShippingStatus::Shipped,
            $this->deriverWith(['1.6000'])->statusFor($invoice),
        );
    }

    public function testTenthsDoNotDriftTheWayFloatsWould(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point, which is why both sides are scaled to whole
        // ten-thousandths before comparing. Read as floats this line is short forever.
        $invoice = $this->invoiceWithLine('0.3000');

        $deriver = new InvoiceShippingStatusDeriver(
            [$this->providerSaying(['0.1000']), $this->providerSaying(['0.2000'])],
            $this->bundleStatus(active: true),
        );

        self::assertSame(InvoiceShippingStatus::Shipped, $deriver->statusFor($invoice));
    }

    public function testAZeroQuantityLineNeitherWaitsOnAShipmentNorCountsAsOne(): void
    {
        // A zero-quantity row (a correction, a discount line) is not goods. It must not hold an
        // otherwise fully shipped invoice at Partially Shipped forever.
        $invoice = $this->invoiceWithLines(['4.0000', '0.0000']);

        self::assertSame(
            InvoiceShippingStatus::Shipped,
            $this->deriverWith(['4.0000', '0'])->statusFor($invoice),
        );
    }

    public function testAnInvoiceWithNothingBilledFallsBackToItsOwnStatus(): void
    {
        // Nothing to ship, so the shipment rows answer nothing, and the invoice's own status is the
        // only fact left — the same answer it would give with no provider at all.
        $empty = $this->invoice();
        self::assertSame(InvoiceShippingStatus::NotShipped, $this->deriverWith([])->statusFor($empty));

        self::assertSame(InvoiceShippingStatus::Shipped, $this->deriverWith([])->statusFor($this->completed($empty)));
    }

    // ── recalculate() ───────────────────────────────────────────────────────────────────────────

    public function testRecalculateWritesTheTransitionOntoTheTimeline(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');
        $before = $invoice->getLogs()->count();

        self::assertTrue($this->deriverWith(['10.0000'])->recalculate($invoice));
        self::assertSame(InvoiceShippingStatus::Shipped, $invoice->getShippingStatus());
        self::assertCount($before + 1, $invoice->getLogs());
        self::assertSame(
            'Shipping status changed from Not Shipped to Shipped.',
            $invoice->getLogs()->last()->getComment(),
        );
    }

    public function testRecalculateIsSilentWhenNothingMoved(): void
    {
        $invoice = $this->invoiceWithLine('10.0000');
        $before = $invoice->getLogs()->count();

        self::assertFalse($this->deriverWith(['0'])->recalculate($invoice));
        self::assertCount($before, $invoice->getLogs(), 'A no-op recalculation must not write a timeline entry.');
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────

    /** The deriver as it is wired with no shipment bundle installed: nothing listening at all. */
    private function deriver(): InvoiceShippingStatusDeriver
    {
        return new InvoiceShippingStatusDeriver([], $this->bundleStatus(active: true));
    }

    /**
     * The deriver with one active provider answering $quantities, one per invoice line in order.
     *
     * @param list<string> $quantities
     */
    private function deriverWith(array $quantities): InvoiceShippingStatusDeriver
    {
        return new InvoiceShippingStatusDeriver(
            [$this->providerSaying($quantities)],
            $this->bundleStatus(active: true),
        );
    }

    /**
     * A provider that answers positionally: the nth line asked about gets the nth quantity.
     *
     * Positional rather than keyed by line, because these lines are never persisted and have no id
     * to key on — which is also why the collaborator is an interface rather than the real query.
     *
     * @param list<string> $quantities
     */
    private function providerSaying(array $quantities): ShippedQuantityProviderInterface
    {
        return new class($quantities) implements ShippedQuantityProviderInterface {
            /** @var array<int, string> keyed by spl_object_id(), so a line asked about twice answers twice the same */
            private array $answers = [];

            /** @param list<string> $quantities */
            public function __construct(private array $quantities)
            {
            }

            public function shippedQuantityForInvoiceLine(\App\Entity\InvoiceLine $invoiceLine): string
            {
                $key = spl_object_id($invoiceLine);

                return $this->answers[$key] ??= (string) (array_shift($this->quantities) ?? '0');
            }
        };
    }

    /**
     * A stub rather than a mock: nothing here asserts HOW the gate is called, only what it answers,
     * and a mock with no expectations is a PHPUnit notice this suite fails on.
     */
    private function bundleStatus(bool $active): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActiveForInstance')->willReturn($active);

        return $repo;
    }

    /**
     * Walks $invoice to Completed the way the application does.
     *
     * Pending -> Processing -> Completed, one legal step at a time: Invoice refuses Pending straight
     * to Completed, and a fixture that reached it by any other route would be testing a state the
     * application cannot produce.
     */
    private function completed(Invoice $invoice): Invoice
    {
        $this->processing($invoice)->setStatus('Completed', DocumentActor::system());

        return $invoice;
    }

    /** Pending -> Processing, the first of the two legal steps to Completed. */
    private function processing(Invoice $invoice): Invoice
    {
        $invoice->setStatus('Processing', DocumentActor::system());

        return $invoice;
    }

    /** An invoice holding one line of $quantity, walked to $status by its legal route. */
    private function atStatus(string $status, string $quantity): Invoice
    {
        $invoice = $this->draftWithLines([$quantity]);

        // setStatus() returns the status string, not the document, so each route is written out
        // rather than chained.
        match ($status) {
            'Draft' => null,
            'Cancelled' => $invoice->setStatus('Cancelled', DocumentActor::system()),
            'Pending' => $invoice->issue(DocumentActor::system()),
            'Processing' => $this->processing($invoice->issue(DocumentActor::system())),
            'Completed' => $this->completed($invoice->issue(DocumentActor::system())),
            default => throw new \LogicException('No legal route written for ' . $status),
        };

        return $invoice;
    }

    private function invoice(): Invoice
    {
        return (new Invoice())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setDocumentNumber('INV-1')
            ->setTotal('100.00')
            ->issue(DocumentActor::system());
    }

    private function invoiceWithLine(string $quantity): Invoice
    {
        return $this->invoiceWithLines([$quantity]);
    }

    /** @param list<string> $quantities */
    private function invoiceWithLines(array $quantities): Invoice
    {
        return $this->draftWithLines($quantities)->issue(DocumentActor::system());
    }

    /** @param list<string> $quantities */
    private function draftWithLines(array $quantities): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setDocumentNumber('INV-1')
            ->setTotal('100.00');

        foreach ($quantities as $index => $quantity) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setName('Widget ' . $index)
                    ->setSku('SKU-' . $index)
                    ->setQuantity($quantity)
                    ->setPrice('10.00'),
            );
        }

        return $invoice;
    }
}
