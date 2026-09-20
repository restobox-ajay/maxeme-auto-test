<?php

declare(strict_types=1);

namespace ProcurementBundle\Command;

use App\Command\Demo\DemoSeed;
use App\Command\Demo\DemoVolume;
use App\Command\Demo\DemoVolumeCheckpoint;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\DebitMemoLine;
use ProcurementBundle\Entity\DebitMemoRefund;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqLine;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\RfqVendorReplyLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorBillPayment;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Entity\VendorReturn;
use ProcurementBundle\Entity\VendorReturnLine;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Enum\RfqStatus;
use ProcurementBundle\Enum\VendorBillStatus;
use ProcurementBundle\Enum\VendorReturnStatus;
use ProcurementBundle\Inventory\IncomingStockReconciler;
use ProcurementBundle\Match\ThreeWayMatchService;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;
use ProcurementBundle\Rfq\RfqConversionService;
use ProcurementBundle\Status\VendorBillStatusDeriver;
use ProcurementBundle\VendorReturn\VendorReturnShipService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ProcurementBundle's step of `app:seed-demo-volume`: the whole buy side at volume — vendors with
 * their address books, contacts and notes; vendor prices; RFQs with vendor replies; purchase orders
 * in every status and the goods receipts that put them there; vendor bills; vendor returns; debit
 * memos.
 *
 * ## Which API each part drives
 *
 * The same ones the screens drive, in the same order, so every derived figure is the application's:
 *
 * | Part            | Driven through                                                                 |
 * |-----------------|--------------------------------------------------------------------------------|
 * | PO lifecycle    | `issue()/cancel()/closeShort()`, then IncomingStockReconciler (as the PO screen) |
 * | receipts        | `ReceivingService::receive()` — numbers the receipt, credits the PO lines, derives the PO status and posts dimensional stock through StockMovementService |
 * | bills           | `approve()` + VendorBillStatusDeriver, `recordPayment()` rows, `dispute()`, `void()` |
 * | RFQs            | `send()/expire()/cancel()`, `markReplied()/decline()`, RfqConversionService    |
 * | vendor returns  | `authorise()/decline()/close()`, VendorReturnShipService for the shipment      |
 * | debit memos     | `issue()`, `applyTo()`, `recordRefund()`, `void()`                             |
 *
 * Vendors, addresses, contacts, notes and vendor prices are master data with no lifecycle, and are
 * written as their controllers write them: entity setters and a flush.
 *
 * Tagged the DemoSeed way: every vendor's account number starts `DEMO-`, and every document here
 * hangs off one of those vendors, which is what DemoDataCleaner deletes by. RFQs, which reach a
 * vendor only through their replies, also carry DemoSeed::DOCUMENT_NOTE in their notes.
 */
#[AsCommand(
    name: 'app:seed-demo-volume:procurement',
    description: 'app:seed-demo-volume step: vendors, vendor prices, RFQs, POs, receipts, bills, vendor returns and debit memos.',
)]
final class SeedProcurementVolumeCommand extends Command
{
    private const BATCH = 20;

    /** @var array<string, array<string, int>> */
    private array $outcomes = [];

    /** @var array<int, array{mode: string, expiry: bool}> dimensional product id => tracking */
    private array $dimensional = [];

    /** @var list<int> */
    private array $simple = [];

    /** @var array<int, list<int>> warehouse id => pick/receiving bin ids */
    private array $bins = [];

