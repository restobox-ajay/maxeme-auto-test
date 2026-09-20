<?php

declare(strict_types=1);

namespace App\Command\Demo;

use App\Service\QuantityScale;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CompanyNote;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\CreditMemoRefund;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Enum\InvoiceIssueIntent;
use App\Enum\InvoiceStatus;
use App\Enum\SalesReturnStatus;
use App\Event\CreditMemoIssuedEvent;
use App\Event\SalesReturnReceivedEvent;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\CreditMemoNumberGenerator;
use App\Service\DocumentActor;
use App\Service\EstimateConversionService;
use App\Service\EstimateNumberGenerator;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\OrderInvoicingService;
use App\Service\OrderNumberGenerator;
use App\Service\SalesDocumentChargeLines;
use App\Service\SalesReturnNumberGenerator;
use App\Service\Uom\ProductAvailableUnitService;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Service\Uom\UnitOfMeasureService;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The sell side of `app:seed-demo-volume`: companies and everything a customer generates.
 *
 * Every document goes through the call the admin controller makes for the same button, so the
 * derived state — order status, invoice payment status, sales holds on `product_inventory`, the
 * document number counters, the timeline logs — is produced by the application rather than typed
 * in. See SeedDemoDataCommand for the order lifecycle table this follows; the differences here are
 * only of scale and spread:
 *
 *  - rows are spread over the past year, older documents skewed towards finished states (Closed,
 *    Completed) and recent ones towards open ones (Draft, Approved, Pending), which is what a real
 *    ledger looks like and what makes every status filter on every list screen return rows;
 *  - it only ever ADDS. It uses the catalogue that is already there (simple-mode products with
 *    stock in the selling region) and never edits a product, price or existing company.
 *
 * Tags are the DemoSeed ones: companies carry a `DEMO-` code, and everything else hangs off a demo
 * company, which is what DemoDataCleaner deletes by.
 */
final class DemoVolumeSellSide
{
    private const ACTOR = DemoSeed::ACTOR_LABEL;

    /** Password every seeded customer user gets. Demo instances only. */
    private const CUSTOMER_PASSWORD = 'demo-password';

    private const BATCH = 20;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array<string, int>> status tallies, for the command's summary */
    private array $outcomes = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly DemoVolumeCheckpoint $checkpoint,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly CompanyFulfillmentRegionService $companyRegions,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly EstimateNumberGenerator $estimateNumbers,
        private readonly CreditMemoNumberGenerator $creditMemoNumbers,
        private readonly SalesReturnNumberGenerator $salesReturnNumbers,
        private readonly OrderInvoicingService $invoicing,
        private readonly AdminOrderStockValidator $stock,
        private readonly BackorderSplitResolver $backorders,
        private readonly EstimateConversionService $conversion,
        private readonly UnitOfMeasureService $units,
        private readonly ProductAvailableUnitService $availableUnits,
        private readonly EventDispatcherInterface $events,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @return array{outcomes: array<string, array<string, int>>, notes: list<string>}
     */
    public function seed(int $count, DemoVolume $volume, ?callable $progress = null): array
    {
        $progress ??= static function (string $message): void {};
        $this->counts = [];
        $this->outcomes = [];
        $notes = [];

        $context = $this->context();
        if ($context['products'] === []) {
            return ['outcomes' => [], 'notes' => ['No simple-mode product has stock in any selling region; the sell side was skipped.']];
        }

        $progress(sprintf('selling from region "%s" (%d stocked products)', $context['region'], \count($context['products'])));

        $companyIds = $this->seedCompanies($count, $volume, $context);
        $progress(sprintf('companies: %d', \count($companyIds)));

        $invoiceIds = $this->seedOrders($count, $volume, $context, $companyIds);
        $progress(sprintf('orders: %d', $this->counts['orders'] ?? 0));

        $this->seedBackorderOrders($count, $volume, $context, $companyIds);
        $progress(sprintf('backorder orders: %d', $this->counts['backorder_orders'] ?? 0));

        $invoiceIds = array_merge($invoiceIds, $this->seedEstimates($count, $volume, $context, $companyIds));
        $progress(sprintf('estimates: %d', $this->counts['estimates'] ?? 0));

        $returnIds = $this->seedSalesReturns($count, $volume, $context, $invoiceIds);
        $progress(sprintf('sales returns: %d', \count($returnIds)));

        $this->seedCreditMemos($count, $volume, $invoiceIds, $returnIds, $companyIds);
        $progress(sprintf('credit memos: %d', $this->counts['credit_memos'] ?? 0));

        $units = $this->seedAvailableUnits($count, $volume, $context);
        $progress(sprintf('product available units: %d', $units));

        if (($this->counts['orders_left_draft_for_stock'] ?? 0) > 0) {
            $notes[] = sprintf(
                '%d order(s) meant to be approved stayed Draft because AdminOrderStockValidator reported a shortfall.',
                $this->counts['orders_left_draft_for_stock'],
            );
        }

        $this->checkpoint->__invoke();

        return ['outcomes' => $this->outcomes, 'notes' => $notes];
    }

    /**
     * What there is to sell: the region with the most stocked simple products, its warehouse, those
     * products, and the active price lists a company can be put on.
     *
     * Read with SQL because it is a read and nothing more — every write below goes through entities
     * and services.
     *
     * @return array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>}
     */
    private function context(): array
    {
        $best = null;

        foreach ($this->warehouses->warehousesByLowerRegionName() as $warehouse) {
            if (!$warehouse instanceof Warehouse) {
                continue;
            }
            $region = $this->warehouses->regionForWarehouse($warehouse);
            if ($region === null) {
                continue;
            }

            $stocked = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM product_inventory pi JOIN product_core p ON p.id = pi.product_id
                 WHERE pi.warehouse_id = ? AND p.status = 'Active' AND p.inventory_mode = 'simple' AND pi.quantity >= 40",
                [$warehouse->getId()],
            );

            if ($best === null || $stocked > $best[2]) {
                $best = [$region->getName(), (int) $warehouse->getId(), $stocked];
            }
        }

        if ($best === null || $best[2] === 0) {
            return ['region' => '', 'warehouseId' => 0, 'products' => [], 'priceLists' => []];
        }

