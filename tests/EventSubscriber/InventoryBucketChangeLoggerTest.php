<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\EventSubscriber\InventoryBucketChangeLogger;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The point of #582, tested the only way that means anything: by writing a bucket and calling
 * NOTHING else.
 *
 * Every test below moves a column on ProductInventory and flushes. No logger is invoked, no service
 * is asked to record anything, and the ProductInventory is written the same blunt way
 * StockMovementService and TransferOrderService write it — because those two were the ones that
 * silently logged nothing, and a test that goes through a service which remembers to log proves
 * only that the service remembers.
 *
 * The register throughout is "would this test fail if the listener were deleted", which is the
 * mutation check the issue asks for.
 */
final class InventoryBucketChangeLoggerTest extends DoctrineIntegrationTestCase
{
    private ProductCore $product;
    private Warehouse $warehouse;
    private InventoryOperationContext $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operations = self::getContainer()->get(InventoryOperationContext::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('BUCKET-1')->setName('Bucket Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    /** A settled row with every bucket at zero, so each test starts from a clean changeset. */
    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            $row = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(100);
            $this->em->persist($row);
            $this->em->flush();
        }

        return $row;
    }

    /** @return list<InventoryBucketChangeLog> */
    private function entries(): array
    {
        return $this->em->getRepository(InventoryBucketChangeLog::class)->findBy([], ['id' => 'ASC']);
    }

    public function testAWarehouseBucketWrittenWithNoLogCallAnywhereStillLandsInTheChangeLog(): void
    {
        // `received` is the bucket that had ZERO rows on the production database: the only thing
        // that ever logged it was an import zeroing it, never a receipt, transfer or adjustment
        // raising it. This is that gap, closed.
        $this->inventory()->setReceivedQuantity(12);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(1, $entries, 'a bucket write with no logger call must still produce exactly one change log row');
        self::assertSame(InventoryBucketChangeLog::BUCKET_RECEIVED, $entries[0]->getBucket(), 'the row names the column that moved');
        self::assertSame('0.0000', $entries[0]->getPreviousQuantity(), 'the previous value comes from the Doctrine changeset, not from the caller');
        self::assertSame('12.0000', $entries[0]->getNewQuantity());
        self::assertSame($this->product->getId(), $entries[0]->getProduct()->getId());
        self::assertSame($this->warehouse->getId(), $entries[0]->getWarehouse()->getId());
    }

    public function testEveryBucketColumnIsCoveredAndNotJustTheOnesThatHadCallers(): void
    {
        // Six of these had a caller that remembered to log; five did not. All eleven are columns on
        // product_inventory, so all eleven are logged — a bucket missing from the listener's table
        // is silent, which is the exact failure this issue is about.
        //
        // `write_off` and `transfer_in` are here because they landed (#581, #584) while this
        // listener was being written against a base that had neither, and the first draft shipped
        // covering nine of the eleven. That is the failure mode, caught late; see
        // testTheListenerCoversEveryBucketInTheAvailabilityFormula() below for the version of this
        // check that cannot be outrun by the next column.
        $this->inventory()
            ->setCartHoldQuantity(1)
            ->setSalesHoldQuantity(2)
            ->setPendingQuantity(3)
            ->setApprovedQuantity(4)
            ->setBackorderedQuantity(5)
            ->setReceivedQuantity(6)
            ->setIncomingQuantity(7)
            ->setQuarantineQuantity(8)
            ->setTransferOutQuantity(9)
            ->setTransferInQuantity(10)
            ->setWriteOffQuantity(11);
        $this->em->flush();

        $buckets = array_map(static fn (InventoryBucketChangeLog $entry): string => $entry->getBucket(), $this->entries());
        sort($buckets);

        self::assertSame([
            InventoryBucketChangeLog::BUCKET_APPROVED,
            InventoryBucketChangeLog::BUCKET_BACKORDERED,
            InventoryBucketChangeLog::BUCKET_CART_HOLD,
            InventoryBucketChangeLog::BUCKET_INCOMING,
            InventoryBucketChangeLog::BUCKET_PENDING,
            InventoryBucketChangeLog::BUCKET_QUARANTINE,
            InventoryBucketChangeLog::BUCKET_RECEIVED,
            InventoryBucketChangeLog::BUCKET_SALES_HOLD,
            InventoryBucketChangeLog::BUCKET_TRANSFER_IN,
            InventoryBucketChangeLog::BUCKET_TRANSFER_OUT,
            InventoryBucketChangeLog::BUCKET_WRITE_OFF,
        ], $buckets, 'one row per bucket column that moved, with no column left out of the listener table');
    }

