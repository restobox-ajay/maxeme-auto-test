<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Entity;

use App\Contract\Document\CommercialDocument;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\AbstractPurchaseDocument;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;

/**
 * Every purchase document conforms to `CommercialDocument`, and a NEW one that skips the contract
 * fails here rather than being discovered by whatever first tries to treat documents uniformly.
 *
 * This is #636's "enumerating test" idea applied to the half of the map #637/#638 actually build.
 * It **enumerates the classes from the filesystem** rather than holding a hand-kept list, which is
 * the whole point — #639 is explicit that "conformance tests enumerate; they never hold a
 * hand-kept list", because a list is exactly what the next document forgets to join.
 *
 * ## Scope, and how it relates to the two tests that now sit above it
 *
 * This covers the BUY side only, and it is no longer the only conformance test in the codebase —
 * #636 landed the sell side and brought two of its own. They ask different questions and all three
 * are worth having:
 *
 *  - `App\Tests\Architecture\EveryDocumentDeclaresItsContractTest` asks, of every document in the
 *    application, whether it implements the contract OR is an argued exception. It is the widest
 *    net and the one a new document trips first. `Rfq` and `VendorReturn` are registered exceptions
 *    there, for the two different reasons recorded on that list.
 *  - `App\Tests\Entity\SalesDocumentsAnswerTheCommercialDocumentContractTest` is that test's sell-
 *    side counterpart.
 *  - This one is the buy-side counterpart, and it is the only one of the three that constructs each
 *    document and calls all six methods. Declaring the interface and answering it are different
 *    claims: a subclass can satisfy `is_subclass_of()` and still fatal on `getLines()`.
 *
 * ## Two documents deliberately do NOT appear, and that is an argument rather than an omission
 *
 * `PurchaseReceipt` and `VendorReturn` are goods documents: they record what physically moved, carry
 * no total and no currency, and state no money owed. #636 asked for exactly this call to be made
 * explicitly — "a receipt is arguably not a *commercial* document (it moves goods, it does not state
 * money owed), and that argument should be made explicitly rather than by omission" — and it now is,
 * in `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`, where a reason is required beside each
 * name. The sell side agrees with the call: `SalesReturn` extends nothing either.
 *
 * `Rfq` is the third: it names a REQUIREMENT put to several vendors at once, so it has no single
 * counterparty, no currency and no total of its own. The document that does — one vendor's priced
 * answer — is `RfqVendorReply`, and it is in the list below.
 */
final class EveryPurchaseDocumentConformsTest extends TestCase
{
    /**
     * Every concrete `AbstractPurchaseDocument` subclass in the bundle, found on disk.
     *
     * @return list<class-string<AbstractPurchaseDocument>>
     */
    private function purchaseDocuments(): array
    {
        $found = [];

        foreach (glob(__DIR__ . '/../../src/Entity/*.php') ?: [] as $file) {
            $class = 'ProcurementBundle\\Entity\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AbstractPurchaseDocument::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * The enumeration itself, asserted by name as well as by count.
     *
     * Named explicitly on purpose: deriving the expectation from the same glob the test uses would
     * assert only that globbing is deterministic. A new purchase document lands here as a failure
     * with its own class name in the diff, which is the message somebody adding one needs.
     */
    public function testTheBuySideDocumentsAreTheOnesExpected(): void
    {
        self::assertSame(
            [DebitMemo::class, PurchaseOrder::class, RfqVendorReply::class, VendorBill::class],
            $this->purchaseDocuments(),
            'a new AbstractPurchaseDocument subclass must be added here deliberately — and must satisfy '
            . 'every assertion below before it is',
        );
    }

    /** Nothing may extend the base without implementing the contract the base declares. */
    public function testEveryPurchaseDocumentImplementsCommercialDocument(): void
    {
        foreach ($this->purchaseDocuments() as $class) {
            self::assertTrue(
                is_subclass_of($class, CommercialDocument::class),
                sprintf('%s extends AbstractPurchaseDocument but does not satisfy CommercialDocument', $class),
            );
        }
    }

    /**
     * The six methods actually answer on a freshly constructed instance.
     *
     * `getDocumentNumber()` and `getLines()` are the two the base deliberately does NOT define — see
     * AbstractPurchaseDocument's docblock on the DQL aliasing footgun and on mapped superclasses not
     * being able to parametrize `targetEntity` — so they are the two a new subclass can forget, and
     * a class that forgot either would not have loaded at all. This proves they RETURN something of
     * the declared shape rather than merely existing.
     */
    public function testEveryPurchaseDocumentAnswersTheWholeContract(): void
    {
        $vendor = (new Vendor())->setName('Contract Test Supply')->setCurrency('CAD');

        foreach ($this->purchaseDocuments() as $class) {
            /** @var AbstractPurchaseDocument $document */
            $document = new $class();
            $document->setVendor($vendor);

            self::assertIsString($document->getDocumentNumber(), $class . '::getDocumentNumber()');
            self::assertSame('Contract Test Supply', $document->getCounterpartyName(), $class . '::getCounterpartyName() is the frozen snapshot, not a live join');
            self::assertSame('CAD', $document->getCurrency(), $class . '::getCurrency()');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $document->getDocumentDate(), $class . '::getDocumentDate() is a plain calendar date');
            self::assertSame('0.00', $document->getTotal(), $class . '::getTotal() starts at zero, as a decimal string');
            self::assertCount(0, $document->getLines(), $class . '::getLines() is an empty collection on a new document');
        }
    }
}
