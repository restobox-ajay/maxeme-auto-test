<?php

declare(strict_types=1);

namespace ProcurementBundle\Command;

use App\Command\Demo\DemoDataCleaner;
use App\Command\Demo\DemoSeed;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\ProductReceivingRule;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorBillPayment;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use ProcurementBundle\Inventory\IncomingStockReconciler;
use ProcurementBundle\Match\ThreeWayMatchService;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;
use ProcurementBundle\Status\VendorBillStatusDeriver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 2 of `app:seed-warehouse-data`: the buy side — vendors, purchase orders in every status, the
 * goods receipts that put them there, and the vendor bills that disagree with them.
 *
 * Lives in the bundle because core may not name a bundle class, a rule this bundle pins itself in
 * ProcurementPackagingTest::testCoreDoesNotReadAnyProcurementTable. See
 * App\Command\SeedWarehouseDataCommand for how the three steps are strung together without core
 * naming any of them.
 *
 * ## Which API each part drives, and why it cannot just INSERT
 *
 * | Part           | Driven through                                                | Why not a row |
 * |----------------|---------------------------------------------------------------|---------------|
 * | PO lifecycle   | `PurchaseOrder::issue()/closeShort()/cancel()`                 | there is no `setStatus()`; each guards its from-state and writes its own log entry |
 * | PO status      | `PurchaseOrderStatusDeriver`, run inside `ReceivingService`    | Issued/Partially Received/Received are derived from what has been receipted, not chosen |
 * | goods receipt  | `ReceivingService::receive()`                                  | it allocates the receipt number, credits the PO lines, derives the PO status and posts the stock through StockMovementService — four things that have to agree |
 * | bill lifecycle | `VendorBill::approve()/dispute()/void()/recordPayment()` | same, and the paid figure is the sum of the payment rows |
 * | bill status    | `VendorBillStatusDeriver::recalculate()`                       | Open/Partially Paid/Paid are derived from the money |
 * | exceptions     | nothing                                                        | see below |
 *
 * ## Exceptions are not rows
 *
 * A three-way-match exception has no table. `MatchLine` is a value object recomputed from the
 * purchase order, its receipts and the bill every time the screen is opened — deliberately, because
 * a stored verdict goes stale the moment a late delivery lands. There is therefore nothing here to
 * insert: the only way to make `/procurement/exceptions` non-empty is to create documents that
 * genuinely disagree, and let the matcher find them. All four kinds are seeded:
 *
 *  - `billed_not_received` and `price_variance` — a bill for 14 of something 12 of which arrived, at
 *    a unit cost above the one agreed;
 *  - `quantity_variance` — a receipt that differs from what was ordered;
 *  - `unmatched` — a bill line naming a SKU its purchase order never carried;
 *  - and the other half of the screen, `received not billed`, which is built from PO lines rather
 *    than from bills: one order is deliberately billed for less than arrived.
 *
 * ## The awkward receipts
 *
 * A PO over-received (12 arrived against 10 ordered, which nothing refuses because a vendor really
 * does send twelve); a PO part-received then closed short, which is what `closeShort()` is for and
 * why it insists on a reason where `cancel()` does not; and a receipt with no purchase order behind
 * it at all — samples, a warranty replacement, a delivery nobody raised paperwork for — which the
 * receiving screen has an explicit filter for and could not otherwise be reviewed against.
 *
 * That last one arrives with NO lot code against a lot-tracked product, deliberately. A tracking
 * policy never blocks a receipt: ReceivingService substitutes the policy's sentinel and flags the
 * row `expect_resolution`, and that flag is the tracking worklist's entire contents.
 */
