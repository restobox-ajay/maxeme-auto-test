<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Contract\Document\CommercialDocument;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every document in the application implements `CommercialDocument`, or is excluded by name and
 * with a reason (#636).
 *
 * ## What went wrong without this
 *
 * `CommercialDocument` was written in core in #555 and implemented by two of the eight documents
 * that existed a year later — both of them in the bundle that wrote it. Nothing failed. Each new
 * document simply reinvented the vocabulary (`orderNumber`, `receiptNumber`, `number`;
 * `vendorName`, a live `Company`, nothing at all), and anything wanting to treat documents
 * uniformly — document prefixes (#615), a document search, an audit trail, a PDF renderer — had to
 * special-case eight classes.
 *
 * ## Why this test discovers its subjects instead of listing them
 *
 * A conformance test naming its subjects in an array passes forever while the ninth document
 * quietly skips the rule, which is exactly how the repo got to two of eight. So the subjects come
 * out of Doctrine's own metadata, and a document that did not exist when this file was written is
 * a subject the moment it is mapped. #637 (RFQ) and #638 (vendor return, debit memo) will each add
 * one; none of them has to remember this test exists, and each fails here until it decides.
 *
 * The exclusions below are the opposite of a subject list, and the distinction matters. Discovery
 * is what makes a NEW document fail; the exclusions are the small, argued set of documents that
 * have already been examined and found to state no money. A name goes in only with a reason beside
 * it and a matching argument in the entity's own docblock, and each excluded class is asserted NOT
 * to implement the contract, so an entry that stops being true fails just as loudly as a missing
 * one.
 *
 * ## What counts as a document
 *
 * A mapped entity that owns a collection of rows: a to-many association whose target class is a
 * `*Line`. That is the shape of every document in the application and of nothing else — a cart
 * holds `CartItem`s and is deliberately not a document, a pick list holds `PickTask`s, a movement
 * group holds `InventoryMovement`s.
 *
 * It leans on the `*Line` naming convention, which ten line entities out of ten follow, and it is
 * fair to ask what happens if someone names their rows `FooRow`. Then this test does not see the
 * document — but the convention it broke is visible in review in a way a missing interface never
 * was, and every `*Line` document from that day on is still caught. Discovery that covers the
 * convention beats a list that covers nothing.
 */
final class EveryDocumentDeclaresItsContractTest extends KernelTestCase
{
    /**
     * Documents that state no money owed between two parties, with the reason each is exempt.
     *
     * Most are here because they only move goods. `Rfq` is here for a different reason — it has no
     * single counterparty to owe anything TO — and the entries say which case each one is, because
     * "not commercial" collapses two arguments that fail in different ways: a goods document gains
     * a total the day somebody prices it, whereas an RFQ never gains a counterparty no matter what
     * is priced. Reading them as one rule is how the wrong one gets copied onto the next document.
     *
     * This is the seam where a goods/logistical contract attaches when it is picked up — the owner
     * parked it rather than declining it — and a `GoodsReceipt` is expected to implement THAT
     * one and `CommercialDocument`, since goods received but not yet billed is a real liability
     * (the three-way match on /admin/bundles/procurement/exceptions is that liability made
     * visible). Interfaces and not abstract bases, precisely so a document can be both.
     *
     * Nothing else may be added here without the same two things: a sentence saying what money the
     * document does not state, and the matching argument in the entity's own docblock.
     *
     * @var array<class-string, string>
     */
    private const NOT_COMMERCIAL = [
        'ProcurementBundle\Entity\GoodsReceipt' =>
            'states what arrived, never what is owed for it — the money is the VendorBill it is'
            . ' matched against, and a receipt is deliberately allowed against no purchase order at'
            . ' all, so there is no price to copy even in principle',
        'WarehouseOpsBundle\Entity\TransferOrder' =>
            'has no counterparty at all: both ends are warehouses this company owns, so there is'
            . ' nobody to owe anything and no currency or total to report',
        'ProcurementBundle\Entity\VendorReturn' =>
            'states what is going back to the vendor, never what is owed for it — the money is the'
            . ' DebitMemo raised against it, which is a separate document precisely because a return'
            . ' can be settled by replacement instead of by credit and then no money moves at all',
        'ProcurementBundle\Entity\Rfq' =>
            'is exempt for a different reason from the goods documents above, and the difference is'
            . ' the point: it names a REQUIREMENT put to several vendors at once, so it has no single'
            . ' counterparty to report, and no currency or total until one of them answers. The'
            . ' document that does state those is one vendor\'s priced reply, RfqVendorReply, and'
            . ' that one implements the contract',
        'InventoryDepthBundle\Entity\Shipment' =>
            'states what left the building, never what is owed for it — the money is the Invoice(s)'
            . ' it ships against, and a combined shipment can name several of them for one customer,'
            . ' so there is no single total or currency to copy even in principle',
    ];

    public function testEveryDocumentImplementsTheCommercialContractOrIsAnArguedException(): void
    {
        $offenders = [];

        foreach ($this->documentClasses() as $class) {
            if (is_a($class, CommercialDocument::class, true) || isset(self::NOT_COMMERCIAL[$class])) {
                continue;
            }

            $offenders[] = $class;
        }

        self::assertSame([], $offenders, sprintf(
            "These documents implement neither %s nor an argued exception:\n  %s\n\n"
            . "Implement the contract if the document states money owed between two parties: a"
            . " number, a date, a counterparty, a currency and a total. Every one of the six must"
            . " come from state the document already stores — #636 is an interface change with no"
            . " migration behind it, and null is a legitimate answer for a currency or a total the"
            . " document genuinely does not state.\n\n"
            . "If it only moves goods, add it to NOT_COMMERCIAL with a sentence saying what money it"
            . " does not state, and put the same argument in the entity's docblock where the next"
            . " person reads it. An exception with no reason beside it is the silence this test"
            . " exists to break.",
            CommercialDocument::class,
            implode("\n  ", $offenders),
        ));
    }

    /**
     * An exception that stopped being true is as bad as a missing one: it reads as a considered
     * decision while describing something that is no longer the case. When the goods documents get
     * their own contract and a receipt implements both, this is the line that says "take the name
     * out of the list".
     */
    public function testTheArguedExceptionsAreStillExceptions(): void
    {
        $documents = $this->documentClasses();

        foreach (self::NOT_COMMERCIAL as $class => $why) {
            // Both live in optional bundles, and deleting a bundle is a supported act. An entry for
            // a bundle that is not installed is not a stale entry — there is nothing to check.
            if (!class_exists($class)) {
                continue;
            }

            self::assertContains($class, $documents, sprintf(
                '%s is listed as a document that states no money, but it is not a document any more'
                . ' (or was renamed). Remove the entry rather than leaving it to describe nothing.',
                $class,
            ));

            self::assertFalse(is_a($class, CommercialDocument::class, true), sprintf(
                '%s now implements %s, so the exception recorded here — "%s" — is out of date.'
                . ' Remove it from NOT_COMMERCIAL.',
                $class,
                CommercialDocument::class,
                $why,
            ));

            self::assertNotSame('', trim($why), sprintf('The exception for %s has no reason beside it.', $class));
        }
    }

    /**
     * Guards the discovery itself: a conformance test whose subject list quietly empties out passes
     * instantly and proves nothing, which is the failure BundlesOffPairingTest exists to catch in
     * the CI gate. If the `*Line` convention is ever abandoned wholesale, this is what says so.
     */
    public function testTheDiscoveryFindsDocumentsToCheck(): void
    {
        $classes = $this->documentClasses();

        self::assertNotEmpty($classes, 'No documents discovered at all — the mapping or the *Line convention changed under this test.');

        $commercial = array_values(array_filter($classes, static fn (string $c): bool => is_a($c, CommercialDocument::class, true)));

        self::assertNotEmpty($commercial, 'Nothing implements CommercialDocument. It was written in core and left unimplemented there for a year once already.');
    }

    /**
     * Every mapped document class, discovered from Doctrine.
     *
     * @return list<class-string>
     */
    private function documentClasses(): array
    {
        self::bootKernel();

        $factory = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory();

        $classes = [];
        foreach ($factory->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass) {
                continue;
            }

            $name = $metadata->getName();

            // A line that points at another line is a row, not a header: InvoiceLine owns the credit
            // rows raised against it. Asking a row to be a document would be asking the wrong object.
            if ($this->isLineClass($name)) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $mapping) {
                if ($mapping->isToMany() && $this->isLineClass($mapping->targetEntity)) {
                    $classes[] = $name;

                    continue 2;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    private function isLineClass(string $class): bool
    {
        $short = str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;

        return str_ends_with($short, 'Line');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