        $products = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id FROM product_inventory pi JOIN product_core p ON p.id = pi.product_id
             WHERE pi.warehouse_id = ? AND p.status = 'Active' AND p.inventory_mode = 'simple'
               AND pi.quantity >= 40 AND CAST(p.default_price AS REAL) > 0
             ORDER BY p.id",
            [$best[1]],
        ));

        $priceLists = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM price_list WHERE status = 'Active' ORDER BY id",
        ));

        return ['region' => $best[0], 'warehouseId' => $best[1], 'products' => $products, 'priceLists' => $priceLists];
    }

    // ---------------------------------------------------------------------------------------------
    // Companies

    /**
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     *
     * @return list<int> new company ids
     */
    private function seedCompanies(int $count, DemoVolume $volume, array $context): array
    {
        $codes = array_flip(array_map('strval', $this->connection->fetchFirstColumn('SELECT code FROM company WHERE code IS NOT NULL')));
        $names = array_flip(array_map('strtolower', array_map('strval', $this->connection->fetchFirstColumn('SELECT name FROM company'))));
        $emails = array_flip(array_map('strtolower', array_map('strval', $this->connection->fetchFirstColumn('SELECT email FROM customer_user'))));
        $next = 1 + (int) $this->connection->fetchOne(sprintf(
            "SELECT COALESCE(MAX(CAST(SUBSTR(code, %d) AS INTEGER)), 0) FROM company WHERE %s",
            \strlen(DemoSeed::COMPANY_CODE_PREFIX) + 1,
            DemoSeed::WHERE_COMPANY,
        ));

        // One hash for every seeded sign-in. Hashing is deliberately slow, and 300 identical
        // passwords hashed one at a time would be most of this command's run time for no benefit.
        $passwordHash = $this->hasher->hashPassword(new CustomerUser(), self::CUSTOMER_PASSWORD);

        $reps = ['Sam T.', 'Marcus D.', 'Jordan Mackay', 'Aisha K.', 'Leah M.', 'Ravi P.'];
        $noteTexts = [
            'Prefers deliveries before 10:00. Dock doors on the north side.',
            'Asked for a quote on winter sets for their fleet; follow up in September.',
            'Credit limit reviewed; keep on Net 30 for now.',
            'Accounts payable contact changed — send statements to the new AP address.',
            'Called about a short shipment; resolved with a credit memo.',
            'Requested tire storage pricing for next season.',
            'Owner mentioned opening a second location in the spring.',
            'Will pick up at the warehouse; no delivery required.',
            'Paid late twice this quarter. Hold new orders if a third invoice goes past 45 days.',
            'Interested in the TPMS sensor program.',
        ];

        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            do {
                $name = trim(sprintf('%s %s %s', $volume->pick(DemoVolume::NAME_PREFIXES), $volume->pick(DemoVolume::CUSTOMER_TRADES), $volume->pick(DemoVolume::LEGAL_SUFFIXES)));
                if (isset($names[strtolower($name)])) {
                    $name .= ' ' . $volume->pick(['North', 'South', 'East', 'West', 'Central', 'Valley', 'Coast', '#2']);
                }
            } while (isset($names[strtolower($name)]));
            $names[strtolower($name)] = true;

            do {
                $code = sprintf('%s%04d', DemoSeed::COMPANY_CODE_PREFIX, $next++);
            } while (isset($codes[$code]));
            $codes[$code] = true;

            $domain = DemoVolume::domainFor($name);
            $first = $volume->firstName();
            $last = $volume->lastName();
            $status = $volume->weighted(['Active' => 80, 'Inactive' => 12, 'Review' => 8]);

            $company = (new Company())
                ->setName($name)
                ->setCode($code)
                ->setAccountType('Business')
                ->setTradeName($name)
                ->setPrimaryEmail('accounts@' . $domain)
                ->setPhoneNumber($volume->phone())
                ->setFirstName($first)
                ->setLastName($last)
                ->setSalesRepNote($volume->pick($reps))
                ->setBusinessLicense(sprintf('BL-%05d-%d', $volume->int(10000, 99999), $volume->int(2019, 2026)));
            $company->setStatus($status, DocumentActor::automation(self::ACTOR));
            $this->em->persist($company);

            // One to three addresses. The first is the default for both roles unless a second one
            // takes over shipping, which is the shape an address book screen needs to be seen
            // honouring the two flags separately.
            $addressCount = $volume->int(1, 3);
            for ($a = 0; $a < $addressCount; ++$a) {
                [$line1, $city, $province, $postal] = $volume->canadianAddress();
                $address = (new CompanyAddress())
                    ->setCompany($company)
                    ->setLabel($a === 0 ? 'Head Office' : $volume->pick(['Yard', 'Shop', 'Warehouse', 'Branch', 'Service Bay']))
                    ->setFirstName($a === 0 ? $first : $volume->firstName())
                    ->setLastName($a === 0 ? $last : $volume->lastName())
                    ->setCompanyName($name)
                    ->setEmailPrimary(($a === 0 ? 'ap@' : 'receiving@') . $domain)
                    ->setPhone($volume->phone())
                    ->setAddressLine1($line1)
                    ->setCity($city)
                    ->setProvince($province)
                    ->setCountry('CA')
                    ->setPostalCode($postal)
                    ->setIsDefaultBilling($a === 0)
                    ->setIsDefaultShipping($a === 0 ? $addressCount === 1 || !$volume->chance(0.4) : false);

                if ($a > 0 && $volume->chance(0.5)) {
                    $address->setDeliveryInstructions($volume->pick(['Deliveries 07:00-15:00. Call on arrival.', 'Rear entrance, ring the bell.', 'Forklift on site.', 'No deliveries on Fridays.']));
                }

                $company->addAddress($address);
                $this->em->persist($address);
                $this->bump('company_address');
            }

            // Guarantee a default shipping address when the coin said "second one takes shipping".
            if ($addressCount > 1 && $company->getDefaultShippingAddress() === null) {
                $company->getAddresses()->last()->setIsDefaultShipping(true);
            }

            $userCount = $volume->weightedInt([1 => 60, 2 => 30, 3 => 10]);
            for ($u = 0; $u < $userCount; ++$u) {
                $userFirst = $u === 0 ? $first : $volume->firstName();
                $userLast = $u === 0 ? $last : $volume->lastName();
                $email = strtolower(sprintf('%s.%s@%s', $userFirst, $userLast, $domain));
                for ($n = 2; isset($emails[$email]); ++$n) {
                    $email = strtolower(sprintf('%s.%s%d@%s', $userFirst, $userLast, $n, $domain));
                }
                $emails[$email] = true;

                $user = (new CustomerUser())
                    ->setCompany($company)
                    ->setEmail($email)
                    ->setFirstName($userFirst)
                    ->setLastName($userLast)
                    ->setPhoneNumber($volume->phone())
                    ->setPassword($passwordHash);
                $user->setStatus(
                    $status === 'Active' && !$volume->chance(0.08) ? 'Active' : 'Inactive',
                    DocumentActor::automation(self::ACTOR),
                );
                $this->em->persist($user);
                $this->bump('customer_user');
            }

            $noteCount = $volume->weightedInt([0 => 35, 1 => 35, 2 => 20, 3 => 10]);
            for ($n = 0; $n < $noteCount; ++$n) {
                $note = (new CompanyNote())
                    ->setCompany($company)
                    ->setUserName($volume->pick(['Jordan Mackay', 'Sam T.', 'Marcus D.', 'Accounts desk']))
                    ->setText($volume->pick($noteTexts))
                    ->setCreatedAt($volume->at($volume->daysAgo()));
                $this->em->persist($note);
                $this->bump('company_note');
            }

            $this->em->flush();

            // The same two steps CompanyController::create() takes: one inactive row per region,
            // then the region this company buys from switched on WITH a price list — the pairing
            // CompanyFulfillmentRegionService::validateActivation() insists on.
            $this->companyRegions->backfillForNewCompany($company);
            $this->em->flush();

            foreach ($this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]) as $row) {
                if ($row->getFulfillmentRegion()->getName() === $context['region'] && $context['priceLists'] !== []) {
                    $row->setPriceList($this->em->find(PriceList::class, $volume->pick($context['priceLists'])))->setStatus('Active');
                }
            }
            $this->em->flush();

            $ids[] = (int) $company->getId();
            $this->tally('company', $status);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // Orders, invoices, payments

    /**
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     * @param list<int> $companyIds
     *
     * @return list<int> ids of the invoices raised
     */
    private function seedOrders(int $count, DemoVolume $volume, array $context, array $companyIds): array
    {
        $actor = DocumentActor::automation(self::ACTOR);
        $invoiceIds = [];

        for ($i = 0; $i < $count; ++$i) {
            $company = $this->em->find(Company::class, $volume->pick($companyIds));
            if (!$company instanceof Company) {
                continue;
            }

            $daysAgo = $volume->daysAgo();
            $outcome = $this->orderOutcome($volume, $daysAgo);

            $order = $this->buildOrder($company, $volume, $context, $daysAgo);
            $this->em->persist($order);
            $this->em->flush();
            $this->bump('orders');

            if ($outcome !== 'draft') {
                $invoiceIds = array_merge($invoiceIds, $this->progressOrder($order, $outcome, $volume, $daysAgo, $actor));
            }

            $this->tally('sales_order', $order->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $invoiceIds;
    }

    /** Older documents are mostly finished; recent ones mostly still open. */
    private function orderOutcome(DemoVolume $volume, int $daysAgo): string
    {
        if ($daysAgo < 14) {
            return $volume->weighted([
                'draft' => 22, 'approved' => 22, 'draft-invoice' => 8, 'partially-invoiced' => 12,
                'invoiced' => 14, 'awaiting-payment' => 8, 'processing' => 6, 'void' => 4, 'invoiced-part-paid' => 4,
            ]);
        }

        if ($daysAgo < 60) {
            return $volume->weighted([
                'draft' => 4, 'approved' => 8, 'partially-invoiced' => 10, 'invoiced' => 16, 'invoiced-part-paid' => 12,
                'awaiting-payment' => 6, 'processing' => 10, 'completed' => 10, 'closed' => 14, 'split-closed' => 4,
                'void' => 4, 'cancelled-and-reraised' => 2,
            ]);
        }

        return $volume->weighted([
            'draft' => 2, 'approved' => 2, 'partially-invoiced' => 3, 'invoiced' => 6, 'invoiced-part-paid' => 6,
            'awaiting-payment' => 2, 'processing' => 3, 'completed' => 18, 'closed' => 40, 'split-closed' => 8,
            'void' => 6, 'cancelled-and-reraised' => 4,
        ]);
    }

    /** @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context */
    private function buildOrder(Company $company, DemoVolume $volume, array $context, int $daysAgo): SalesOrder
    {
        $source = $volume->weighted(['Customer' => 60, 'Admin' => 40]);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($this->orderNumbers->next($this->em))
            ->setFulfillmentRegion($context['region'])
            ->setDocumentDate($volume->date($daysAgo))
            ->setSource($source)
            ->setUserName($company->getFirstName() . ' ' . $company->getLastName())
            ->setPaymentMethod($volume->pick(['Net 30', 'Net 30', 'Credit Card', null]));

        if ($volume->chance(0.7)) {
            $order->setPoNumber(sprintf('%s-%05d', $volume->pick(['PO', 'REQ', 'WO', 'FLEET']), $volume->int(1000, 99999)));
        }
        if ($volume->chance(0.15)) {
            $order->setSpecialInstructions($volume->pick(['Call before delivery.', 'Deliver to the service bay entrance.', 'Mount and balance on arrival.', 'Hold for pickup.']));
        }

        // Frozen copies of the address book rows, not references — see AbstractDocumentAddress.
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress() ?? $company->getDefaultBillingAddress());
        $order->snapshotCompany($company);

        $subtotal = 0.0;
        $sort = 0;

        foreach ($volume->sample($context['products'], $volume->weightedInt([1 => 30, 2 => 30, 3 => 20, 4 => 12, 5 => 8])) as $productId) {
            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            // Tyres sell in pairs and sets of four far more often than singly.
            $quantity = (float) $volume->pick([1, 2, 2, 4, 4, 4, 4, 8, 8, 12, 16]);
            $price = (float) $product->getDefaultPrice();
            $lineSubtotal = $price * $quantity;
            $subtotal += $lineSubtotal;

            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setUnit($product->getUnit())
                    ->setQuantity(number_format($quantity, 2, '.', ''))
                    ->setCost((string) ($product->getCostPrice() ?? '0.00'))
                    ->setPrice(DemoVolume::money($price))
                    ->setSubtotal(DemoVolume::money($lineSubtotal))
                    ->setTaxCode($product->getSalesTaxCode() ?? 'Taxable')
                    ->setSortOrder($sort++),
            );
        }

        // A flat 5% stands in for the tax engine, as in SeedDemoDataCommand: an order's tax_lines
        // snapshot is a record of a calculation, and none ran.
        $tax = round($subtotal * 0.05, 2);

        return $order
            ->setSubtotal(DemoVolume::money($subtotal))
            ->setTax(DemoVolume::money($tax))
            ->setTotal(DemoVolume::money($subtotal + $tax));
    }

    /**
     * Approve (as OrderController does: stock check, approve, backorder split) and then raise the
     * invoices and payments the outcome implies. The order's status is the deriver's business.
     *
     * @return list<int> invoice ids
     */
    private function progressOrder(SalesOrder $order, string $outcome, DemoVolume $volume, int $daysAgo, DocumentActor $actor): array
    {
        if ($this->stock->shortfallsForOrder($order, $this->em) !== []) {
            $this->bump('orders_left_draft_for_stock');

            return [];
        }

        $order->setStatus('Approved', $actor, 'Order approved.');
        $this->backorders->applyToOrderLines($order, $order->getStatus(), $this->em);
        $this->em->flush();

        if ($outcome === 'approved') {
            return [];
        }

        if ($outcome === 'void') {
            $order->setStatus('Void', $actor, sprintf(
                'Order voided (was %s): %s',
                $order->getStatus(),
                $volume->pick(['Customer cancelled before shipment.', 'Duplicate of another order.', 'Customer went with a different size.']),
            ));
            $this->backorders->applyToOrderLines($order, $order->getStatus(), $this->em);
            $this->em->flush();

            return [];
        }

        $ids = [];
        $invoiceDaysAgo = max(0, $daysAgo - $volume->int(0, 3));

        if ($outcome === 'partially-invoiced' || $outcome === 'split-closed') {
            $lines = $order->getLines()->toArray();
            $first = $lines[0];
            $half = max(1.0, floor((float) $first->getQuantity() / 2));

            $invoice = $this->invoicing->invoiceFromOrder(
                $order,
                [['line' => $first, 'quantity' => number_format($half, 2, '.', ''), 'price' => null]],
                $this->em,
                $actor,
                InvoiceIssueIntent::Issue,
                [],
            );
            $this->dateInvoice($invoice, $invoiceDaysAgo);
            $this->em->flush();
            $ids[] = (int) $invoice->getId();
            $this->tallyInvoice($invoice);

            if ($outcome === 'partially-invoiced') {
                return $ids;
            }

            // Split billing: the rest on a second invoice a week or so later, then both paid —
            // which is the case where an order is Closed by two invoices rather than one.
            $rest = [];
            foreach ($order->getLines() as $line) {
                $left = $order->uninvoicedQuantityFor($line);
                if ((float) $left > 0) {
                    $rest[] = ['line' => $line, 'quantity' => $left, 'price' => null];
                }
            }

            $second = $rest === [] ? null : $this->invoicing->invoiceFromOrder($order, $rest, $this->em, $actor, InvoiceIssueIntent::Issue, null);
            if ($second !== null) {
                $this->dateInvoice($second, max(0, $invoiceDaysAgo - $volume->int(3, 12)));
                $this->em->flush();
                $ids[] = (int) $second->getId();
            }

            foreach (array_filter([$invoice, $second]) as $billed) {
                $this->pay($billed, $volume, (float) $billed->getTotal(), $invoiceDaysAgo, $actor);
                $this->tallyInvoice($billed);
            }
            $this->em->flush();

            return $ids;
        }

        if ($outcome === 'cancelled-and-reraised') {
            // Never deleted, never renumbered: the spoiled invoice stays Cancelled beside its
            // replacement, and cancelling returns its quantity to the order.
            $spoiled = $this->invoicing->invoiceInFull($order, $this->em, $actor, InvoiceIssueIntent::Issue);
            $this->dateInvoice($spoiled, $invoiceDaysAgo);
            $this->em->flush();
            $spoiled->setStatus(
                'Cancelled',
                $actor,
                'Invoice cancelled: Billed to the wrong site address; re-raised as a replacement.',
            );
            $this->em->flush();
            $ids[] = (int) $spoiled->getId();
            $this->tallyInvoice($spoiled);
        }

        $intent = match ($outcome) {
            'draft-invoice' => InvoiceIssueIntent::KeepDraft,
            'awaiting-payment' => InvoiceIssueIntent::AwaitingPayment,
            default => InvoiceIssueIntent::Issue,
        };

        $invoice = $this->invoicing->invoiceInFull($order, $this->em, $actor, $intent);
        $this->dateInvoice($invoice, $invoiceDaysAgo);
        $this->em->flush();
        $ids[] = (int) $invoice->getId();

        if ($outcome === 'processing' || $outcome === 'completed') {
            $invoice->startProcessing($actor);
            if ($outcome === 'completed') {
                $invoice->setStatus('Completed', $actor);
                $this->pay($invoice, $volume, (float) $invoice->getTotal(), $invoiceDaysAgo, $actor);
            }
            $this->em->flush();
        } elseif ($outcome === 'closed' || $outcome === 'cancelled-and-reraised') {
            // Most goods that were paid for were also shipped: walk the invoice's fulfilment to
            // Completed as the invoice screen does, one named action at a time.
            if ($volume->chance(0.75)) {
                $invoice->startProcessing($actor);
                $invoice->setStatus('Completed', $actor);
            }
            $this->pay($invoice, $volume, (float) $invoice->getTotal(), $invoiceDaysAgo, $actor);
            $this->em->flush();
        } elseif ($outcome === 'invoiced-part-paid') {
            $this->pay($invoice, $volume, (float) $invoice->getTotal() * $volume->float(0.2, 0.7), $invoiceDaysAgo, $actor, 1);
            $this->em->flush();
        }

        $this->tallyInvoice($invoice);

        return $ids;
    }

    /**
     * An invoice raised from an order carries that order's date; a real one is issued a day or two
     * later and falls due 30 days after that.
     */
    private function dateInvoice(Invoice $invoice, int $daysAgo): void
    {
        $invoice->setDocumentDate((new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->format('Y-m-d'));
        $invoice->setDueDate((new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->modify('+30 days')->format('Y-m-d'));
    }

    /**
     * Payments through Invoice::recordPayment(), in one to three instalments totalling $amount.
     * The payment status follows at flush (InvoicePaymentStatusSubscriber).
     */
    private function pay(Invoice $invoice, DemoVolume $volume, float $amount, int $invoiceDaysAgo, DocumentActor $actor, ?int $instalments = null): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $instalments ??= $volume->weightedInt([1 => 65, 2 => 28, 3 => 7]);
        $remaining = $amount;
        $day = $invoiceDaysAgo;

        for ($n = 1; $n <= $instalments; ++$n) {
            $part = $n === $instalments ? $remaining : round($amount * $volume->float(0.25, 0.5), 2);
            $remaining = round($remaining - $part, 2);
            $day = max(0, $day - $volume->int(3, 25));

            $payment = (new InvoicePayment())
                ->setAmount(DemoVolume::money($part))
                ->setMethod($volume->pick(DemoVolume::PAYMENT_METHODS))
                ->setComment($n === $instalments && $instalments > 1 ? 'Balance of account.' : $volume->pick([null, 'Deposit on account.', 'Cheque received at the counter.', 'EFT remittance.']))
                ->setReceivedAt($volume->at($day));

            $invoice->recordPayment($actor, $payment);
            $this->em->persist($payment);
            $this->bump('invoice_payment');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Backorders

    /**
     * Orders that promise units nobody has yet.
     *
     * A backorder is not a status an order can be given — it is what `BackorderSplitResolver` works
     * out at approval, and only for a SKU whose `product_inventory` row has `allow_backorder` set.
     * No existing row has it, and turning it on for a product this seeder does not own would be
     * writing to somebody else's data, so the products below are the seeder's own: a handful of
     * short-stocked SKUs an order can outrun. Each order pairs ordinary in-stock lines with one or
     * two of those, which is the shape a real one has — part ships, part is a promise.
     */
    public function seedBackordersOnly(int $count, DemoVolume $volume, ?callable $progress = null): array
    {
        $progress ??= static function (string $message): void {};
        $this->counts = [];
        $this->outcomes = [];

        $context = $this->context();
        if ($context['products'] === []) {
            return ['outcomes' => [], 'notes' => ['No simple-mode product has stock in any selling region; nothing to sell.']];
        }

        $companyIds = array_map('intval', $this->connection->fetchFirstColumn(
            sprintf('SELECT id FROM company WHERE %s ORDER BY id', DemoSeed::WHERE_COMPANY),
        ));
        if ($companyIds === []) {
            return ['outcomes' => [], 'notes' => ['No seeded company to order against; run the full seeder first.']];
        }

        $this->seedBackorderOrders($count, $volume, $context, $companyIds);
        $progress(sprintf('backorder orders: %d', $this->counts['backorder_orders'] ?? 0));

        $this->checkpoint->__invoke();

        return ['outcomes' => $this->outcomes, 'notes' => $this->backorderNotes()];
    }

    /**
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     * @param list<int> $companyIds
     */
    private function seedBackorderOrders(int $count, DemoVolume $volume, array $context, array $companyIds): void
    {
        $productIds = $this->backorderProducts(max(8, intdiv($count, 12)), $volume, $context);
        if ($productIds === []) {
            return;
        }

        $actor = DocumentActor::automation(self::ACTOR);

        for ($i = 0; $i < $count; ++$i) {
            $company = $this->em->find(Company::class, $volume->pick($companyIds));
            if (!$company instanceof Company) {
                continue;
            }

            // Recent, because a promise made a year ago and still open would say the warehouse never
            // restocks rather than that the queue has rows.
            $daysAgo = $volume->daysAgo(75);
            $order = $this->buildOrder($company, $volume, $context, $daysAgo);
            $this->addBackorderLines($order, $productIds, $volume);

            $this->em->persist($order);
            $this->em->flush();
            $this->bump('orders');

            // The same refusal the admin screen shows. A SKU whose cap is already spoken for is a
            // real "no", so the order stays Draft rather than being forced through.
            if ($this->stock->shortfallsForOrder($order, $this->em) !== []) {
                $this->bump('backorder_orders_refused');
                $this->tally('sales_order', $order->getStatus());

                continue;
            }

            $order->setStatus('Approved', $actor, 'Order approved.');
            $this->backorders->applyToOrderLines($order, $order->getStatus(), $this->em);
            $this->em->flush();

            $promised = '0.0000';
            $shippable = [];
            foreach ($order->getLines() as $line) {
                $promised = QuantityScale::add($promised, $line->getBackorderedUnits());
                if (!$line->isBackordered()) {
                    $shippable[] = $line;
                }
            }
            $promised = (int) floor((float) $promised);

            if ($promised > 0) {
                $this->bump('backorder_orders');
                $this->bump('backordered_units', $promised);
            }

            // A third of them have already shipped what was on the shelf, which is what puts an
            // order on the backorder screen with an invoice against it.
            if ($promised > 0 && $shippable !== [] && $volume->chance(0.33)) {
                $invoice = $this->invoicing->invoiceFromOrder(
                    $order,
                    array_map(
                        static fn (SalesOrderLine $line): array => ['line' => $line, 'quantity' => $line->getQuantity(), 'price' => null],
                        $shippable,
                    ),
                    $this->em,
                    $actor,
                    InvoiceIssueIntent::Issue,
                    [],
                );
                $this->dateInvoice($invoice, max(0, $daysAgo - $volume->int(0, 2)));
                $this->em->flush();
                $this->tallyInvoice($invoice);
                $this->bump('backorder_orders_part_shipped');
            }

            $this->tally('sales_order', $order->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();
    }

    /** @return list<string> */
    private function backorderNotes(): array
    {
        $notes = [];
        if (($this->counts['backorder_orders_refused'] ?? 0) > 0) {
            $notes[] = sprintf(
                '%d order(s) stayed Draft because AdminOrderStockValidator refused the quantity outright.',
                $this->counts['backorder_orders_refused'],
            );
        }

        return $notes;
    }

    /**
     * One to two lines for more units than the SKU holds.
     *
     * The quantity is not what makes the line a backorder — the split at approval is — so this only
     * has to outrun the stock the product was created with.
     *
     * @param list<int> $productIds
     */
    private function addBackorderLines(SalesOrder $order, array $productIds, DemoVolume $volume): void
    {
        $sort = $order->getLines()->count();
        $subtotal = (float) $order->getSubtotal();

        foreach ($volume->sample($productIds, $volume->weightedInt([1 => 70, 2 => 30])) as $productId) {
            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $quantity = (float) $volume->pick([8, 12, 12, 16, 20, 24, 32]);
            $price = (float) $product->getDefaultPrice();
            $lineSubtotal = $price * $quantity;
            $subtotal += $lineSubtotal;

            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setUnit($product->getUnit())
                    ->setQuantity(number_format($quantity, 2, '.', ''))
                    ->setCost((string) ($product->getCostPrice() ?? '0.00'))
                    ->setPrice(DemoVolume::money($price))
                    ->setSubtotal(DemoVolume::money($lineSubtotal))
                    ->setTaxCode($product->getSalesTaxCode() ?? 'Taxable')
                    // An ETA is a date the stock is expected, so it is ahead of today, not behind it.
                    ->setRestockEta($volume->chance(0.6) ? new \DateTimeImmutable(sprintf('+%d days', $volume->int(5, 40))) : null)
                    ->setSortOrder($sort++),
            );
        }

        $tax = round($subtotal * 0.05, 2);
        $order
            ->setSubtotal(DemoVolume::money($subtotal))
            ->setTax(DemoVolume::money($tax))
            ->setTotal(DemoVolume::money($subtotal + $tax));
    }

    /**
     * The seeder's own short-stocked, backorder-enabled SKUs, reused across runs.
     *
     * Modelled on a product that already sells in this region so the line reads like the rest of the
     * catalogue, with a few units on the shelf: enough that part of an order ships, not enough to
     * cover it.
     *
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     *
     * @return list<int>
     */
    private function backorderProducts(int $wanted, DemoVolume $volume, array $context): array
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id FROM product_core p JOIN product_inventory pi ON pi.product_id = p.id
             WHERE p.sku LIKE 'DEMO-BO-%' AND pi.warehouse_id = ? AND pi.allow_backorder = 1 ORDER BY p.id",
            [$context['warehouseId']],
        ));
        if (\count($ids) >= $wanted) {
            return $ids;
        }

        $warehouse = $this->em->find(Warehouse::class, $context['warehouseId']);
        if (!$warehouse instanceof Warehouse) {
            return $ids;
        }

        $next = 1 + (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTR(sku, 9) AS INTEGER)), 0) FROM product_core WHERE sku LIKE 'DEMO-BO-%'",
        );

        while (\count($ids) < $wanted) {
            $template = $this->em->find(ProductCore::class, $volume->pick($context['products']));
            if (!$template instanceof ProductCore) {
                continue;
            }

            $product = (new ProductCore())
                ->setSku(sprintf('DEMO-BO-%05d', $next++))
                ->setName(sprintf('%s (Special Order)', $template->getName()))
                ->setCategory($template->getCategory())
                ->setUnit($template->getUnit())
                ->setCostPrice((string) ($template->getCostPrice() ?? '0.00'))
                ->setDefaultPrice((string) $template->getDefaultPrice())
                ->setOriginalPrice((string) $template->getDefaultPrice())
                ->activate()
                ->setVisible(true)
                ->setSalesTaxCode($template->getSalesTaxCode() ?? 'Taxable')
                ->setShortDescription('Ordered in for the customer; stocked thinly on purpose.')
                ->setSyncSource(DemoSeed::PRODUCT_SYNC_SOURCE);

            $this->em->persist($product);

            // Thin stock and no ceiling: the cap is the other half of the feature and belongs to a
            // product an admin configured, not to every SKU this seeder makes.
            $this->em->persist(
                (new ProductInventory())
                    ->setProduct($product)
                    ->setWarehouse($warehouse)
                    ->setQuantity($volume->int(2, 9))
                    ->setAllowBackorder(true)
                    ->setMaxBackorderQuantity($volume->chance(0.25) ? $volume->int(200, 600) : null),
            );

            $this->em->flush();
            $ids[] = (int) $product->getId();
            $this->bump('backorder_products');
        }

        ($this->checkpoint)();

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // Estimates

    /**
     * Quotes in every EstimateStatus. The Accepted ones are accepted the way the CUSTOMER's quote
     * screen accepts them — EstimateConversionService with its default invoicing — which raises the
     * order and its invoice in one step. The admin screens no longer work that way (accept, convert,
     * invoice are three actions there), and this seeder deliberately still models the customer path:
     * the demo data wants orders that are already billed.
     *
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     * @param list<int> $companyIds
     *
     * @return list<int> ids of the invoices conversion raised
     */
    private function seedEstimates(int $count, DemoVolume $volume, array $context, array $companyIds): array
    {
        $invoiceIds = [];

        for ($i = 0; $i < $count; ++$i) {
            $company = $this->em->find(Company::class, $volume->pick($companyIds));
            if (!$company instanceof Company) {
                continue;
            }

            $daysAgo = $volume->daysAgo();
            $status = $volume->weighted(['Draft' => 15, 'Submitted' => 20, 'Priced' => 25, 'Accepted' => 25, 'Rejected' => 15]);
            $priced = $status !== 'Submitted' && ($status !== 'Draft' || $volume->chance(0.5));

            $estimate = (new Estimate())
                ->setCompany($company)
                ->setDocumentNumber($this->estimateNumbers->next($this->em))
                ->setFulfillmentRegion($context['region'])
                ->setDocumentDate($volume->date($daysAgo))
                ->setSource($volume->weighted(['Customer' => 55, 'Admin' => 45]))
                ->setUserName($company->getFirstName() . ' ' . $company->getLastName());

            // Out of the chain since the status seam: setStatus() returns the resulting status
            // rather than $this, and it takes the actor whose name goes on the timeline row. The
            // Accepted ones are seeded Priced and then accepted through the conversion service
            // below, which is the customer path this seeder deliberately still models.
            $estimate->setStatus($status === 'Accepted' ? 'Priced' : $status, DocumentActor::automation(self::ACTOR));

            if ($volume->chance(0.5)) {
                $estimate->setPoNumber(sprintf('RFQ-%05d', $volume->int(1000, 99999)));
            }

            $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
            $estimate->setShippingAddressFrom($company->getDefaultShippingAddress() ?? $company->getDefaultBillingAddress());
            $estimate->snapshotCompany($company);

            $subtotal = 0.0;
            $sort = 0;
            foreach ($volume->sample($context['products'], $volume->int(1, 4)) as $productId) {
                $product = $this->em->find(ProductCore::class, $productId);
                if (!$product instanceof ProductCore) {
                    continue;
                }

                $quantity = (float) $volume->pick([4, 4, 8, 12, 16, 20, 24, 40]);
                // A quote is often priced below list — that is what a quote is for.
                $price = $priced ? round((float) $product->getDefaultPrice() * $volume->float(0.85, 1.0), 2) : null;
                $lineSubtotal = $price === null ? null : $price * $quantity;
                $subtotal += $lineSubtotal ?? 0.0;

                $estimate->addLine(
                    (new EstimateLine())
                        ->setProduct($product)
                        ->setName($product->getName())
                        ->setSku($product->getSku())
                        ->setUnit($product->getUnit())
                        ->setQuantity(number_format($quantity, 2, '.', ''))
                        ->setCost((string) ($product->getCostPrice() ?? '0.00'))
                        ->setPrice($price === null ? null : DemoVolume::money($price))
                        ->setSubtotal($lineSubtotal === null ? null : DemoVolume::money($lineSubtotal))
                        ->setTaxCode($product->getSalesTaxCode() ?? 'Taxable')
                        ->setSortOrder($sort++),
                );
            }

            // A priced quote states its shipping, even when it is nothing — Estimate::isFullyPriced()
            // reads "no shipping row" as "shipping still TBD". Written as the quote form writes a
            // typed-in shipping amount: a manual type=shipping charge row.
            $shipping = 0.0;
            if ($priced) {
                $shipping = $volume->chance(0.4) ? 0.0 : round($volume->float(25, 180), 2);
                $estimate->setFeeLines(FeeLineSnapshot::encode(SalesDocumentChargeLines::toShippingLines(
                    [['label' => 'Custom Shipping', 'amount' => $shipping, 'type' => FeeLine::TYPE_SHIPPING]],
                    $estimate->getHighestTaxClass(),
                )));
            }

            $tax = round(($subtotal + $shipping) * 0.05, 2);
            $estimate
                ->setSubtotal(DemoVolume::money($subtotal))
                ->setTax(DemoVolume::money($tax))
                ->setTotal(DemoVolume::money($subtotal + $shipping + $tax));

            $this->em->persist($estimate);
            $this->em->flush();
            $this->bump('estimates');

            if ($status === 'Accepted') {
                // Flushed above first: the conversion claims the estimate with a conditional UPDATE
                // on its id, which an unflushed row does not have.
                $order = $this->conversion->convert($estimate, $this->em, self::ACTOR);
                $this->em->flush();
                $this->bump('orders_from_estimates');

                foreach ($order->getInvoices() as $invoice) {
                    $invoiceIds[] = (int) $invoice->getId();
                    $this->tallyInvoice($invoice);
                }
                $this->tally('sales_order', $order->getStatus());
            }

            $this->tally('estimate', $estimate->getStatus());

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $invoiceIds;
    }

    // ---------------------------------------------------------------------------------------------
    // Sales returns

    /**
     * RMAs against issued invoices, in every SalesReturnStatus, walked with the entity's own named
     * actions exactly as SalesReturnController does — including SalesReturnReceivedEvent after a
     * receipt, which is what puts dimensional stock back (the catalogue here is simple, so the
     * subscriber records nothing, which is also what the screen would do).
     *
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     * @param list<int> $invoiceIds
     *
     * @return list<int> return ids that reached Received or Closed
     */
    private function seedSalesReturns(int $count, DemoVolume $volume, array $context, array $invoiceIds): array
    {
        $receivedIds = [];
        $reasons = ['Wrong size ordered', 'Damaged in transit', 'Customer changed their mind', 'Duplicate shipment', 'Defective — sidewall bulge', 'Wrong load index'];
        $candidates = $this->returnableInvoices($invoiceIds);

        for ($i = 0; $i < $count && $candidates !== []; ++$i) {
            $invoice = $this->em->find(Invoice::class, $volume->pick($candidates));
            if (!$invoice instanceof Invoice || $invoice->getCompany() === null) {
                continue;
            }

            $reason = $volume->pick($reasons);
            $return = (new SalesReturn())
                ->setCompany($invoice->getCompany())
                ->setDocumentNumber($this->salesReturnNumbers->next($this->em))
                ->setInvoice($invoice)
                ->setSalesOrder($invoice->getSalesOrder())
                ->setReason($reason)
                ->setNotes($volume->chance(0.3) ? 'Customer will drop the units at the counter.' : null);

            $sort = 0;
            foreach ($volume->sample($invoice->getLines()->toArray(), $volume->int(1, 2)) as $line) {
                /** @var InvoiceLine $line */
                if (!$line->getProduct() instanceof ProductCore) {
                    continue;
                }
                $billed = max(1, (int) floor((float) $line->getQuantity()));
                $return->addLine(
                    (new SalesReturnLine())
                        ->setProduct($line->getProduct())
                        ->setInvoiceLine($line)
                        ->setName($line->getName())
                        ->setSku($line->getSku())
                        ->setQuantity(number_format((float) $volume->int(1, min(4, $billed)), 2, '.', ''))
                        ->setReason($reason)
                        ->setSortOrder($sort++),
                );
            }

            if ($sort === 0) {
                continue;
            }

            $this->em->persist($return);
            $this->em->flush();
            $this->bump('sales_returns');

            $target = $volume->weighted(['Requested' => 18, 'Authorised' => 20, 'Received' => 27, 'Closed' => 20, 'Declined' => 15]);

            if ($target !== 'Requested') {
                $return->authorise();
                $this->em->flush();
            }

            if (\in_array($target, ['Received', 'Closed'], true) || ($target === 'Declined' && $volume->chance(0.5))) {
                $warehouse = $this->em->find(Warehouse::class, $context['warehouseId']);
                $return->receive($warehouse);
                foreach ($return->getLines() as $line) {
                    $line->setDisposition($volume->pick(['restock', 'restock', 'damaged', 'scrap', 'inspect']));
                }
                $this->em->flush();
                $this->events->dispatch(new SalesReturnReceivedEvent($return));
            }

            if ($target === 'Closed') {
                $return->close();
                $this->em->flush();
            } elseif ($target === 'Declined') {
                $return->decline($volume->pick(['Outside the return window.', 'Tyres have been mounted.', 'Not purchased from us.']));
                $this->em->flush();
            }

            if (\in_array($return->getStatus(), [SalesReturnStatus::Received, SalesReturnStatus::Closed], true)) {
                $receivedIds[] = (int) $return->getId();
            }

            $this->tally('sales_return', $return->getStatus()->value);

            if (($i + 1) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        return $receivedIds;
    }

    /**
     * @param list<int> $invoiceIds
     *
     * @return list<int> the ones a customer could return goods against: issued and not cancelled
     */
    private function returnableInvoices(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return array_map('intval', $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT id FROM invoice WHERE id IN (%s) AND status IN ('Pending', 'Processing', 'Completed')",
                implode(',', array_map('intval', $invoiceIds)),
            ),
        ));
    }

    // ---------------------------------------------------------------------------------------------
    // Credit memos

    /**
     * Credit memos in every CreditMemoStatus: against an invoice (lines capped at what is still
     * uncredited, as the controller caps them), against a received sales return, and standalone
     * goodwill credits. Open ones are then applied to their invoice or refunded, which is what
     * settles them to Closed — there is no setter for that either.
     *
     * @param list<int> $invoiceIds
     * @param list<int> $returnIds
     * @param list<int> $companyIds
     */
    private function seedCreditMemos(int $count, DemoVolume $volume, array $invoiceIds, array $returnIds, array $companyIds): void
    {
        $candidates = $this->returnableInvoices($invoiceIds);
        $returns = $returnIds;
        $types = array_map('intval', $this->connection->fetchFirstColumn("SELECT id FROM credit_memo_type WHERE status = 'Active'"));

        // Attempts rather than iterations: an invoice line already credited in full yields no
        // line, and that memo is skipped rather than saved empty.
        for ($i = 0, $attempts = 0; $i < $count && $attempts < $count * 3; ++$attempts) {
            $kind = $volume->weighted(['invoice' => 55, 'return' => 25, 'standalone' => 20]);
            if ($kind === 'return' && $returns === []) {
                $kind = 'invoice';
            }
            if ($kind === 'invoice' && $candidates === []) {
                $kind = 'standalone';
            }

            $memo = $this->buildCreditMemo($kind, $volume, $candidates, $returns, $companyIds);
            if (!$memo instanceof CreditMemo) {
                continue;
            }

            if ($types !== [] && $volume->chance(0.8)) {
                $memo->setCreditMemoTypeId($volume->pick($types));
            }

            $this->em->persist($memo);
            $this->em->flush();
            $this->bump('credit_memos');

            $target = $volume->weighted(['Draft' => 18, 'Open' => 20, 'Applied' => 32, 'PartApplied' => 8, 'Refunded' => 12, 'Void' => 10]);

            if ($target !== 'Draft' && (float) $memo->getTotal() > 0) {
                $memo->issue();
                $this->em->flush();
                // After the flush, as CreditMemoController does: the restock listener reads the
                // persisted memo.
                $this->events->dispatch(new CreditMemoIssuedEvent($memo));

                $invoice = $memo->getInvoice();
                $balance = (float) $memo->getBalance();

                if (($target === 'Applied' || $target === 'PartApplied') && $invoice instanceof Invoice && !$invoice->isStatus('Cancelled')) {
                    $amount = $target === 'Applied' ? $balance : round($balance * $volume->float(0.3, 0.7), 2);
                    $application = $memo->applyTo($invoice, DemoVolume::money($amount), $volume->at(max(0, $this->daysAgoOf($memo->getDocumentDate()) - $volume->int(0, 10))));
                    $this->em->persist($application);
                    $this->em->flush();
                } elseif ($target === 'Refunded' || (($target === 'Applied' || $target === 'PartApplied') && !$invoice instanceof Invoice)) {
                    $refund = (new CreditMemoRefund())
                        ->setRefundedAt($volume->at(max(0, $this->daysAgoOf($memo->getDocumentDate()) - $volume->int(1, 14))))
                        ->setMethod($volume->pick(DemoVolume::REFUND_METHODS))
                        ->setAmount(DemoVolume::money($balance))
                        ->setComment($volume->pick([null, 'Refunded to the card on file.', 'Cheque mailed to AP.']));
                    $memo->recordRefund($refund);
                    $this->em->persist($refund);
                    $this->em->flush();
                } elseif ($target === 'Void') {
                    $memo->setStatus('Void', DocumentActor::automation(self::ACTOR));
                    $this->em->flush();
                }
            } elseif ($target === 'Void') {
                $memo->setStatus('Void', DocumentActor::automation(self::ACTOR));
                $this->em->flush();
            }

            $this->tally('credit_memo', $memo->getStatus()->value);

            if ((++$i) % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();
    }

    /**
     * @param list<int> $candidates invoice ids
     * @param list<int> $returns    sales return ids
     * @param list<int> $companyIds
     */
    private function buildCreditMemo(string $kind, DemoVolume $volume, array $candidates, array $returns, array $companyIds): ?CreditMemo
    {
        $return = null;
        $invoice = null;

        if ($kind === 'return') {
            $return = $this->em->find(SalesReturn::class, $volume->pick($returns));
            $invoice = $return?->getInvoice();
        } elseif ($kind === 'invoice') {
            $invoice = $this->em->find(Invoice::class, $volume->pick($candidates));
        }

        $company = $invoice?->getCompany() ?? $return?->getCompany()
            ?? $this->em->find(Company::class, $volume->pick($companyIds));
        if (!$company instanceof Company) {
            return null;
        }

        $baseDaysAgo = $invoice instanceof Invoice ? $this->daysAgoOf($invoice->getDocumentDate()) : $volume->daysAgo();

        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setInvoice($invoice)
            ->setSalesReturn($return)
            ->setDocumentNumber($this->creditMemoNumbers->next($this->em))
            ->setDocumentDate($volume->date(max(0, $baseDaysAgo - $volume->int(2, 30))))
            ->setRestock(false);

        if ($invoice instanceof Invoice) {
            foreach ($invoice->getAddresses() as $address) {
                $memo->copyAddressFrom($address);
            }
            $memo->copyCompanySnapshotFrom($invoice);
            $memo->setFulfillmentRegion($invoice->getFulfillmentRegion());
        }

        $subtotal = 0.0;
        $sort = 0;

        if ($return instanceof SalesReturn) {
            $memo->setReason('Credit for returned goods: ' . ($return->getReason() ?? 'see RMA ' . $return->getDocumentNumber()));
            foreach ($return->getLines() as $returnLine) {
                $invoiceLine = $returnLine->getInvoiceLine();
                if (!$invoiceLine instanceof InvoiceLine) {
                    continue;
                }
                $units = min((int) floor((float) $returnLine->getQuantity()), $this->uncredited($invoiceLine));
                $subtotal += $this->addCreditLine($memo, $invoiceLine, $units, $sort);
            }
        } elseif ($invoice instanceof Invoice) {
            $memo->setReason($volume->pick(['Short shipment', 'Price adjustment agreed with the rep', 'Damaged on arrival', 'Pricing error on the invoice']));
            foreach ($volume->sample($invoice->getLines()->toArray(), $volume->int(1, 2)) as $invoiceLine) {
                $uncredited = $this->uncredited($invoiceLine);
                $subtotal += $this->addCreditLine($memo, $invoiceLine, min($uncredited, $volume->int(1, 4)), $sort);
            }
        } else {
            $memo->setReason($volume->pick(['Goodwill credit', 'Delivery delay', 'Loyalty rebate', 'Freight overcharge']));
            $amount = round($volume->float(15, 450), 2);
            $memo->addLine(
                (new CreditMemoLine())
                    ->setName($memo->getReason() ?? 'Credit')
                    ->setQuantity('1.00')
                    ->setPrice(DemoVolume::money($amount))
                    ->setSubtotal(DemoVolume::money($amount))
                    ->setTaxCode('Taxable')
                    ->setSortOrder($sort++),
            );
            $subtotal += $amount;
        }

        if ($sort === 0) {
            return null;
        }

        foreach ($memo->getLines() as $line) {
            $this->em->persist($line);
        }

        $tax = round($subtotal * 0.05, 2);
        $memo->setSubtotal(DemoVolume::money($subtotal))->setTax(DemoVolume::money($tax))->setTotal(DemoVolume::money($subtotal + $tax));

        return $memo;
    }

    /** Billed units not already on another credit memo — the controller's cap. */
    private function uncredited(InvoiceLine $line): int
    {
        return max(0, (int) floor((float) $line->getQuantity()) - (int) floor((float) $line->getCreditedUnits()));
    }

    private function addCreditLine(CreditMemo $memo, InvoiceLine $invoiceLine, int $units, int &$sort): float
    {
        if ($units <= 0) {
            return 0.0;
        }

        $price = (float) ($invoiceLine->getPrice() ?? 0);
        $memo->addLine(
            (new CreditMemoLine())
                ->setInvoiceLine($invoiceLine)
                ->setProduct($invoiceLine->getProduct())
                ->setName($invoiceLine->getName())
                ->setSku($invoiceLine->getSku())
                ->setLocation($invoiceLine->getLocation())
                ->setUnit($invoiceLine->getUnit())
                ->setTaxCode($invoiceLine->getTaxCode())
                ->setQuantity(number_format((float) $units, 2, '.', ''))
                ->setPrice(DemoVolume::money($price))
                ->setSubtotal(DemoVolume::money($price * $units))
                ->setSortOrder($sort++),
        );

        return $price * $units;
    }

    // ---------------------------------------------------------------------------------------------
    // Available units

    /**
     * Lists a few larger terms on existing products, through ProductAvailableUnitService::apply() —
     * the call the product form makes (#659).
     *
     * Two halves, because #659 makes them two. The TERMS are global and instance-wide, so they are
     * created once here if the catalogue does not already hold them: `BOX-12` holds twelve for every
     * product that names it, which is the whole reason a twelve is not typed onto hundreds of rows.
     * The LIST is per product, and it is the thing that stops that catalogue reaching the order
     * screen.
     *
     * Only products that have DECLARED a base unit are touched, and only terms of that base unit's
     * family are offered — a product with no base unit has no family to draw from, and one measured
     * by weight is not offered a count. Nothing here writes `product_core.unit_id`, so this can run
     * against products the seeder did not create without restating anything.
     *
     * @param array{region: string, warehouseId: int, products: list<int>, priceLists: list<int>} $context
     */
    private function seedAvailableUnits(int $count, DemoVolume $volume, array $context): int
    {
        $terms = $this->demoTerms();
        if ($terms === []) {
            return 0;
        }

        $created = 0;
        $products = $volume->sample($context['products'], \count($context['products']));

        foreach ($products as $productId) {
            if ($created >= $count) {
                break;
            }

            $product = $this->em->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $family = $this->availableUnits->familyOf($product);
            if ($family === null) {
                continue;
            }

            $wanted = [];
            foreach ($this->availableUnits->choicesFor($product) as $already) {
                $wanted[(int) $already->getId()] = true;
            }

            foreach ($volume->pick($this->termPicks()) as $code) {
                $term = $terms[$code] ?? null;
                if (!$term instanceof UnitOfMeasure || $term->getFamily() !== $family) {
                    continue;
                }

                $wanted[(int) $term->getId()] = true;
            }

            try {
                $this->availableUnits->apply($product, array_keys($wanted), null);
            } catch (UnitOfMeasureRefusal) {
                // A demo seeder never argues with a refusal: the product keeps the list it had.
                continue;
            }

            ++$created;

            if ($created % self::BATCH === 0) {
                ($this->checkpoint)();
            }
        }

        $this->em->flush();
        ($this->checkpoint)();

        return $created;
    }

    /**
     * The demo terms, created if the catalogue does not already hold them.
     *
     * Real ratios, because the seeded four were all `quantity` at 1 and #659 opens by calling that
     * catalogue incoherent: a pair is not one and a box is not one.
     *
     * @return array<string, UnitOfMeasure>
     */
    private function demoTerms(): array
    {
        $wanted = [
            'PR' => ['Pair', '2'],
            'BOX-12' => ['Box of 12', '12'],
            'CASE-24' => ['Case of 24', '24'],
            'PALLET-240' => ['Pallet of 240', '240'],
        ];

        $terms = [];
        foreach ($wanted as $code => [$name, $factor]) {
            $existing = $this->em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
            if ($existing instanceof UnitOfMeasure) {
                $terms[$code] = $existing;

                continue;
            }

            try {
                $terms[$code] = $this->units->add($code, $name, UnitOfMeasure::FAMILY_QUANTITY, $factor, '1');
            } catch (UnitOfMeasureRefusal) {
                // A code collision with something a person created: leave theirs alone.
            }
        }

        return $terms;
    }

    /**
     * The shapes a demo product is listed in — one, two or three terms, never all four.
     *
     * @return list<list<string>>
     */
    private function termPicks(): array
    {
        return [
            ['BOX-12'],
            ['BOX-12', 'PALLET-240'],
            ['CASE-24'],
            ['PR', 'BOX-12'],
        ];
    }

    // ---------------------------------------------------------------------------------------------

    private function daysAgoOf(?string $date): int
    {
        if ($date === null || $date === '') {
            return 0;
        }

        return max(0, (int) (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($date))->days);
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    private function tally(string $table, string $status): void
    {
        $this->outcomes[$table][$status] = ($this->outcomes[$table][$status] ?? 0) + 1;
    }

    private function tallyInvoice(Invoice $invoice): void
    {
        $this->tally('invoice', $invoice->getStatus()->value);
    }
}