#[AsCommand(
    name: 'app:seed-demo-procurement',
    description: 'Step 2 of app:seed-warehouse-data: demo vendors, purchase orders, receipts and vendor bills.',
)]
final class SeedProcurementDemoDataCommand extends Command
{
    private const WEST = 'Demo West';
    private const EAST = 'Demo East';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoDataCleaner $cleaner,
        private readonly BundleStatusRepository $bundles,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly ReceivingService $receiving,
        private readonly IncomingStockReconciler $incoming,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly VendorBillStatusDeriver $billStatus,
        private readonly ThreeWayMatchService $matcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['ProcurementBundle', 'InventoryDepthBundle'] as $bundle) {
            if (!$this->bundles->isActive($bundle)) {
                $io->error(sprintf('%s is Inactive; its screens are 404 and there is nothing to seed.', $bundle));

                return Command::FAILURE;
            }
        }

        if ($this->cleaner->hasProcurement()) {
            $io->error('Demo procurement data already exists. Run app:seed-warehouse-data --force instead.');

            return Command::FAILURE;
        }

        $west = $this->warehouses->warehouseForRegionName(self::WEST);
        $east = $this->warehouses->warehouseForRegionName(self::EAST);

        if (!$west instanceof Warehouse || !$east instanceof Warehouse) {
            $io->error('The demo warehouses do not exist. Run app:seed-demo-data first.');

            return Command::FAILURE;
        }

        $products = $this->products();
        if (\count($products) < 4) {
            $io->error('The demo dimensional products do not exist. Run app:seed-demo-depth first.');

            return Command::FAILURE;
        }

        $actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);

        $vendors = $this->seedVendors();
        $rules = $this->seedReceivingRules($products);
        $purchasing = $this->seedPurchasing($vendors, $products, $west, $east, $actor);
        $bills = $this->seedBills($purchasing['orders'], $actor);

        $io->writeln(sprintf('  %-36s %6d', 'vendor', \count($vendors)));
        $io->writeln(sprintf('  %-36s %6d', 'vendor_address', \count($vendors) * 2));
        $io->writeln(sprintf('  %-36s %6d', 'procurement_product_rule', $rules));
        $io->writeln(sprintf('  %-36s %6d', 'purchase_order', \count($purchasing['orders'])));
        $io->writeln(sprintf('  %-36s %6d', 'goods_receipt', $purchasing['receipts']));
        $io->writeln(sprintf('  %-36s %6d', 'vendor_bill', $bills['bills']));
        $io->writeln(sprintf('  %-36s %6d', 'vendor_bill_line', $bills['lines']));

        return Command::SUCCESS;
    }

    /** @return array<string, ProductCore> the demo dimensional products, keyed by SKU */
    private function products(): array
    {
        $products = [];

        foreach ($this->em->getRepository(ProductCore::class)->findBy(['syncSource' => DemoSeed::PRODUCT_SYNC_SOURCE]) as $product) {
            if (str_starts_with($product->getSku(), 'DEMO-DIM-')) {
                $products[$product->getSku()] = $product;
            }
        }

        return $products;
    }

    /** @return array<string, WarehouseLocation> one warehouse's bins, keyed by code */
    private function bins(Warehouse $warehouse): array
    {
        $bins = [];

        foreach ($this->em->getRepository(WarehouseLocation::class)->findBy(['warehouse' => $warehouse]) as $bin) {
            $bins[$bin->getCode()] = $bin;
        }

        return $bins;
    }

    /** @return array<string, Vendor> */
    private function seedVendors(): array
    {
        $definitions = [
            ['DEMO-V-100', 'Steelhead Industrial Supply', 'orders@steelhead.example', 'Net 30', 'CAD', 'Active',
                ['1450 Kootenay Street', 'Vancouver', 'BC', 'V5K 5B8'], ['88 Annacis Parkway', 'Delta', 'BC', 'V3M 6R9']],
            ['DEMO-V-200', 'Cascade Safety Products', 'ap@cascadesafety.example', 'Net 45', 'CAD', 'Active',
                ['2200 Meadowvale Blvd', 'Mississauga', 'ON', 'L5N 6H7'], ['17 Bramalea Road', 'Brampton', 'ON', 'L6T 2W7']],
            ['DEMO-V-300', 'Pacific Tool Import Co.', 'sales@pacifictool.example', 'Net 60', 'USD', 'Inactive',
                ['915 Harbor Drive', 'Seattle', 'WA', '98104'], ['4 Terminal Way', 'Tacoma', 'WA', '98421']],
        ];

        $vendors = [];

        foreach ($definitions as [$account, $name, $email, $term, $currency, $status, $head, $ship]) {
            $vendor = (new Vendor())
                ->setName($name)
                ->setAccountNumber($account)
                ->setEmail($email)
                ->setPhone('604-555-1' . substr($account, -3))
                ->setPaymentTerm($term)
                ->setCurrency($currency)
                ->setStatus($status);

            $this->em->persist($vendor);

            // The vendor's own notes CLOB is gone (item 43); a note is a row with an author and a
            // date, exactly as it is on the customer side.
            $this->em->persist(
                (new VendorNote())
                    ->setVendor($vendor)
                    ->setUserName('Demo seeder')
                    ->setText('Demo vendor. Contact details are not real.'),
            );

            // Two addresses, one of them flagged default — `Vendor::getDefaultAddress()` falls back
            // to the first row, so a single-address vendor would never show whether the flag is
            // being honoured or merely coincidentally right.
            //
            // The purposes (#606) are split ACROSS the two rows for the same reason: the head office
            // takes the paperwork and the depot ships the goods, so a demo instance shows a purchase
            // order and a receipt naming two different places rather than the same one twice.
            foreach ([
                [$head, true, 'Head Office', ['order_to', 'remit_to']],
                [$ship, false, 'Shipping', ['ship_from', 'return_to']],
            ] as [$parts, $isDefault, $label, $purposes]) {
                [$line1, $city, $province, $postal] = $parts;

                $address = (new VendorAddress())
                    ->setLabel($label)
                    ->setAddressLine1($line1)
                    ->setCity($city)
                    ->setProvince($province)
                    ->setPostalCode($postal)
                    ->setCountry($province === 'WA' ? 'US' : 'CA')
                    ->setIsDefault($isDefault)
                    ->setIsOrderTo(\in_array('order_to', $purposes, true))
                    ->setIsRemitTo(\in_array('remit_to', $purposes, true))
                    ->setIsShipFrom(\in_array('ship_from', $purposes, true))
                    ->setIsReturnTo(\in_array('return_to', $purposes, true));

                $vendor->addAddress($address);
                $this->em->persist($address);
            }

            // Three contacts, which is what a real supplier has (#605) — and three RECORDS, not three
            // accounts: nothing here sets a password or a role, because `vendor_contact` has no
            // column for either.
            foreach ([
                ['Priya', 'Raman', 'Orders desk', 'orders@' . $this->emailDomain($email), true],
                ['Dana', 'Okafor', 'Accounts payable', 'ap@' . $this->emailDomain($email), false],
                ['Marc', 'Belanger', 'Territory rep', 'rep@' . $this->emailDomain($email), false],
            ] as [$first, $last, $jobTitle, $contactEmail, $isPrimary]) {
                $contact = (new VendorContact())
                    ->setFirstName($first)
                    ->setLastName($last)
                    ->setJobTitle($jobTitle)
                    ->setEmail($contactEmail)
                    ->setPhone('604-555-2' . substr($account, -3))
                    ->setIsPrimary($isPrimary);

                $vendor->addContact($contact);
                $this->em->persist($contact);
            }

            $note = (new VendorNote())
                ->setText('Demo note. Dated and attributed, which is the only kind a vendor has now.')
                ->setUserName('Demo seeder');
            $vendor->addNoteEntry($note);
            $this->em->persist($note);

            $vendors[$account] = $vendor;
        }

        $this->em->flush();

        return $vendors;
    }

    /** "orders@steelhead.example" -> "steelhead.example", so the demo contacts share the vendor's domain. */
    private function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'example' : substr($email, $at + 1);
    }

    /**
     * Per-product receiving requirements — the procurement settings screen's only rows.
     *
     * ONE row and one column, because item 67 took the other three away. A receiving rule now says
     * exactly one thing — whether the receiver must name the bin the goods went into — and the batch,
     * expiry and serial requirements are derived from the product's TRACKING POLICY, which is where
     * a user declares them and where every other screen already reads them from.
     *
     * That is the defect this replaced: the two were separate, receiving consulted only this table,
     * and nothing in the application ever wrote a row to it, so all four guards passed for every
     * product. The demo data is deliberately set up so a reviewer sees the working arrangement:
     * DEMO-DIM-5001 is on a lot policy AND needs a bin, so its receipt row demands both, from two
     * different places, without either being able to contradict the other.
     *
     * @param array<string, ProductCore> $products
     */
    private function seedReceivingRules(array $products): int
    {
        $rules = ['DEMO-DIM-5001'];

        foreach ($rules as $sku) {
            $this->em->persist(
                (new ProductReceivingRule())
                    ->setProduct($products[$sku])
                    ->setLocationRequired(true),
            );
        }

        $this->em->flush();

        return \count($rules);
    }

    /**
     * Purchase orders in every status, and the receipts that put most of them there.
     *
     * Only three of the six statuses are chosen: `Draft` is where a PO starts, and `Cancelled` and
     * `Closed` are deliberate human acts with their own methods. `Issued`, `Partially Received` and
     * `Received` are derived by PurchaseOrderStatusDeriver from what has been receipted, which
     * ReceivingService runs in the same transaction as the receipt. So this seeds receipts and lets
     * the statuses follow, exactly as the receiving screen does.
     *
     * @param array<string, Vendor>      $vendors
     * @param array<string, ProductCore> $products
     *
     * @return array{orders: array<string, PurchaseOrder>, receipts: int}
     */
    private function seedPurchasing(
        array $vendors,
        array $products,
        Warehouse $west,
        Warehouse $east,
        DocumentActor $actor,
    ): array {
        $westBins = $this->bins($west);
        $eastBins = $this->bins($east);
        $orders = [];
        $receipts = 0;

        // -- Draft: typed up, not sent. The only status a PO can still be edited in.
        $orders['draft'] = $this->purchaseOrder($vendors['DEMO-V-100'], $west, [
            ['DEMO-DIM-5004', '40.00', '21.4000'],
        ], 21, 7);

        // -- Cancelled: issued, then withdrawn before anything arrived. cancel() refuses a PO with
        //    goods against it — "close it short instead" — which is why this one has none.
        $orders['cancelled'] = $this->purchaseOrder($vendors['DEMO-V-300'], $west, [
            ['DEMO-DIM-5003', '4.00', '112.0000'],
        ], 30, 5);
        $orders['cancelled']->setStatus('Issued', $actor);
        $this->em->flush();
        $orders['cancelled']->setStatus('Cancelled', $actor, 'Purchase order cancelled: Vendor could not confirm the lead time.');
        $this->em->flush();

        // -- Issued with nothing received: the row that makes /purchase-orders/expected non-empty.
        $orders['issued'] = $this->purchaseOrder($vendors['DEMO-V-200'], $east, [
            ['DEMO-DIM-5002', '60.00', '7.6000'],
            ['DEMO-DIM-5004', '25.00', '21.4000'],
        ], 6, -9);
        $orders['issued']->setStatus('Issued', $actor);
        $this->em->flush();

        // -- Partially Received, carrying a lot that expires INSIDE 30 days. The expiring-soon filter
        //    needs something inside its window and something well outside it, or all it can be seen
        //    to do is return everything or nothing.
        $orders['partial'] = $this->purchaseOrder($vendors['DEMO-V-100'], $west, [
            ['DEMO-DIM-5001', '240.00', '9.2000'],
        ], 24, -4);
        $orders['partial']->setStatus('Issued', $actor);
        $this->em->flush();

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder(
                $orders['partial'],
                'PS-40118',
                'R. Mensah',
                'Two pallets of four. Balance to follow.',
                new \DateTimeImmutable('-18 days'),
                'demo-seed-po-partial-1',
            )->add(
                $products['DEMO-DIM-5001'],
                '120.00',
                $orders['partial']->getLines()->first(),
                'LOT-SEA-2609',
                new \DateTimeImmutable('+18 days'),
                null,
                $westBins['A-01'],
                '9.2000',
            ),
            $actor->displayName,
        );
        ++$receipts;

        // -- Received in full, split across two bins on one receipt, carrying a lot that expires in
        //    400 days. The split is the awkward case a bin-level screen has to add up: one product in
        //    two places, and one LOT in two places.
        $orders['received'] = $this->purchaseOrder($vendors['DEMO-V-100'], $west, [
            ['DEMO-DIM-5001', '160.00', '9.2000'],
        ], 40, -22);
        $orders['received']->setStatus('Issued', $actor);
        $this->em->flush();

        $line = $orders['received']->getLines()->first();
        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder(
                $orders['received'],
                'PS-39880',
                'R. Mensah',
                'Split across A-02 and B-01 — A-02 was full.',
                new \DateTimeImmutable('-36 days'),
                'demo-seed-po-received-1',
            )
                ->add($products['DEMO-DIM-5001'], '100.00', $line, 'LOT-SEA-2704', new \DateTimeImmutable('+400 days'), null, $westBins['A-02'], '9.2000')
                ->add($products['DEMO-DIM-5001'], '60.00', $line, 'LOT-SEA-2704', new \DateTimeImmutable('+400 days'), null, $westBins['B-01'], '9.2000'),
            $actor->displayName,
        );
        ++$receipts;

        // -- Over-received: 12 arrived against 10 ordered. Nothing refuses it, and
        //    PurchaseOrderLine::isOverReceived() is what the screen has to say so with. The PO still
        //    derives to Received, because isFullyReceived() asks >= rather than ==.
        $orders['over'] = $this->purchaseOrder($vendors['DEMO-V-300'], $west, [
            ['DEMO-DIM-5003', '10.00', '112.0000'],
        ], 33, -15);
        $orders['over']->setStatus('Issued', $actor);
        $this->em->flush();

        // A serial-tracked product: one line of one unit per serial, because a detail row carrying a
        // serial may never hold more than 1 — a rule InventoryDetail::setQuantity() enforces before
        // the transaction opens, so a single line of 12 would be refused outright.
        $overRequest = ReceivingRequest::againstPurchaseOrder(
            $orders['over'],
            'PS-39955',
            'R. Mensah',
            'Twelve arrived against ten ordered; vendor advised to credit or collect.',
            new \DateTimeImmutable('-29 days'),
            'demo-seed-po-over-1',
        );
        $overLine = $orders['over']->getLines()->first();
        for ($i = 1; $i <= 12; ++$i) {
            $overRequest->add(
                $products['DEMO-DIM-5003'],
                '1.00',
                $overLine,
                null,
                null,
                sprintf('TW-2026-%04d', 3100 + $i),
                $westBins['C-01'],
                '112.0000',
            );
        }
        $this->receiving->receive($overRequest, $actor->displayName);
        ++$receipts;

        // -- Part-received then closed short: the vendor discontinued the balance, so the outstanding
        //    quantity is taken off the document rather than left open forever. closeShort() insists on
        //    a reason, which is the whole difference between it and cancel().
        $orders['closed'] = $this->purchaseOrder($vendors['DEMO-V-200'], $east, [
            ['DEMO-DIM-5002', '200.00', '7.6000'],
        ], 52, -30);
        $orders['closed']->setStatus('Issued', $actor);
        $this->em->flush();

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder(
                $orders['closed'],
                'PS-39510',
                'L. Tran',
                null,
                new \DateTimeImmutable('-44 days'),
                'demo-seed-po-closed-1',
            )->add(
                $products['DEMO-DIM-5002'],
                '140.00',
                $orders['closed']->getLines()->first(),
                'LOT-NIT-2588',
                null,
                null,
                $eastBins['A-01'],
                '7.6000',
            ),
            $actor->displayName,
        );
        ++$receipts;

        // Closed AFTER the receipt, and this ordering is the whole point of the case: the receipt
        // derives the order to Partially Received, and closing it short is a human overriding that
        // with "the rest is never coming". Closed is not derivable, so the deriver leaves it alone
        // from here on — which is what stops a late delivery springing the document back open.
        $orders['closed']->closeShort($actor, 'Vendor discontinued the line; the balance will not ship.');
        $this->em->flush();

        // -- A receipt with no purchase order at all, and no lot code against a lot-tracked product.
        //    See this class's docblock: the sentinel row it produces IS the tracking worklist.
        $this->receiving->receive(
            ReceivingRequest::unordered(
                $vendors['DEMO-V-200'],
                $east,
                'PS-UNK-771',
                'L. Tran',
                'Arrived on the Cascade truck with no paperwork. Batch code to be confirmed.',
                new \DateTimeImmutable('-9 days'),
                'demo-seed-unordered-1',
                // `unidentified: true` is the receiver SAYING there is no batch code, which is what
                // this receipt has always meant and what item 67 made it say out loud. Without it
                // the delivery is now refused, because a blank identity box and a pallet that
                // genuinely arrived with no paperwork stopped being the same thing.
            )->add($products['DEMO-DIM-5002'], '24.00', null, null, null, null, $eastBins['RECV-01'], null, true),
            $actor->displayName,
        );
        ++$receipts;

        $this->em->flush();

        // The demo orders reach their statuses by calling the named actions on the entity directly,
        // which is the one path that does NOT run the reconciler — the screens do, and so does
        // receiving. Without this pass the seeded warehouses would read `incoming = 0` against six
        // open orders, which is the exact wrong impression to give of a column #597 is about to
        // build a reorder screen on. Recomputed from the orders, so it is one call per order and
        // the answer is the same one the screens would have written (#583).
        foreach ($orders as $order) {
            $this->incoming->reconcileForOrder($order);
        }

        return ['orders' => $orders, 'receipts' => $receipts];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $lines sku, quantity, unit cost */
    private function purchaseOrder(
        Vendor $vendor,
        Warehouse $warehouse,
        array $lines,
        int $orderedDaysAgo,
        int $expectedDaysAgo,
    ): PurchaseOrder {
        $products = $this->em->getRepository(ProductCore::class);

        $order = (new PurchaseOrder())
            ->setPoNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency($vendor->getCurrency())
            ->setDocumentDate($this->date($orderedDaysAgo))
            ->setExpectedDate($this->date($expectedDaysAgo))
            ->setPaymentTerm($vendor->getPaymentTerm())
            ->setNotes(DemoSeed::DOCUMENT_NOTE);

        $this->em->persist($order);

        $sortOrder = 0;
        foreach ($lines as [$sku, $quantity, $unitCost]) {
            $product = $products->findOneBy(['sku' => $sku]);

            $line = (new PurchaseOrderLine())
                ->setProduct($product)
                ->setName($product?->getName() ?? $sku)
                ->setSku($sku)
                ->setVendorSku(str_replace('DEMO-DIM-', 'V', $sku))
                ->setQuantityOrdered($quantity)
                ->setUnitCost($unitCost)
                ->setSubtotal(number_format((float) $quantity * (float) $unitCost, 2, '.', ''))
                ->setSortOrder($sortOrder++);

            $order->addLine($line);
            $this->em->persist($line);
        }

        // recalculateTotals() rather than a typed-in figure: the subtotal is the sum of the lines and
        // nothing else, and a total typed beside them is a second answer waiting to disagree.
        $order->setTax(number_format((float) $order->getSubtotal() * 0.05, 2, '.', ''));
        $order->recalculateTotals();

        $this->em->flush();

        return $order;
    }

    /**
     * Vendor bills in every status, three of which disagree with their paperwork.
     *
     * @param array<string, PurchaseOrder> $orders
     *
     * @return array{bills: int, lines: int}
     */
    private function seedBills(array $orders, DocumentActor $actor): array
    {
        $bills = 0;
        $lines = 0;

        // -- Clean: exactly what was ordered, at the price ordered, paid in full.
        $clean = $this->bill($orders['received'], 'INV-SH-77410', 34, [
            ['DEMO-DIM-5001', '160.00', '9.2000'],
        ]);
        $clean->approve($actor, $this->matcher->match($clean)->summary());
        $this->pay($clean, $actor, $clean->getTotal(), 'E-Transfer', 'Paid in full, EFT 9911.');
        $this->billStatus->recalculate($clean);
        $this->em->flush();
        ++$bills;
        ++$lines;

        // -- The exception: more billed than arrived, at more than the agreed price. Approved on
        //    purpose — the exceptions screen ignores Draft and Void bills, so a draft one would show
        //    the disagreement to nobody.
        $overbilled = $this->bill($orders['over'], 'INV-PT-2201', 26, [
            ['DEMO-DIM-5003', '14.00', '124.5000'],
        ]);
        $overbilled->approve($actor, $this->matcher->match($overbilled)->summary());
        $this->billStatus->recalculate($overbilled);
        $this->em->flush();
        ++$bills;
        ++$lines;

        // -- Disputed, which is a LIVE status: a disputed bill is still payable and still matched, it
        //    simply has somebody's objection recorded against it.
        //
        //    Its second line names a SKU the purchase order never carried, which is the fourth kind of
        //    exception: `unmatched`. That is not untidy data to be cleaned up later — it is a vendor
        //    billing for something nobody ordered, and it reads differently from a variance on
        //    something that was.
        $disputed = $this->bill($orders['closed'], 'INV-CS-5510', 40, [
            ['DEMO-DIM-5002', '200.00', '7.6000'],
            ['DEMO-DIM-5004', '10.00', '21.4000'],
        ]);
        $disputed->approve($actor, $this->matcher->match($disputed)->summary());
        $disputed->setStatus('Disputed', $actor, 'Billed for the full 200 but only 140 shipped before the line was discontinued.');
        $this->billStatus->recalculate($disputed);
        $this->em->flush();
        ++$bills;
        $lines += 2;

        // -- Partially paid, so the payables screen has a balance that is neither zero nor the total.
        //    Two rows rather than one running total (#658): a bill's money is itemised the way an
        //    invoice's is, and the paid figure is their sum.
        //
        //    It bills 80 of the 120 that arrived, also deliberately: the 40 nobody has billed for are
        //    what put a row on the OTHER half of the exceptions screen, `received not billed`, which
        //    is built from purchase order lines rather than from bills and would otherwise be empty.
        $partial = $this->bill($orders['partial'], 'INV-SH-77488', 16, [
            ['DEMO-DIM-5001', '80.00', '9.2000'],
        ]);
        $partial->approve($actor, $this->matcher->match($partial)->summary());
        $this->pay($partial, $actor, '400.00', 'Cheque', 'First instalment, cheque 4471.');
        $this->pay($partial, $actor, '200.00', 'E-Transfer', 'Second instalment pending the balance of the order.');
        $this->billStatus->recalculate($partial);
        $this->em->flush();
        ++$bills;
        ++$lines;

        // -- Draft: entered, not yet approved for payment, and counting for nothing in the match.
        $this->bill($orders['issued'], 'INV-CS-5602', 3, [
            ['DEMO-DIM-5002', '60.00', '7.6000'],
            ['DEMO-DIM-5004', '25.00', '21.4000'],
        ]);
        ++$bills;
        $lines += 2;

        // -- Void: entered against the wrong order and withdrawn. void() refuses a bill with money
        //    against it, which is why this one has none.
        $void = $this->bill($orders['draft'], 'INV-SH-77501', 12, [
            ['DEMO-DIM-5004', '40.00', '21.4000'],
        ]);
        $void->setStatus('Void', $actor, 'Bill voided: Entered against the wrong purchase order.');
        $this->em->flush();
        ++$bills;
        ++$lines;

        return ['bills' => $bills, 'lines' => $lines];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $lines sku, quantity, unit cost */
    private function bill(PurchaseOrder $order, string $vendorInvoiceNo, int $daysAgo, array $lines): VendorBill
    {
        $vendor = $order->getVendor();

        $bill = (new VendorBill())
            ->setBillNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_BILL))
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setPurchaseOrder($order)
            ->setVendorInvoiceNo($vendorInvoiceNo)
            ->setCurrency($order->getCurrency())
            ->setDocumentDate($this->date($daysAgo))
            ->setDueDate($this->date($daysAgo - 30))
            ->setNotes(DemoSeed::DOCUMENT_NOTE);

        $this->em->persist($bill);

        $orderLinesBySku = [];
        foreach ($order->getLines() as $orderLine) {
            $orderLinesBySku[(string) $orderLine->getSku()] = $orderLine;
        }

        $sortOrder = 0;
        foreach ($lines as [$sku, $quantity, $unitCost]) {
            $orderLine = $orderLinesBySku[$sku] ?? null;

            $line = (new VendorBillLine())
                // The per-row attribution is what the matcher walks. A line attached to the wrong
                // order row would quietly make a real variance disappear, and one attached to none
                // is its own exception rather than missing data.
                ->setPurchaseOrderLine($orderLine)
                ->setProduct($orderLine?->getProduct())
                ->setName($orderLine?->getName() ?? $sku)
                ->setSku($sku)
                ->setVendorSku($orderLine?->getVendorSku())
                ->setQuantity($quantity)
                ->setUnitCost($unitCost)
                ->setSubtotal(number_format((float) $quantity * (float) $unitCost, 2, '.', ''))
                ->setSortOrder($sortOrder++);

            $bill->addLine($line);
            $this->em->persist($line);
        }

        $bill->setTax(number_format((float) $bill->getSubtotal() * 0.05, 2, '.', ''));
        $bill->recalculateTotals();
        $this->em->flush();

        return $bill;
    }

    /**
     * One payment against a bill, through the same named action the screen uses (#658).
     *
     * Through the action and not by constructing a row and persisting it, for the reason every
     * other write in this seeder goes through one: the demo data should be reachable by the same
     * path a person's would be, so a rule that refuses something refuses it here too.
     */
    private function pay(VendorBill $bill, DocumentActor $actor, string $amount, string $method, string $comment): void
    {
        $payment = (new VendorBillPayment())
            ->setPaidAt(new \DateTimeImmutable())
            ->setMethod($method)
            ->setAmount($amount)
            ->setComment($comment);

        $bill->recordPayment($actor, $payment, $comment);
        $this->em->persist($payment);
    }

    private function date(int $daysAgo): string
    {
        return (new \DateTimeImmutable(sprintf('%+d days', -$daysAgo)))->format('Y-m-d');
    }
}