    /**
     * The check the list above cannot make: that the listener covers every bucket, derived from the
     * entity rather than restated here.
     *
     * The test above is a list of eleven names, and a list is exactly what went wrong — the listener
     * shipped covering nine buckets because `write_off` and `transfer_in` did not exist on the
     * branch it was written against, and no test could notice, because every test restated the same
     * nine. Two lists that have to be kept in step is the bug, not the fix.
     *
     * So this reads ProductInventory::getAvailableQuantity() and takes the answer from there.
     * Availability is the definition of "is this a bucket": a column that adds to or subtracts from
     * what a customer can buy is a number somebody will one day have to explain, and explaining it
     * is what the change log is for. `quantity` is excluded because it is the base the buckets
     * adjust — the client's own imported figure, which this app does not own and has no business
     * claiming to have moved.
     *
     * The assertion is one-directional on purpose. Every availability bucket MUST be logged; the
     * listener may additionally cover columns outside the formula, and does — `incoming` is listed
     * against the day something writes it, which is precisely the omission this guards against.
     */
    public function testTheListenerCoversEveryBucketInTheAvailabilityFormula(): void
    {
        $source = $this->availabilityFormulaSource();

        // Every `$this->somethingQuantity` the formula reads, in the order it reads them.
        preg_match_all('/\$this->(\w+Quantity)\b/', $source, $matches);
        $referenced = array_values(array_unique($matches[1]));

        self::assertNotEmpty($referenced, 'the availability formula must be readable, or this test proves nothing');

        // `quantity` is the base, not a bucket. Everything else in the formula is one.
        $buckets = array_values(array_filter($referenced, static fn (string $field): bool => $field !== 'quantity'));

        self::assertGreaterThanOrEqual(
            10,
            \count($buckets),
            'the availability formula was expected to read at least ten bucket columns; if it now reads fewer, this test has stopped testing anything',
        );

        $covered = array_keys($this->listenerBucketFields());

        foreach ($buckets as $field) {
            self::assertContains(
                $field,
                $covered,
                sprintf(
                    'ProductInventory::getAvailableQuantity() reads $this->%s, so it is a bucket, so it has to appear in '
                    . 'InventoryBucketChangeLogger::BUCKET_FIELDS. A bucket the listener does not know about changes silently, '
                    . 'which is the entire failure #582 exists to prevent. Add it there and give it a BUCKET_* constant.',
                    $field,
                ),
            );
        }
    }