    private DocumentActor $actor;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly BundleStatusRepository $bundles,
        private readonly DemoVolumeCheckpoint $checkpoint,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly ReceivingService $receiving,
        private readonly IncomingStockReconciler $incoming,
        private readonly VendorBillStatusDeriver $billStatus,
        private readonly ThreeWayMatchService $matcher,
        private readonly RfqConversionService $rfqConversion,
        private readonly VendorReturnShipService $returnShipping,
        private readonly InventoryDetailRepository $details,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many new rows of each object to add.', '200')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run token shared with the other steps.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, (int) $input->getOption('count'));
        $volume = new DemoVolume($input->getOption('run'), 'proc');
        $this->actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);
        $this->outcomes = [];

        foreach (['ProcurementBundle', 'InventoryDepthBundle'] as $bundle) {
            if (!$this->bundles->isActive($bundle)) {
                $io->error(sprintf('%s is Inactive; its screens are 404 and there is nothing to seed.', $bundle));

                return Command::FAILURE;
            }
        }

        $this->loadCatalogue();
        if ($this->bins === []) {
            $io->error('No warehouse has an active bin. Run app:seed-demo-volume:depth first.');

            return Command::FAILURE;
        }

        [$vendorIds, $activeVendorIds] = $this->seedVendors($count, $volume);
        $io->writeln(sprintf('  %-30s %6d', 'vendor', \count($vendorIds)));

        $prices = $this->seedVendorPrices($count, $volume, $activeVendorIds);
        $io->writeln(sprintf('  %-30s %6d', 'vendor_price', $prices));

        [$poIds, $receiptIds] = $this->seedPurchaseOrders($count, $volume, $activeVendorIds);
        $io->writeln(sprintf('  %-30s %6d', 'purchase_order', \count($poIds)));
        $io->writeln(sprintf('  %-30s %6d', 'goods_receipt', \count($receiptIds)));

        $billIds = $this->seedBills($count, $volume, $poIds, $activeVendorIds);
        $io->writeln(sprintf('  %-30s %6d', 'vendor_bill', \count($billIds)));

        $rfqs = $this->seedRfqs($count, $volume, $activeVendorIds);
        $io->writeln(sprintf('  %-30s %6d', 'rfq', $rfqs));

        [$returnIds, $notShipped, $returns] = $this->seedVendorReturns($count, $volume, $receiptIds);
        $io->writeln(sprintf('  %-30s %6d', 'vendor_return', $returns));
        if ($notShipped > 0) {
            $io->writeln(sprintf('  %-30s %6d', '  left Authorised (no stock)', $notShipped));
        }

        $memos = $this->seedDebitMemos($count, $volume, $returnIds, $billIds, $activeVendorIds);
        $io->writeln(sprintf('  %-30s %6d', 'debit_memo', $memos));

        foreach ($this->outcomes as $table => $statuses) {
            ksort($statuses);
            $io->writeln(sprintf('  %-14s %s', $table, implode(', ', array_map(
                static fn (string $s, int $n): string => sprintf('%s %d', $s, $n),
                array_keys($statuses),
                $statuses,
            ))));
        }

        ($this->checkpoint)();

        return Command::SUCCESS;
    }

    private function loadCatalogue(): void
    {
        $this->dimensional = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT p.id, COALESCE(t.mode, 'none') AS mode, COALESCE(t.requires_expiry, 0) AS expiry
             FROM product_core p LEFT JOIN tracking_policy t ON t.id = p.tracking_policy_id
             WHERE p.inventory_mode = 'dimensional' AND p.status = 'Active'
             ORDER BY p.id",
        ) as $row) {
            $this->dimensional[(int) $row['id']] = ['mode' => (string) $row['mode'], 'expiry' => (bool) $row['expiry']];
        }

        $this->simple = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM product_core WHERE inventory_mode = 'simple' AND status = 'Active' ORDER BY id",
        ));

        $this->bins = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, warehouse_id FROM warehouse_location WHERE status = 'Active' AND type IN ('pick', 'receiving') ORDER BY warehouse_id, sort_key",
        ) as $row) {
            $this->bins[(int) $row['warehouse_id']][] = (int) $row['id'];
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Vendors and vendor prices

    /** @return array{0: list<int>, 1: list<int>} all new vendor ids, the active ones */
    private function seedVendors(int $count, DemoVolume $volume): array
    {
        $accounts = array_flip(array_map('strval', $this->connection->fetchFirstColumn('SELECT account_number FROM vendor WHERE account_number IS NOT NULL')));
        $names = array_flip(array_map('strtolower', array_map('strval', $this->connection->fetchFirstColumn('SELECT name FROM vendor'))));
        $next = 1 + (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTR(account_number, 7) AS INTEGER)), 0) FROM vendor WHERE account_number LIKE 'DEMO-V%'",
        );

        $ids = [];
        $active = [];

        for ($i = 0; $i < $count; ++$i) {
            do {
                $name = trim(sprintf('%s %s %s', $volume->pick(DemoVolume::NAME_PREFIXES), $volume->pick(DemoVolume::VENDOR_TRADES), $volume->pick(DemoVolume::LEGAL_SUFFIXES)));
                if (isset($names[strtolower($name)])) {
                    $name .= ' ' . $volume->pick(['West', 'Pacific', 'Canada', 'Direct', 'Wholesale', 'International']);
                }
            } while (isset($names[strtolower($name)]));
            $names[strtolower($name)] = true;

            do {
                $account = sprintf('%sV%05d', DemoSeed::VENDOR_ACCOUNT_PREFIX, $next++);
            } while (isset($accounts[$account]));
            $accounts[$account] = true;

            $domain = DemoVolume::domainFor($name);
            $usd = $volume->chance(0.2);
            $status = $volume->chance(0.85) ? 'Active' : 'Inactive';

            $vendor = (new Vendor())
                ->setName($name)
                ->setAccountNumber($account)
                ->setEmail('orders@' . $domain)
                ->setPhone($volume->phone())
                ->setPaymentTerm($volume->pick(['Net 30', 'Net 30', 'Net 45', 'Net 60', 'Due on receipt', '2% 10 Net 30']))
                ->setCurrency($usd ? 'USD' : 'CAD')
                ->setStatus($status)
                ->setCreatedAt($volume->at($volume->int(120, 900)));
            $this->em->persist($vendor);

            // A vendor's notes are threaded rows since item 43 — the single `vendor.notes` CLOB is
            // gone, migrated into this table. Roughly one vendor in three gets one, as before.
            if ($volume->chance(0.3)) {
                $this->em->persist(
                    (new VendorNote())
                        ->setVendor($vendor)
                        ->setUserName('Purchasing')
                        ->setText($volume->pick([
                            'Minimum order $500.',
                            'Ships Tuesdays and Thursdays.',
                            'Freight free over $2,500.',
                            'Order cutoff 2pm PT.',
                        ])),
                );
            }

            // Head office takes the paperwork and the depot ships the goods — split across two rows
            // (#606) so a PO and a receipt name two different places. One vendor in four is a single
            // location carrying all four purposes.
            $single = $volume->chance(0.25);
            foreach ($single ? [['Head Office', true, [true, true, true, true]]] : [
                ['Head Office', true, [true, true, false, false]],
                [$volume->pick(['Distribution Centre', 'Warehouse', 'Depot']), false, [false, false, true, true]],
            ] as [$label, $isDefault, [$orderTo, $remitTo, $shipFrom, $returnTo]]) {
                [$line1, $city, $province, $postal] = $volume->canadianAddress();
                $address = (new VendorAddress())
                    ->setLabel($label)
                    ->setAddressLine1($line1)
                    ->setCity($city)
                    ->setProvince($province)
                    ->setPostalCode($postal)
                    ->setCountry('CA')
                    ->setIsDefault($isDefault)
                    ->setIsOrderTo($orderTo)
                    ->setIsRemitTo($remitTo)
                    ->setIsShipFrom($shipFrom)
                    ->setIsReturnTo($returnTo);
                $vendor->addAddress($address);
                $this->em->persist($address);
            }

            $roles = [['Orders desk', 'orders'], ['Accounts receivable', 'ar'], ['Territory rep', 'rep'], ['Warehouse lead', 'shipping']];
            foreach ($volume->sample($roles, $volume->int(1, 3)) as $n => [$jobTitle, $mailbox]) {
                $contact = (new VendorContact())
                    ->setFirstName($volume->firstName())
                    ->setLastName($volume->lastName())
                    ->setJobTitle($jobTitle)
                    ->setEmail($mailbox . '@' . $domain)
                    ->setPhone($volume->phone())
                    ->setIsPrimary($n === 0);
                $vendor->addContact($contact);
                $this->em->persist($contact);
            }

            for ($n = 0, $notes = $volume->weightedInt([0 => 40, 1 => 40, 2 => 20]); $n < $notes; ++$n) {
                $note = (new VendorNote())
                    ->setText($volume->pick([
                        'Rep visited; new price list effective next month.',
                        'Short-shipped twice this quarter. Confirm quantities before issuing large POs.',
                        'Agreed free freight on orders over $3,000.',
                        'Credit application approved at $25,000.',
                        'Switching to EDI for order acknowledgements.',
                    ]))
                    ->setUserName($volume->pick(['Purchasing', 'Jordan Mackay', 'Accounts payable']))
                    ->setCreatedAt($volume->at($volume->daysAgo()));
                $vendor->addNoteEntry($note);
                $this->em->persist($note);
            }

            $this->em->flush();
            $ids[] = (int) $vendor->getId();
            if ($status === 'Active') {
                $active[] = (int) $vendor->getId();
            }
            $this->tally('vendor', $status);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return [$ids, $active === [] ? $ids : $active];
    }

    /** @param list<int> $vendorIds */
    private function seedVendorPrices(int $count, DemoVolume $volume, array $vendorIds): int
    {
        $taken = [];
        foreach ($this->connection->fetchAllAssociative('SELECT vendor_id, product_id FROM vendor_price') as $row) {
            $taken[$row['vendor_id'] . ':' . $row['product_id']] = true;
        }

        $created = 0;
        for ($attempt = 0; $created < $count && $attempt < $count * 4; ++$attempt) {
            $vendorId = $volume->pick($vendorIds);
            $productId = $this->pickProduct($volume, 0.6);
            if (isset($taken[$vendorId . ':' . $productId])) {
                continue;
            }

            $vendor = $this->em->find(Vendor::class, $vendorId);
            $product = $this->em->find(ProductCore::class, $productId);
            if (!$vendor instanceof Vendor || !$product instanceof ProductCore) {
                continue;
            }

            // The shape VendorPriceController::save() writes: one current row per vendor and product.
            $this->em->persist(
                (new VendorPrice())
                    ->setVendor($vendor)
                    ->setProduct($product)
                    ->setVendorSku(sprintf('%s-%05d', strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $vendor->getName()) ?: 'V', 0, 3)), $volume->int(100, 99999)))
                    ->setUnitCost(number_format((float) ($product->getCostPrice() ?? 10) * $volume->float(0.88, 1.08), 4, '.', ''))
                    ->setCurrency($vendor->getCurrency())
                    ->setOrderMultiple($volume->pick([1, 1, 2, 4, 10, 25]))
                    ->setIsActive(!$volume->chance(0.08))
                    ->setNotes($volume->chance(0.15) ? 'Quoted by the rep; valid while stock lasts.' : null),
            );
            $taken[$vendorId . ':' . $productId] = true;
            ++$created;

            if ($created % 50 === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $created;
    }

    /** A product id: dimensional with probability $dimensional, else an existing simple one. */
    private function pickProduct(DemoVolume $volume, float $dimensional): int
    {
        if ($this->dimensional !== [] && ($this->simple === [] || $volume->chance($dimensional))) {
            return $volume->pick(array_keys($this->dimensional));
        }

        return $volume->pick($this->simple);
    }

    // ---------------------------------------------------------------------------------------------
    // Purchase orders and receipts

    /**
     * @param list<int> $vendorIds
     *
     * @return array{0: list<int>, 1: list<int>} purchase order ids, goods receipt ids
     */
    private function seedPurchaseOrders(int $count, DemoVolume $volume, array $vendorIds): array
    {
        $poIds = [];
        $receiptIds = [];
        $warehouseIds = array_keys($this->bins);

        for ($i = 0; $i < $count; ++$i) {
            $vendor = $this->em->find(Vendor::class, $volume->pick($vendorIds));
            $warehouse = $this->em->find(Warehouse::class, $volume->pick($warehouseIds));
            if (!$vendor instanceof Vendor || !$warehouse instanceof Warehouse) {
                continue;
            }

            $daysAgo = $volume->daysAgo();
            $lead = $volume->int(5, 25);
            $target = $daysAgo < $lead
                ? $volume->weighted(['Draft' => 35, 'Issued' => 50, 'Cancelled' => 15])
                : $volume->weighted(['Draft' => 5, 'Issued' => 10, 'Cancelled' => 8, 'Partial' => 20, 'Received' => 45, 'Closed' => 12]);

            $order = $this->purchaseOrder($vendor, $warehouse, $volume, $daysAgo, $lead);
            $poIds[] = (int) $order->getId();

            if ($target !== 'Draft') {
                $order->setStatus(PurchaseOrderStatus::Issued->value, $this->actor);
                $this->incoming->reconcileForOrder($order);
                $this->em->flush();
            }

            if ($target === 'Cancelled') {
                $order->setStatus(
                    PurchaseOrderStatus::Cancelled->value,
                    $this->actor,
                    sprintf('Purchase order cancelled: %s', $volume->pick(['Vendor could not confirm the lead time.', 'Raised in error.', 'Bought elsewhere at a better price.'])),
                );
                $this->incoming->reconcileForOrder($order);
                $this->em->flush();
            }

            if (\in_array($target, ['Partial', 'Received', 'Closed'], true)) {
                $arrivedDaysAgo = max(0, $daysAgo - $lead);
                $deliveries = match ($target) {
                    'Received' => $volume->weightedInt([1 => 50, 2 => 35, 3 => 15]),
                    'Partial' => $volume->weightedInt([1 => 70, 2 => 30]),
                    default => 1,
                };

                for ($d = 1; $d <= $deliveries; ++$d) {
                    $final = $target === 'Received' && $d === $deliveries;
                    $share = $final ? 1.0 : ($target === 'Received' ? 0.5 : $volume->float(0.25, 0.45));
                    $receipt = $this->receive($order, $volume, $share, $final && $volume->chance(0.05), max(0, $arrivedDaysAgo - ($d - 1) * $volume->int(3, 10)));
                    if ($receipt instanceof GoodsReceipt) {
                        $receiptIds[] = (int) $receipt->getId();
                    }
                }

                if ($target === 'Closed') {
                    $order->closeShort($this->actor, $volume->pick(['Vendor discontinued the line; the balance will not ship.', 'Balance back-ordered past the season; cancelled the rest.']));
                    $this->incoming->reconcileForOrder($order);
                    $this->em->flush();
                }
            }

            $this->tally('purchase_order', $order->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        // Deliveries nobody raised paperwork for — samples, warranty replacements. The receiving
        // screen has a filter for exactly these.
        // Also what tops the receipt count up to $count when recent, not-yet-due orders left it short.
        for ($u = 0, $unordered = max(intdiv($count, 10), $count - \count($receiptIds)); $u < $unordered; ++$u) {
            $receipt = $this->receiveUnordered($volume, $vendorIds, $warehouseIds);
            if ($receipt instanceof GoodsReceipt) {
                $receiptIds[] = (int) $receipt->getId();
            }
        }

        ($this->checkpoint)();

        return [$poIds, $receiptIds];
    }

    private function purchaseOrder(Vendor $vendor, Warehouse $warehouse, DemoVolume $volume, int $daysAgo, int $lead): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER))
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency($vendor->getCurrency())
            ->setDocumentDate($volume->date($daysAgo))
            ->setExpectedDate((new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->modify(sprintf('+%d days', $lead))->format('Y-m-d'))
            ->setPaymentTerm($vendor->getPaymentTerm())
            ->setNotes($volume->chance(0.3) ? $volume->pick(['Deliver to the receiving dock.', 'Confirm ship date by email.', 'Seasonal restock.', 'Rush — customer waiting.']) : null)
            ->setCreatedAt($volume->at($daysAgo));
        $this->em->persist($order);

        $sort = 0;
        $seen = [];
        for ($n = 0, $lines = $volume->weightedInt([1 => 30, 2 => 30, 3 => 25, 4 => 15]); $n < $lines; ++$n) {
            $productId = $this->pickProduct($volume, 0.65);
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;

            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $tracking = $this->dimensional[$productId] ?? null;
            $quantity = match (true) {
                $tracking !== null && $tracking['mode'] === 'serial' => $volume->int(2, 8),
                $tracking !== null => $volume->int(2, 20) * 10,
                default => $volume->int(2, 12) * 4,
            };
            $cost = (float) ($product->getCostPrice() ?? 10) * $volume->float(0.9, 1.02);

            $qty = QuantityScale::canonical($quantity);
            $line = (new PurchaseOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setVendorSku(sprintf('V-%s', substr((string) $product->getSku(), -6)))
                ->setQuantityOrdered($qty)
                ->setUnitCost(number_format($cost, 4, '.', ''))
                ->setSubtotal(number_format($quantity * round($cost, 4), 2, '.', ''))
                ->setSortOrder($sort++);
            $order->addLine($line);
            $this->em->persist($line);
        }

        $subtotal = 0.0;
        foreach ($order->getLines() as $line) {
            $subtotal += (float) $line->getSubtotal();
        }
        $order->setTax(number_format($subtotal * 0.05, 2, '.', ''));
        $order->recalculateTotals();
        $this->em->flush();

        return $order;
    }

    /**
     * One delivery against a PO, through ReceivingService — which numbers it, credits the lines,
     * derives the PO's status and posts dimensional stock (simple lines are recorded as paperwork
     * only, as the receiving screen records them).
     *
     * @param float $share fraction of each line's outstanding quantity that arrives (1.0 = all)
     */
    private function receive(PurchaseOrder $order, DemoVolume $volume, float $share, bool $over, int $daysAgo): ?GoodsReceipt
    {
        $warehouseId = (int) $order->getWarehouse()->getId();
        $request = ReceivingRequest::againstPurchaseOrder(
            $order,
            sprintf('PS-%05d', $volume->int(10000, 99999)),
            $volume->pick(DemoVolume::RECEIVERS),
            $volume->chance(0.2) ? $volume->pick(['Two pallets, shrink-wrap torn on one.', 'Driver arrived early.', 'Balance to follow.']) : null,
            $volume->at($daysAgo),
            $volume->operationId('receipt'),
        );

        foreach ($order->getLines() as $line) {
            $outstanding = (int) floor((float) $line->getQuantityOrdered() - (float) $line->getQuantityReceived());
            $units = $share >= 1.0 ? $outstanding : (int) floor($outstanding * $share);
            if ($over) {
                $units += 2;
            }
            $product = $line->getProduct();
            if ($units <= 0 || !$product instanceof ProductCore) {
                continue;
            }

            $this->addReceivedLine($request, $product, $units, $line, $warehouseId, $volume, $daysAgo, $line->getUnitCost());
        }

        if ($request->isEmpty()) {
            return null;
        }

        return $this->receiving->receive($request, DemoSeed::ACTOR_LABEL);
    }

    /** @param list<int> $vendorIds @param list<int> $warehouseIds */
    private function receiveUnordered(DemoVolume $volume, array $vendorIds, array $warehouseIds): ?GoodsReceipt
    {
        $vendor = $this->em->find(Vendor::class, $volume->pick($vendorIds));
        $warehouseId = $volume->pick($warehouseIds);
        $warehouse = $this->em->find(Warehouse::class, $warehouseId);
        if (!$vendor instanceof Vendor || !$warehouse instanceof Warehouse) {
            return null;
        }

        $daysAgo = $volume->daysAgo(200);
        $request = ReceivingRequest::unordered(
            $vendor,
            $warehouse,
            sprintf('PS-UNK-%04d', $volume->int(100, 9999)),
            $volume->pick(DemoVolume::RECEIVERS),
            $volume->pick(['Arrived with no paperwork.', 'Warranty replacement units.', 'Samples from the rep.']),
            $volume->at($daysAgo),
            $volume->operationId('unordered'),
        );

        $product = $this->em->find(ProductCore::class, $this->pickProduct($volume, 0.8));
        if (!$product instanceof ProductCore) {
            return null;
        }
        $this->addReceivedLine($request, $product, $volume->int(2, 12), null, $warehouseId, $volume, $daysAgo, null);

        return $this->receiving->receive($request, DemoSeed::ACTOR_LABEL);
    }

    /**
     * A line of a receipt, in the identity the product's tracking policy asks for: one line per
     * serial, a batch code (and an expiry where the policy wants one) per lot product. One lot
     * delivery in ten arrives with no batch code at all, which the service lands on the policy's
     * sentinel — the tracking worklist.
     */
    private function addReceivedLine(ReceivingRequest $request, ProductCore $product, int $units, ?PurchaseOrderLine $line, int $warehouseId, DemoVolume $volume, int $daysAgo, ?string $unitCost): void
    {
        $tracking = $this->dimensional[(int) $product->getId()] ?? null;
        $bin = $tracking !== null ? $this->em->find(WarehouseLocation::class, $volume->pick($this->bins[$warehouseId])) : null;

        if ($tracking !== null && $tracking['mode'] === 'serial') {
            for ($u = 0; $u < $units; ++$u) {
                $request->add($product, '1.00', $line, null, null, sprintf('SN-%s-%s', substr((string) $product->getSku(), -5), strtoupper(bin2hex(random_bytes(4)))), $bin, $unitCost);
            }

            return;
        }

        $lotCode = null;
        $expiry = null;
        if ($tracking !== null && $tracking['mode'] === 'lot' && !$volume->chance(0.1)) {
            $lotCode = sprintf('%s%s-%04d', $volume->pick(['LOT', 'B', 'L']), (new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->format('ym'), $volume->int(1, 9999));
            $expiry = $tracking['expiry'] ? new \DateTimeImmutable(sprintf('%+d days', $volume->int(-30, 720) - $daysAgo)) : null;
        }

        // The one-in-ten with no batch code is DECLARED unidentified now rather than simply left
        // blank (item 67). Same row, same sentinel, same tracking worklist — but a blank identity on
        // a lot-tracked product is refused at receiving from here on, so the seeder has to say what
        // it means. A product that tracks nothing carries the flag harmlessly: there is no identity
        // for it to excuse.
        $request->add(
            $product,
            number_format((float) $units, 2, '.', ''),
            $line,
            $lotCode,
            $expiry,
            null,
            $bin,
            $unitCost,
            $lotCode === null,
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Vendor bills

    /**
     * Bills in every VendorBillStatus. Most bill what a PO received — some for a little more, or at
     * a little more than the agreed price, which is what the three-way match exists to catch — and
     * one in ten is a freight or service bill with no purchase order behind it.
     *
     * @param list<int> $poIds
     * @param list<int> $vendorIds
     *
     * @return list<int>
     */
    private function seedBills(int $count, DemoVolume $volume, array $poIds, array $vendorIds): array
    {
        $billable = $poIds === [] ? [] : array_map('intval', $this->connection->fetchFirstColumn(sprintf(
            "SELECT id FROM purchase_order WHERE id IN (%s) AND status IN ('Partially Received', 'Received', 'Closed', 'Issued')",
            implode(',', $poIds),
        )));

        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $order = ($billable !== [] && !$volume->chance(0.1)) ? $this->em->find(PurchaseOrder::class, $volume->pick($billable)) : null;
            $vendor = $order?->getVendor() ?? $this->em->find(Vendor::class, $volume->pick($vendorIds));
            if (!$vendor instanceof Vendor) {
                continue;
            }

            $billedDaysAgo = $order instanceof PurchaseOrder
                ? max(0, $this->daysAgoOf($order->getDocumentDate()) - $volume->int(5, 30))
                : $volume->daysAgo();

            $bill = $this->bill($vendor, $order, $volume, $billedDaysAgo);
            if (!$bill instanceof VendorBill) {
                continue;
            }
            $ids[] = (int) $bill->getId();

            $target = $order instanceof PurchaseOrder && $order->getStatusEnum() === PurchaseOrderStatus::Issued
                ? 'Draft' // billed ahead of delivery: nobody approves that
                : $volume->weighted(['Draft' => 12, 'Open' => 20, 'PartiallyPaid' => 15, 'Paid' => 33, 'Disputed' => 10, 'Void' => 10]);

            if ($target === 'Void') {
                $bill->setStatus(
                    VendorBillStatus::Void->value,
                    $this->actor,
                    sprintf('Bill voided: %s', $volume->pick(['Entered against the wrong purchase order.', 'Duplicate of an earlier bill.'])),
                );
                $this->em->flush();
            } elseif ($target !== 'Draft') {
                $bill->approve($this->actor, $this->matcher->match($bill)->summary());
                $this->billStatus->recalculate($bill);
                $this->em->flush();

                if ($target === 'PartiallyPaid' || $target === 'Paid') {
                    $amount = $target === 'Paid' ? (float) $bill->getTotal() : round((float) $bill->getTotal() * $volume->float(0.25, 0.75), 2);

                    // A payment ROW, through the same named action the screen uses (#658). This
                    // used to be recordPaymentToDate(), a cumulative figure on the bill itself;
                    // the paid amount is now the sum of these rows and nothing else holds it.
                    $payment = (new VendorBillPayment())
                        ->setPaidAt(new \DateTimeImmutable())
                        ->setMethod($volume->pick(['Bank Transfer', 'Cheque', 'E-Transfer']))
                        ->setAmount(number_format($amount, 2, '.', ''))
                        ->setComment($volume->pick(['EFT remittance.', 'Cheque run.', 'Paid by wire.']));

                    $bill->recordPayment($this->actor, $payment);
                    $this->em->persist($payment);
                    $this->billStatus->recalculate($bill);
                    $this->em->flush();
                } elseif ($target === 'Disputed') {
                    $bill->setStatus(
                        VendorBillStatus::Disputed->value,
                        $this->actor,
                        $volume->pick(['Billed quantity exceeds what we received.', 'Unit cost above the agreed price.', 'Freight charged on a free-freight order.']),
                    );
                    $this->em->flush();
                }
            }

            $this->tally('vendor_bill', $bill->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $ids;
    }

    /** The shape VendorBillController::save() writes, remit-to snapshot included. */
    private function bill(Vendor $vendor, ?PurchaseOrder $order, DemoVolume $volume, int $daysAgo): ?VendorBill
    {
        $bill = (new VendorBill())->setBillNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_BILL));
        $this->em->persist($bill);

        $terms = ['Net 30' => 30, 'Net 45' => 45, 'Net 60' => 60, 'Due on receipt' => 0];
        $bill
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setPurchaseOrder($order)
            ->setCurrency($order?->getCurrency() ?? $vendor->getCurrency())
            ->setVendorInvoiceNo(sprintf('%s-%06d', $volume->pick(['INV', 'IN', 'BL', 'SI']), $volume->int(1000, 999999)))
            ->setDocumentDate($volume->date($daysAgo))
            ->setDueDate((new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->modify(sprintf('+%d days', $terms[$vendor->getPaymentTerm() ?? ''] ?? 30))->format('Y-m-d'))
            ->setCreatedAt($volume->at($daysAgo));

        if ($bill->getRemitToAddress() === null) {
            $bill->setRemitToAddress($vendor->getRemitToAddress()?->toSnapshot());
        }

        $sort = 0;
        $subtotal = 0.0;

        if ($order instanceof PurchaseOrder) {
            foreach ($order->getLines() as $line) {
                // What arrived, occasionally a couple more than arrived, occasionally at a higher
                // unit cost: the disagreements the exceptions screen is for.
                $quantity = (float) $line->getQuantityReceived() > 0 ? (float) $line->getQuantityReceived() : (float) $line->getQuantityOrdered();
                if ($volume->chance(0.08)) {
                    $quantity += 2;
                }
                $cost = (float) $line->getUnitCost() * ($volume->chance(0.1) ? $volume->float(1.02, 1.12) : 1.0);
                $subtotal += $this->addBillLine($bill, $line->getProduct(), $line->getName(), $line->getSku(), $line->getVendorSku(), $quantity, $cost, $sort++, $line);
            }
        } else {
            $amount = round($volume->float(80, 1400), 2);
            $subtotal += $this->addBillLine($bill, null, $volume->pick(['Inbound freight — LTL', 'Pallet exchange fee', 'Tire disposal service', 'Rush handling']), null, null, 1.0, $amount, $sort++, null);
        }

        if ($sort === 0) {
            return null;
        }

        $bill->setTax(number_format($subtotal * 0.05, 2, '.', ''));
        $bill->recalculateTotals();
        $this->em->flush();

        return $bill;
    }

    private function addBillLine(VendorBill $bill, ?ProductCore $product, string $name, ?string $sku, ?string $vendorSku, float $quantity, float $cost, int $sort, ?PurchaseOrderLine $orderLine): float
    {
        $line = (new VendorBillLine())
            ->setPurchaseOrderLine($orderLine)
            ->setProduct($product)
            ->setName($name)
            ->setSku($sku)
            ->setVendorSku($vendorSku)
            ->setQuantity(number_format($quantity, 2, '.', ''))
            ->setUnitCost(number_format($cost, 4, '.', ''))
            ->setSubtotal(number_format($quantity * round($cost, 4), 2, '.', ''))
            ->setSortOrder($sort);
        $bill->addLine($line);
        $this->em->persist($line);

        return (float) $line->getSubtotal();
    }

    // ---------------------------------------------------------------------------------------------
    // RFQs

    /** @param list<int> $vendorIds */
    private function seedRfqs(int $count, DemoVolume $volume, array $vendorIds): int
    {
        $warehouseIds = array_keys($this->bins);
        $created = 0;

        for ($i = 0; $i < $count; ++$i) {
            $warehouse = $this->em->find(Warehouse::class, $volume->pick($warehouseIds));
            if (!$warehouse instanceof Warehouse) {
                continue;
            }
            $daysAgo = $volume->daysAgo();
            $target = $volume->weighted(['Draft' => 15, 'Sent' => 25, 'Accepted' => 25, 'Cancelled' => 15, 'Expired' => 20]);

            $rfq = (new Rfq())->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RFQ));
            $this->em->persist($rfq);
            $rfq->setWarehouse($warehouse)->setNotes(sprintf('%s %s', $volume->pick([
                'Pricing for the winter season.', 'Looking for a second source.', 'Quarterly restock quote.', 'Customer project — need firm lead times.',
            ]), DemoSeed::DOCUMENT_NOTE));

            $sort = 0;
            $seen = [];
            for ($n = 0, $lines = $volume->int(1, 4); $n < $lines; ++$n) {
                $productId = $this->pickProduct($volume, 0.5);
                $product = $this->em->find(ProductCore::class, $productId);
                if (isset($seen[$productId]) || !$product instanceof ProductCore) {
                    continue;
                }
                $seen[$productId] = true;
                $line = (new RfqLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setQuantity(number_format((float) ($volume->int(2, 30) * 4), 2, '.', ''))
                    ->setSortOrder($sort++);
                $rfq->addLine($line);
                $this->em->persist($line);
            }
            $this->em->flush();
            ++$created;

            if ($target !== 'Draft' && !($target === 'Cancelled' && $volume->chance(0.5))) {
                $this->sendRfq($rfq, $volume, $vendorIds, $daysAgo, $target);
            }

            if ($target === 'Cancelled') {
                $rfq->cancel($volume->at(max(0, $daysAgo - $volume->int(1, 10))));
                $this->em->flush();
            } elseif ($target === 'Expired' && $rfq->getStatus() === RfqStatus::Sent) {
                $rfq->expire($volume->at(max(0, $daysAgo - $volume->int(14, 30))));
                $this->em->flush();
            }

            $this->tally('rfq', $rfq->getStatus()->value);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $created;
    }

    /**
     * Invite two or three vendors, send, collect replies (priced, declined, or still waiting), and —
     * for an Accepted RFQ — convert the cheapest reply into a purchase order the way the RFQ screen
     * does, then issue that PO about half the time.
     *
     * @param list<int> $vendorIds
     */
    private function sendRfq(Rfq $rfq, DemoVolume $volume, array $vendorIds, int $daysAgo, string $target): void
    {
        foreach ($volume->sample($vendorIds, $volume->int(2, 3)) as $vendorId) {
            $vendor = $this->em->find(Vendor::class, $vendorId);
            if (!$vendor instanceof Vendor) {
                continue;
            }
            $reply = (new RfqVendorReply())
                ->setReplyNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RFQ_REPLY))
                ->setVendor($vendor)
                ->setCurrency($vendor->getCurrency())
                ->setDocumentDate($volume->date($daysAgo));
            $rfq->addReply($reply);
            $this->em->persist($reply);
        }

        if ($rfq->getReplies()->isEmpty()) {
            return;
        }

        $rfq->send($volume->at($daysAgo));
        $this->em->flush();

        $best = null;
        foreach ($rfq->getReplies() as $index => $reply) {
            $answer = ($target === 'Accepted' && $index === 0) ? 'priced' : $volume->weighted(['priced' => 60, 'declined' => 15, 'waiting' => 25]);

            if ($answer === 'declined') {
                $reply->decline($volume->pick(['Cannot meet the requested quantity.', 'Line discontinued.', 'Not a product we carry.']));
            } elseif ($answer === 'priced') {
                $factor = $volume->float(0.85, 1.1);
                foreach ($rfq->getLines() as $line) {
                    $cost = (float) ($line->getProduct()?->getCostPrice() ?? 10) * $factor;
                    $replyLine = (new RfqVendorReplyLine())
                        ->setRfqLine($line)
                        ->setUnitCost(number_format($cost, 4, '.', ''))
                        ->setSubtotal(number_format((float) $line->getQuantity() * round($cost, 4), 2, '.', ''));
                    $reply->addLine($replyLine);
                    $this->em->persist($replyLine);
                }
                $reply->markReplied($volume->at(max(0, $daysAgo - $volume->int(1, 7))));
                if ($best === null || $factor < $best[1]) {
                    $best = [$reply, $factor];
                }
            }
        }
        $this->em->flush();

        if ($target !== 'Accepted' || $best === null) {
            return;
        }

        $order = $this->rfqConversion->convert($best[0], $this->em, DemoSeed::ACTOR_LABEL);
        $this->em->flush();

        if ($volume->chance(0.5) && !$order->getLines()->isEmpty()) {
            $order->setStatus(PurchaseOrderStatus::Issued->value, $this->actor);
            $this->incoming->reconcileForOrder($order);
            $this->em->flush();
        }

        foreach ($rfq->getReplies() as $reply) {
            $this->tally('rfq_vendor_reply', $reply->getStatus()->value);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Vendor returns

    /**
     * RMAs back to the vendor against a receipt, in every VendorReturnStatus. The shipment goes
     * through VendorReturnShipService, which takes dimensional units out of available stock; it is
     * only attempted when the warehouse can cover it, because the service's own refusal happens
     * inside its transaction and would end the run rather than one document.
     *
     * @param list<int> $receiptIds
     *
     * @return array{0: list<int>, 1: int, 2: int} ids that shipped, count left Authorised for want of stock, count created
     */
    private function seedVendorReturns(int $count, DemoVolume $volume, array $receiptIds): array
    {
        $shippedIds = [];
        $notShipped = 0;
        $created = 0;
        if ($receiptIds === []) {
            return [[], 0, 0];
        }

        for ($i = 0; $i < $count; ++$i) {
            $receipt = $this->em->find(GoodsReceipt::class, $volume->pick($receiptIds));
            if (!$receipt instanceof GoodsReceipt || $receipt->getLines()->isEmpty()) {
                continue;
            }

            $reason = $volume->pick(['Damaged in transit', 'Wrong item shipped', 'Over-shipped', 'Defective on inspection', 'Recalled batch']);
            $return = (new VendorReturn())
                ->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_VENDOR_RETURN))
                ->setVendor($receipt->getVendor())
                ->setGoodsReceipt($receipt)
                ->setReason($reason)
                ->setNotes($volume->chance(0.3) ? 'Vendor RA number to follow.' : null);

            $sort = 0;
            $onReturn = [];
            foreach ($volume->sample($receipt->getLines()->toArray(), $volume->int(1, 2)) as $receiptLine) {
                $product = $receiptLine->getProduct();
                // One line per product. A serial product is received one line per unit, so two of
                // its receipt lines would make two return lines — and VendorReturnShipService plans
                // each line's withdrawal separately, so both would pick the same first serial and
                // StockMovementService refuses a serial row holding 2. A real guard, respected.
                if (!$product instanceof ProductCore || isset($onReturn[(int) $product->getId()])) {
                    continue;
                }
                $onReturn[(int) $product->getId()] = true;
                $units = min((int) floor((float) $receiptLine->getQuantity()), $volume->int(1, 5));
                if ($units <= 0) {
                    continue;
                }
                $return->addLine(
                    (new VendorReturnLine())
                        ->setProduct($product)
                        ->setGoodsReceiptLine($receiptLine)
                        ->setQuantity(number_format((float) $units, 2, '.', ''))
                        ->setName($receiptLine->getName())
                        ->setSku($receiptLine->getSku())
                        ->setReason($reason)
                        ->setSortOrder($sort++),
                );
            }
            if ($sort === 0) {
                continue;
            }

            $this->em->persist($return);
            $this->em->flush();
            ++$created;

            $target = $volume->weighted(['Requested' => 18, 'Authorised' => 20, 'Shipped' => 27, 'Closed' => 20, 'Declined' => 15]);
            $at = $volume->at(max(0, (int) (new \DateTimeImmutable('today'))->diff($receipt->getReceivedAt())->days - $volume->int(1, 10)));

            if ($target !== 'Requested') {
                $return->authorise($at);
                $this->em->flush();
            }

            if (\in_array($target, ['Shipped', 'Closed'], true) || ($target === 'Declined' && $volume->chance(0.5))) {
                if ($this->canShip($return, $receipt->getWarehouse())) {
                    $this->returnShipping->ship($return, $receipt->getWarehouse(), DemoSeed::ACTOR_LABEL, $at);
                } else {
                    ++$notShipped;
                }
            }

            if ($target === 'Closed' && $return->getStatus() === VendorReturnStatus::Shipped) {
                $return->close();
                $this->em->flush();
            } elseif ($target === 'Declined' && \in_array($return->getStatus(), [VendorReturnStatus::Authorised, VendorReturnStatus::Shipped], true)) {
                $return->decline($volume->pick(['Vendor refused: outside the return window.', 'Vendor says the damage is ours.']), $at);
                $this->em->flush();
            }

            if (\in_array($return->getStatus(), [VendorReturnStatus::Shipped, VendorReturnStatus::Closed], true)) {
                $shippedIds[] = (int) $return->getId();
            }
            $this->tally('vendor_return', $return->getStatus()->value);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return [$shippedIds, $notShipped, $created];
    }

    /** Whether available stock covers every dimensional line — simple lines move nothing. */
    private function canShip(VendorReturn $return, Warehouse $warehouse): bool
    {
        $needed = [];
        foreach ($return->getLines() as $line) {
            $productId = (int) $line->getProduct()->getId();
            if (isset($this->dimensional[$productId])) {
                $needed[$productId] = QuantityScale::add($needed[$productId] ?? 0, $line->getUnits());
            }
        }

        foreach ($needed as $productId => $units) {
            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore || QuantityScale::compare($this->details->availableTotal($product, $warehouse), $units) < 0) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // Debit memos

    /**
     * Debit memos in every DebitMemoStatus: for goods shipped back on a vendor return, for a price
     * variance on a bill, and standalone rebates. Open ones are then applied to a bill from the same
     * vendor, or refunded, which is what settles them to Closed.
     *
     * @param list<int> $returnIds
     * @param list<int> $billIds
     * @param list<int> $vendorIds
     */
    private function seedDebitMemos(int $count, DemoVolume $volume, array $returnIds, array $billIds, array $vendorIds): int
    {
        $created = 0;
        $liveBills = $billIds === [] ? [] : array_map('intval', $this->connection->fetchFirstColumn(sprintf(
            "SELECT id FROM vendor_bill WHERE id IN (%s) AND status <> 'Void'",
            implode(',', $billIds),
        )));

        for ($i = 0; $i < $count; ++$i) {
            $kind = $volume->weighted(['return' => 45, 'bill' => 35, 'rebate' => 20]);
            if ($kind === 'return' && $returnIds === []) {
                $kind = 'bill';
            }
            if ($kind === 'bill' && $liveBills === []) {
                $kind = 'rebate';
            }

            $memo = $this->debitMemo($kind, $volume, $returnIds, $liveBills, $vendorIds);
            if (!$memo instanceof DebitMemo) {
                continue;
            }
            ++$created;

            $target = $volume->weighted(['Draft' => 18, 'Open' => 22, 'Applied' => 30, 'PartApplied' => 8, 'Refunded' => 12, 'Void' => 10]);

            if ($target === 'Void') {
                $memo->void();
                $this->em->flush();
            } elseif ($target !== 'Draft' && (float) $memo->getTotal() > 0) {
                $memo->issue();
                $this->em->flush();

                $balance = (float) $memo->getBalance();
                $bill = $this->billFor($memo, $volume, $liveBills);

                if (($target === 'Applied' || $target === 'PartApplied') && $bill instanceof VendorBill) {
                    $amount = $target === 'Applied' ? $balance : round($balance * $volume->float(0.3, 0.7), 2);
                    $memo->applyTo($bill, number_format($amount, 2, '.', ''), $volume->at($volume->int(0, 20)));
                    $this->em->flush();
                } elseif ($target !== 'Open') {
                    $refund = (new DebitMemoRefund())
                        ->setRefundedAt($volume->at($volume->int(0, 20)))
                        ->setMethod($volume->pick(DemoVolume::REFUND_METHODS))
                        ->setAmount(number_format($balance, 2, '.', ''))
                        ->setComment($volume->pick([null, 'Vendor cheque received.', 'Credited to our card.']));
                    $memo->recordRefund($refund);
                    $this->em->persist($refund);
                    $this->em->flush();
                }
            }

            $this->tally('debit_memo', $memo->getStatus()->value);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $created;
    }

    /**
     * @param list<int> $returnIds
     * @param list<int> $billIds
     * @param list<int> $vendorIds
     */
    private function debitMemo(string $kind, DemoVolume $volume, array $returnIds, array $billIds, array $vendorIds): ?DebitMemo
    {
        $return = $kind === 'return' ? $this->em->find(VendorReturn::class, $volume->pick($returnIds)) : null;
        $bill = $kind === 'bill' ? $this->em->find(VendorBill::class, $volume->pick($billIds)) : null;
        $vendor = $return?->getVendor() ?? $bill?->getVendor() ?? $this->em->find(Vendor::class, $volume->pick($vendorIds));
        if (!$vendor instanceof Vendor) {
            return null;
        }

        // The shape DebitMemoController::save() writes.
        $memo = (new DebitMemo())->setDocumentNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_DEBIT_MEMO));
        $this->em->persist($memo);
        $memo
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->setCurrency($vendor->getCurrency())
            ->setDocumentDate($volume->date($volume->daysAgo(180)))
            ->setVendorBill($bill)
            ->setVendorReturn($return)
            ->setRestock(false);

        $sort = 0;
        if ($return instanceof VendorReturn) {
            $memo->setReason('Credit for goods returned on ' . $return->getDocumentNumber());
            foreach ($return->getLines() as $line) {
                $cost = (float) ($line->getGoodsReceiptLine()?->getUnitCost() ?? $line->getProduct()->getCostPrice() ?? 10);
                $this->addDebitLine($memo, $line->getName(), $line->getSku(), (float) $line->getUnits(), $cost, $sort++);
            }
        } elseif ($bill instanceof VendorBill) {
            $memo->setReason($volume->pick(['Price variance against the PO', 'Short shipment billed in full', 'Freight overcharge']));
            $line = $bill->getLines()->first();
            if ($line instanceof VendorBillLine) {
                $this->addDebitLine($memo, 'Adjustment: ' . $line->getName(), $line->getSku(), 1.0, round((float) $line->getSubtotal() * $volume->float(0.03, 0.15), 2), $sort++);
            }
        } else {
            $memo->setReason($volume->pick(['Volume rebate', 'Co-op marketing allowance', 'Early payment discount']));
            $this->addDebitLine($memo, (string) $memo->getReason(), null, 1.0, round($volume->float(50, 900), 2), $sort++);
        }

        if ($sort === 0) {
            return null;
        }

        $memo->recalculateTotals();
        $this->em->flush();

        return $memo;
    }

    private function addDebitLine(DebitMemo $memo, string $name, ?string $sku, float $quantity, float $cost, int $sort): void
    {
        $line = (new DebitMemoLine())
            ->setName($name)
            ->setSku($sku)
            ->setQuantity(number_format($quantity, 2, '.', ''))
            ->setUnitCost(number_format($cost, 4, '.', ''))
            ->setSubtotal(number_format($quantity * $cost, 2, '.', ''))
            ->setSortOrder($sort);
        $memo->addLine($line);
        $this->em->persist($line);
    }

    /**
     * The bill a memo is applied to: its own, else another live bill from the same vendor.
     *
     * @param list<int> $billIds
     */
    private function billFor(DebitMemo $memo, DemoVolume $volume, array $billIds): ?VendorBill
    {
        $own = $memo->getVendorBill();
        if ($own instanceof VendorBill && $own->getStatusEnum() !== VendorBillStatus::Void) {
            return $own;
        }

        $candidates = $billIds === [] ? [] : array_map('intval', $this->connection->fetchFirstColumn(sprintf(
            "SELECT id FROM vendor_bill WHERE vendor_id = ? AND id IN (%s) AND status <> 'Void'",
            implode(',', $billIds),
        ), [$memo->getVendor()->getId()]));

        return $candidates === [] ? null : $this->em->find(VendorBill::class, $volume->pick($candidates));
    }

    // ---------------------------------------------------------------------------------------------

    private function daysAgoOf(?string $date): int
    {
        return $date === null || $date === '' ? 0 : max(0, (int) (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($date))->days);
    }

    private function tally(string $table, string $status): void
    {
        $this->outcomes[$table][$status] = ($this->outcomes[$table][$status] ?? 0) + 1;
    }
}