    /** The body of ProductInventory::getAvailableQuantity(), read off the file it is declared in. */
    private function availabilityFormulaSource(): string
    {
        $method = new \ReflectionMethod(ProductInventory::class, 'getAvailableQuantity');
        $file = $method->getFileName();
        self::assertIsString($file);

        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    /**
     * InventoryBucketChangeLogger's private field => bucket table.
     *
     * Read by reflection rather than made public. The mapping is an implementation detail of the
     * listener and nothing but this test has any business reading it; widening the API so a test can
     * see it would be letting the test drive the design.
     *
     * @return array<string, string>
     */
    private function listenerBucketFields(): array
    {
        /** @var array<string, string> $fields */
        $fields = (new \ReflectionClass(InventoryBucketChangeLogger::class))->getConstant('BUCKET_FIELDS');

        return $fields;
    }

    public function testCartHoldIsLoggedLikeEveryOtherBucketWithNoOptOut(): void
    {
        // cart_hold was 55% of the table before any of this, and holds expire after 300 seconds, so
        // automatic logging makes it noisier still. The decision was to log it anyway: there is no
        // per-bucket opt-out to configure and no bucket the listener skips.
        $this->inventory()->setCartHoldQuantity(3);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(1, $entries, 'cart_hold is logged like everything else — noisiness is a retention question, not a recording one');
        self::assertSame(InventoryBucketChangeLog::BUCKET_CART_HOLD, $entries[0]->getBucket());
    }

    public function testARecomputeThatWritesBackTheSameNumberRecordsNothing(): void
    {
        $row = $this->inventory();
        $row->setPendingQuantity(4);
        $this->em->flush();
        self::assertCount(1, $this->entries(), 'the first write is a real change');

        // Exactly what the hourly recalc crons do to every bucket of every row on every run.
        $row->setPendingQuantity(4);
        $row->touch();
        $this->em->flush();

        self::assertCount(1, $this->entries(), 'writing back the value already there is not a change and must not produce a row — otherwise the hourly recalc buries the changes worth finding');
    }

    public function testANewInventoryRowCarryingAHoldIsAChangeFromZero(): void
    {
        // The reconcilers create the row and fill a bucket in one breath, so an insertion that is
        // not treated as a change would lose the FIRST hold on every product/warehouse pair.
        $row = (new ProductInventory())
            ->setProduct($this->product)
            ->setWarehouse($this->warehouse)
            ->setQuantity(50)
            ->setSalesHoldQuantity(7);
        $this->em->persist($row);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(1, $entries, 'a bucket filled on a brand new row is still a bucket change');
        self::assertSame(InventoryBucketChangeLog::BUCKET_SALES_HOLD, $entries[0]->getBucket());
        self::assertSame('0.0000', $entries[0]->getPreviousQuantity(), 'a row that did not exist held nothing');
        self::assertSame('7.0000', $entries[0]->getNewQuantity());
    }

    public function testANewInventoryRowWithEmptyBucketsRecordsNothing(): void
    {
        $this->em->persist((new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(50));
        $this->em->flush();

        self::assertSame([], $this->entries(), 'creating a stock row holds nothing, so there is nothing to record — `quantity` is not a bucket');
    }

    public function testTheAmbientOperationNamesTheChangeRatherThanTheCallSite(): void
    {
        $this->operations->run('goods_received', function (): void {
            $this->inventory()->setReceivedQuantity(20);
            $this->em->flush();
        });

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('goods_received', $entries[0]->getAction(), 'the action comes from the operation that was open, which is the one thing the changeset cannot supply');
    }

    public function testTheInnermostOperationWinsWhenTheyNest(): void
    {
        // A transfer receipt opens its own operation and calls StockMovementService, which opens
        // another inside it. Both describe the same job at different scopes; the nearer description
        // is the more specific one.
        $this->operations->run('transfer_received', function (): void {
            $this->operations->run('movement_transfer', function (): void {
                $this->inventory()->setTransferOutQuantity(4);
                $this->em->flush();
            });
        });

        self::assertSame('movement_transfer', $this->entries()[0]->getAction(), 'the innermost open operation describes the change most precisely');
    }

    public function testABucketMovedWithNoOperationOpenIsRecordedAsUnattributedRatherThanDropped(): void
    {
        // Honest, not silent. Dropping the row would put the log straight back to being incomplete,
        // which is the whole complaint; 'unattributed' is a greppable list of the writers nobody
        // has described yet.
        $this->inventory()->setQuarantineQuantity(2);
        $this->em->flush();

        self::assertSame(
            InventoryOperationContext::UNATTRIBUTED,
            $this->entries()[0]->getAction(),
            'a bucket write outside any operation is still a real change and must still be recorded',
        );
    }

    public function testTheLoggedInUserIsCreditedWhenTheOperationDeclaresNoActor(): void
    {
        $admin = (new AdminUser())->setFirstName('Ada')->setLastName('Admin')->setEmail('ada@example.com')->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($admin, 'admin', $admin->getRoles()),
        );

        $this->operations->run('manual_adjustment', function (): void {
            $this->inventory()->setApprovedQuantity(9);
            $this->em->flush();
        });

        self::assertSame('ada@example.com', $this->entries()[0]->getTriggeredBy(), 'with nobody named by the operation, the person whose session did it is the honest answer');
    }

    public function testTriggeredByFallsBackToSystemWithNobodyLoggedIn(): void
    {
        $this->inventory()->setCartHoldQuantity(1);
        $this->em->flush();

        self::assertSame('System', $this->entries()[0]->getTriggeredBy(), 'the same default InventoryBucketAuditLogger used before the listener existed');
    }

    public function testAnOperationMayDeclareItsOwnActorInsteadOfTheSecurityToken(): void
    {
        // A movement carries who performed it — a scan gun operator, a command — and that beats
        // "whoever's session posted the form".
        $this->operations->run('movement_receipt', function (): void {
            $this->inventory()->setReceivedQuantity(5);
            $this->em->flush();
        }, 'receiving-desk-2');

        self::assertSame('receiving-desk-2', $this->entries()[0]->getTriggeredBy());
    }

    public function testAChangeWithNoPhysicalOperationBehindItHasANullGroup(): void
    {
        // NULL means "no stock moved", by construction rather than by omission: a hold, a
        // reconcile and an import rebaseline never open an operation carrying a movement group.
        $this->operations->run('order_reconciled', function (): void {
            $this->inventory()->setSalesHoldQuantity(6);
            $this->em->flush();
        });

        self::assertNull($this->entries()[0]->getGroupId(), 'a document reserving stock has not moved any, so there is no group to point at');
    }

    public function testAFailingOperationDoesNotLeaveItsNameStandingForTheNextChange(): void
    {
        // Matters more here than in a request-per-process world: a Messenger consumer or a long
        // import loop would otherwise attribute everything after a throw to an operation that died.
        try {
            $this->operations->run('doomed_operation', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->inventory()->setPendingQuantity(1);
        $this->em->flush();

        self::assertSame(
            InventoryOperationContext::UNATTRIBUTED,
            $this->entries()[0]->getAction(),
            'run() pops in a finally, so a thrown operation cannot leak its name into whatever happens next',
        );
    }
}
