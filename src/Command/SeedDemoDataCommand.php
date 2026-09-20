<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Demo\DemoDataCleaner;
use App\Command\Demo\DemoInventoryInvariant;
use App\Command\Demo\DemoSeed;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Enum\InvoiceIssueIntent;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\DocumentActor;
use App\Service\EstimateNumberGenerator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\OrderInvoicingService;
use App\Service\OrderNumberGenerator;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fills the sell side of an empty database with data an admin screen can actually be reviewed
 * against: companies, catalogue, stock, orders across their whole lifecycle, invoices, payments and
 * quotes.
 *
 * ## Why this is a committed command rather than a script
 *
 * There have been two previous versions of this fixture, both left as one-off scripts outside the
 * repository, and both bit-rotted to the point of not running. The rot is not incidental — it is
 * what a fixture that nothing compiles or tests always does. Committing it puts it in front of every
 * `grep`, every rename tool and every reviewer, which is the only thing that has ever kept a fixture
 * honest. Some of what it cost is worth recording, because each is a real change in the model that a
 * script written against the old shape silently misrepresented:
 *
 * | Was                                     | Is now                                                    |
 * |-----------------------------------------|-----------------------------------------------------------|
 * | `ProductInventory::setFulfillmentRegion()` | `setWarehouse()` — stock lives in a building (#546)     |
 * | `SalesOrder::setStatus('Completed')`    | there is no setter; see the lifecycle section below        |
 * | `SalesOrderPayment` / `sales_order_payment` | `InvoicePayment` — money belongs to the invoice (#539) |
 *
 * The middle row is the interesting one. `Completed`, `Processing`, `On Hold` and `Pending` were
 * never SalesOrder statuses at all: they are `InvoiceStatus` values, and the old seeder was writing
 * them into `sales_order.status` because a string setter accepted them. Every screen showed an order
 * in a state the deriver would have overwritten on the next flush.
 *
 * ## The order lifecycle, which is the whole reason this file is long
 *
 * A SalesOrder has exactly two statuses a human sets — `Approved` and `Void`, both named at the one
 * gate, `setStatus()`. Everything from
 * `Partially Invoiced` onward is DERIVED from the invoice set by SalesOrderStatusDeriver, run by
 * SalesOrderDerivedStatusSubscriber on every flush that touches an order, one of its invoices, or
 * one of those invoices' payments. So this command cannot ask for an order in a given state; it has
 * to produce the facts that state is a summary of:
 *
 * | Wanted state         | What this seeder actually does                                        |
 * |----------------------|-----------------------------------------------------------------------|
 * | `Draft`              | build the order, persist, stop                                        |
 * | `Approved`           | `setStatus('Approved', ...)`                                          |
 * | `Approved` + a draft invoice | `invoiceInFull(..., KeepDraft)` — a draft invoice counts for nothing |
 * | `Partially Invoiced` | `invoiceFromOrder()` for part of the quantity, issued                 |
 * | `Invoiced`           | `invoiceInFull(..., Issue)` and leave it unpaid, or part-paid         |
 * | `Closed`             | the same, then `recordPayment()` covering the total                   |
 * | `Void`               | `setStatus('Void', ...)`                                              |
 *
 * That is also why nothing here calls `applyDerivedStatus()` even though it is public: it exists for
 * the deriver, and a caller that used it would be writing an answer the next flush recomputes.
 *
 * ## What it deliberately does not do
 *
 * Products here are all `simple`: their stock is the number an admin types into the inventory grid,
 * written straight to `product_inventory.quantity`. Dimensional products — the ones whose quantity is
 * maintained by bins, lots and movements — belong to `app:seed-warehouse-data`, which owns the depth
 * layer and every write into it. Splitting it that way keeps this command free of any dependency on
 * the optional inventory bundles, so it still produces a reviewable sell side on a build where they
 * are switched off.
 *
 * It still asserts the depth-layer invariant on the way out (see DemoInventoryInvariant). On a
 * database where only this command has run the check covers zero pairs and that is the correct
 * answer — but it also proves that nothing on the sell side quietly created a detail row, which is
 * exactly the kind of thing that would otherwise be found much later.
 *
 * ## Re-running it
 *
 * Refuses a database that already carries demo tags unless `--force`, which purges first. The purge
 * order is DemoDataCleaner's problem and its docblock explains why it is not one statement:
 * `company` and `product_core` are referenced `ON DELETE NO ACTION` by every sales document.
 */
#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Seed companies, catalogue, stock, sales orders, invoices, payments and quotes for reviewing the admin screens.',
)]
final class SeedDemoDataCommand extends Command
{
    /**
     * The two regions, and therefore the two warehouses, everything sell-side is spread across.
     *
     * Each carries the province and country of the building it creates. A warehouse may not exist
     * without a province (queue item 61) and a seeder is one of the few callers that CAN state one
     * honestly, because it authors its own world: "Demo West" is in BC and "Demo East" is in
     * Ontario, which is also what makes the demo data price two different tax regimes instead of
     * quoting $0.00 twice.
     *
     * @var array<string, array{province: string, country: string}>
     */
    private const REGIONS = [
        'Demo West' => ['province' => 'BC', 'country' => 'CA'],
        'Demo East' => ['province' => 'ON', 'country' => 'CA'],
    ];

    /** Password every seeded customer user gets. Demo instances only; there is nothing real behind it. */
    private const CUSTOMER_PASSWORD = 'demo-password';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly DemoDataCleaner $cleaner,
        private readonly DemoInventoryInvariant $invariant,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly CompanyFulfillmentRegionService $companyRegions,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly EstimateNumberGenerator $estimateNumbers,
        private readonly OrderInvoicingService $invoicing,
        private readonly BackorderSplitResolver $backorders,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Delete every existing demo-tagged row (sell side AND warehouse side) and seed again.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if ($this->cleaner->isSeeded() && !$force) {
            $io->error([
                'This database already carries demo data.',
                sprintf(
                    'Companies with %s, products with %s, or vendors with %s already exist.',
                    DemoSeed::WHERE_COMPANY,
                    DemoSeed::WHERE_PRODUCT,
                    DemoSeed::WHERE_VENDOR,
                ),
                'Re-run with --force to delete it and seed again.',
            ]);

            return Command::FAILURE;
        }

        if ($force) {
            $io->section('Purging existing demo data');
            $purged = $this->cleaner->purgeAll();
            $io->writeln($purged === [] ? '  nothing to purge' : $this->formatCounts($purged));
        }

        $actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);
        $counts = [];

        $io->section('Seeding');

        $warehouses = $this->seedRegionsAndWarehouses();
        $counts['fulfillment_region'] = \count($warehouses);
        $counts['warehouse'] = \count($warehouses);

        $priceLists = $this->seedPriceLists();
        $counts['price_list'] = \count($priceLists);

        $counts += $this->seedConfigTables();

        [$products, $categories] = $this->seedCatalogue($priceLists);
        $counts['product_category'] = \count($categories);
        $counts['product_core'] = \count($products);
        $counts['product_pricing'] = \count($products) * \count($priceLists);

        $counts['product_inventory'] = $this->seedStock($products, $warehouses);

        [$companies, $users, $addresses] = $this->seedCompanies($priceLists);
        $counts['company'] = \count($companies);
        $counts['customer_user'] = $users;
        $counts['company_address'] = $addresses;

        $ledger = $this->seedOrdersAndInvoices($companies, $products, $actor);
        $counts += $ledger;

        $counts['estimate'] = $this->seedEstimates($companies, $products);

        $io->writeln($this->formatCounts($counts));

        return $this->assertInvariant($io);
    }

    /**
     * Two fulfillment regions, each with the warehouse that serves it.
     *
     * Through WarehouseFulfillmentRegionService rather than by constructing both halves, because
     * since #546 a region and a warehouse are two rows plus a link row, and the service is what knows
     * that. A seeder that built the pair by hand would be a fourth implementation of a three-row
     * relationship and would be the one to forget the link.
     *
     * @return array<string, Warehouse> keyed by region name
     */
    private function seedRegionsAndWarehouses(): array
    {
        $warehouses = [];

        foreach (self::REGIONS as $name => $where) {
            $warehouses[$name] = $this->warehouses->warehouseForRegionNameOrCreate($name, $where['province'], $where['country']);
        }

        $this->em->flush();

        return $warehouses;
    }

    /** @return array<string, PriceList> keyed by name */
    private function seedPriceLists(): array
    {
        $lists = [];

        foreach (['Demo Wholesale', 'Demo Distributor', 'Demo Contractor'] as $name) {
            $list = (new PriceList())->setName($name)->setCurrency('CAD')->setStatus('Active');
            $this->em->persist($list);
            $lists[$name] = $list;
        }

        $this->em->flush();

        return $lists;
    }

    /**
     * The two "simple config" lookup tables the sell side reads and that ship empty.
     *
     * These are the one place in either seeder that issues an INSERT, and it is not a shortcut
     * around an entity — there is no entity. `credit_memo_type`, `payment_term` and the rest of the
     * tables in Admin\ConfigController::TABLES are plain rows with no Doctrine mapping at all, and
     * the controller that maintains them builds its INSERT the same way this does. Going "through
     * the app" here means writing the same three columns the screen writes.
     *
     * They are tagged only by the fact that a fresh database has none: the tables carry a name and a
     * status and nothing else, so there is nowhere to put a prefix that would not end up on screen
     * as part of a payment term a customer reads. The cleaner therefore leaves them alone, and this
     * skips a table that already has rows rather than duplicating them.
     *
     * @return array<string, int>
     */
    private function seedConfigTables(): array
    {
        $rows = [
            // Named for what an admin is actually recording when they raise one — the reason a
            // credit exists is the only thing the type is for.
            'credit_memo_type' => ['Damaged on arrival', 'Short shipment', 'Price adjustment', 'Returned goods', 'Goodwill'],
            'payment_term' => ['Due on receipt', 'Net 15', 'Net 30', 'Net 45', 'Net 60', 'Prepaid'],
        ];

        $counts = [];

        foreach ($rows as $table => $names) {
            if ((int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)) > 0) {
                $counts[$table] = 0;

                continue;
            }

            $sortOrder = 0;
            foreach ($names as $name) {
                $row = ['name' => $name, 'status' => 'Active', 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];

                // payment_term carries a sort order and credit_memo_type does not, which is the only
                // difference between the two tables.
                if ($table === 'payment_term') {
                    $row['sort_order'] = $sortOrder += 10;
                }

                $this->connection->insert($table, $row);
            }

            $counts[$table] = \count($names);
        }

        return $counts;
    }

    /**
     * The catalogue, plus a price on every product in every price list.
     *
     * `syncSource` is set on every product for two reasons at once: it is the demo tag, and it is
     * what stops ProductSyncSourceGuard writing an error_log row per product. That guard exists
     * because an unstamped product is invisible to the import feeds' scoping and can be clobbered by
     * one — so a seeder that left it null would be creating exactly the hazard the guard reports.
     *
     * @param array<string, PriceList> $priceLists
     *
     * @return array{0: array<string, ProductCore>, 1: array<string, ProductCategory>}
     */
    private function seedCatalogue(array $priceLists): array
    {
        // Prefixed, because product_category is one of the tables with nowhere else to put a tag —
        // no sync_source, no code — and an untagged category would survive every --force and be
        // duplicated by the next run.
        $categories = [];
        foreach (['Fasteners', 'Adhesives', 'Safety', 'Power Tools'] as $name) {
            $category = (new ProductCategory())
                ->setName(DemoSeed::SHARED_NAME_PREFIX . $name)
                ->setStatus('Active');
            $this->em->persist($category);
            $categories[$name] = $category;
        }

        // sku => [name, category, cost, list price, unit]
        $catalogue = [
            'DEMO-FS-1001' => ['Hex Bolt M10 x 60mm (box of 100)', 'Fasteners', '18.40', '32.00', 'BOX'],
            'DEMO-FS-1002' => ['Hex Nut M10 zinc (box of 250)', 'Fasteners', '9.10', '17.50', 'BOX'],
            'DEMO-FS-1003' => ['Structural Washer M10 (box of 500)', 'Fasteners', '11.75', '21.00', 'BOX'],
            'DEMO-FS-1004' => ['Self-Tapping Screw #8 x 25mm (box of 500)', 'Fasteners', '14.20', '26.90', 'BOX'],
            'DEMO-AD-2001' => ['Construction Adhesive 300ml', 'Adhesives', '4.35', '8.95', 'EA'],
            'DEMO-AD-2002' => ['Two-Part Epoxy 50ml', 'Adhesives', '7.80', '16.40', 'EA'],
            'DEMO-AD-2003' => ['Silicone Sealant Clear 300ml', 'Adhesives', '3.95', '7.85', 'EA'],
            'DEMO-SF-3001' => ['Hi-Vis Vest Class 2 (L)', 'Safety', '6.20', '14.50', 'EA'],
            'DEMO-SF-3002' => ['Safety Glasses Clear Anti-Fog', 'Safety', '2.85', '6.75', 'EA'],
            'DEMO-SF-3003' => ['Cut-Resistant Glove Level 4 (pair)', 'Safety', '5.40', '12.20', 'PR'],
            'DEMO-SF-3004' => ['Hard Hat Type 1 Class E', 'Safety', '11.60', '24.95', 'EA'],
            'DEMO-PT-4001' => ['18V Cordless Drill (bare tool)', 'Power Tools', '82.00', '169.00', 'EA'],
            'DEMO-PT-4002' => ['18V Battery 5.0Ah', 'Power Tools', '46.50', '98.00', 'EA'],
            'DEMO-PT-4003' => ['Angle Grinder 125mm', 'Power Tools', '58.00', '119.00', 'EA'],
        ];

        // Each list discounts off the printed price. Three lists means a reviewer can see the
        // customer-specific pricing screens do something, which one list cannot show.
        $multipliers = ['Demo Wholesale' => 1.00, 'Demo Distributor' => 0.88, 'Demo Contractor' => 0.94];

        $products = [];

        foreach ($catalogue as $sku => [$name, $categoryName, $cost, $price, $unit]) {
            $product = (new ProductCore())
                ->setSku($sku)
                ->setName($name)
                ->setCategory($categories[$categoryName])
                ->setUnit($unit)
                ->setCostPrice($cost)
                ->setDefaultPrice($price)
                ->setOriginalPrice($price)
                ->activate()
                ->setVisible(true)
                ->setSalesTaxCode('Taxable')
                ->setShortDescription($name)
                ->setSyncSource(DemoSeed::PRODUCT_SYNC_SOURCE);

            // One featured product per category, so the storefront's featured rail is not empty.
            $product->setFeatured(str_ends_with($sku, '001'));

            $this->em->persist($product);
            $products[$sku] = $product;

            foreach ($priceLists as $listName => $list) {
                $this->em->persist(
                    (new ProductPricing())
                        ->setProduct($product)
                        ->setPriceList($list)
                        ->setCurrency('CAD')
                        ->setPrice(number_format((float) $price * $multipliers[$listName], 2, '.', '')),
                );
            }
        }

        $this->em->flush();

        return [$products, $categories];
    }

    /**
     * Opening stock, per product per warehouse.
     *
     * Written straight onto `product_inventory.quantity`, which is not a shortcut around the movement
     * layer — it is what the layer is for a `simple` product. Simple mode means "the quantity is a
     * number an admin maintains"; the inventory grid writes this same column, and there are no detail
     * rows to disagree with because there is no depth layer in play. A dimensional product's stock
     * cannot be set this way and is not set here; see `app:seed-warehouse-data`.
     *
     * Two products are opted into backorders so the backorder screens and the Backordered bucket have
     * something to show, and so one order below can legitimately go short.
     *
     * @param array<string, ProductCore> $products
     * @param array<string, Warehouse>   $warehouses
     */
    private function seedStock(array $products, array $warehouses): int
    {
        $rows = 0;
        $index = 0;

        foreach ($products as $sku => $product) {
            foreach ($warehouses as $regionName => $warehouse) {
                // Deliberately uneven, and deliberately including a zero: an inventory grid where
                // every row reads the same number tells a reviewer nothing about how it sorts,
                // filters or highlights.
                $quantity = match (true) {
                    $sku === 'DEMO-PT-4002' && $regionName === 'Demo East' => 0,
                    str_starts_with($sku, 'DEMO-PT-') => 12 + ($index % 5) * 3,
                    str_starts_with($sku, 'DEMO-SF-') => 140 + ($index % 7) * 25,
                    default => 60 + ($index % 4) * 40,
                };

                $inventory = (new ProductInventory())
                    ->setProduct($product)
                    ->setWarehouse($warehouse)
                    ->setQuantity($quantity);

                // The two SKUs a customer is allowed to order beyond stock. The cap on the second is
                // what makes the "remaining backorder capacity" reading on the screen non-infinite.
                if ($sku === 'DEMO-PT-4002') {
                    $inventory->setAllowBackorder(true)->setAutoReleaseOnRestock(true);
                }
                if ($sku === 'DEMO-AD-2002') {
                    $inventory->setAllowBackorder(true)->setMaxBackorderQuantity(50);
                }

                $this->em->persist($inventory);
                ++$rows;
                ++$index;
            }
        }

        $this->em->flush();

        return $rows;
    }

    /**
     * Companies, their address books, their sign-ins and their per-region price list.
     *
     * `CompanyFulfillmentRegionService::backfillForNewCompany()` is what creates the one row per
     * region every company must have — inactive — and this then activates one of them and gives it a
     * price list. That pairing is the app's rule (a region cannot be active for a company without a
     * price list; see `validateActivation()`), and honouring it here is what makes the customer-facing
     * catalogue price something rather than nothing.
     *
     * @param array<string, PriceList> $priceLists
     *
     * @return array{0: array<string, Company>, 1: int, 2: int}
     */
    private function seedCompanies(array $priceLists): array
    {
        $definitions = [
            ['DEMO-0001', 'Harbourline Building Supply', 'Active', 'Business', 'Demo West', 'Demo Distributor', 'Priya', 'Raman'],
            ['DEMO-0002', 'Copperfield Mechanical Ltd.', 'Active', 'Business', 'Demo West', 'Demo Contractor', 'Dan', 'Okafor'],
            ['DEMO-0003', 'Northgate Facilities Group', 'Active', 'Business', 'Demo East', 'Demo Wholesale', 'Sofia', 'Bergeron'],
            ['DEMO-0004', 'Ridgeway Site Services', 'Pending', 'Business', 'Demo East', 'Demo Contractor', 'Tom', 'Whitfield'],
            ['DEMO-0005', 'Cormorant Marine Repair', 'Inactive', 'Business', 'Demo West', 'Demo Wholesale', 'Ana', 'Silva'],
        ];

        $cities = [
            'Demo West' => ['Burnaby', 'BC', 'V5C 4T2'],
            'Demo East' => ['Mississauga', 'ON', 'L4W 5N5'],
        ];

        $companies = [];
        $users = 0;
        $addresses = 0;

        foreach ($definitions as [$code, $name, $status, $accountType, $regionName, $priceListName, $firstName, $lastName]) {
            $slug = strtolower(str_replace([' ', '.', ','], ['-', '', ''], $name));
            $domain = explode('-', $slug)[0] . '.example';

            $company = (new Company())
                ->setName($name)
                ->setCode($code)
                ->setAccountType($accountType)
                ->setTradeName($name)
                ->setPrimaryEmail('accounts@' . $domain)
                ->setPhoneNumber('604-555-0' . substr($code, -3))
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setSalesRepNote('Jordan Mackay')
                ->setBusinessLicense('BL-' . substr($code, -4) . '-2026');
            $company->setStatus($status, DocumentActor::automation(DemoSeed::ACTOR_LABEL));

            $this->em->persist($company);

            [$city, $province, $postal] = $cities[$regionName];

            // Two addresses, one of them the default for both roles — the shape the checkout and the
            // document snapshots expect, and enough for the address-book screen to show a list rather
            // than a single row.
            $billing = (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Head Office')
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setCompanyName($name)
                ->setEmailPrimary('ap@' . $domain)
                ->setPhone('604-555-0' . substr($code, -3))
                ->setAddressLine1(sprintf('%d Industrial Way', 1200 + (int) substr($code, -3)))
                ->setCity($city)
                ->setProvince($province)
                ->setCountry('CA')
                ->setPostalCode($postal)
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true);

            $shipping = (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Yard')
                ->setCompanyName($name . ' (Yard)')
                ->setAddressLine1(sprintf('%d Dock Road', 40 + (int) substr($code, -3)))
                ->setCity($city)
                ->setProvince($province)
                ->setCountry('CA')
                ->setPostalCode($postal)
                ->setDeliveryInstructions('Deliveries 07:00-15:00. Call on arrival.');

            $company->addAddress($billing);
            $company->addAddress($shipping);
            $this->em->persist($billing);
            $this->em->persist($shipping);
            $addresses += 2;

            $user = (new CustomerUser())
                ->setCompany($company)
                ->setEmail(strtolower($firstName) . '@' . $domain)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setPhoneNumber('604-555-0' . substr($code, -3));
            $user->setStatus($status === 'Active' ? 'Active' : 'Inactive', DocumentActor::automation(DemoSeed::ACTOR_LABEL));
            $user->setPassword($this->hasher->hashPassword($user, self::CUSTOMER_PASSWORD));
            $this->em->persist($user);
            ++$users;

            // The first two companies get a second sign-in, so the user list has a company with more
            // than one and the "who else can order on this account" question has an answer on screen.
            if (\in_array($code, ['DEMO-0001', 'DEMO-0002'], true)) {
                $second = (new CustomerUser())
                    ->setCompany($company)
                    ->setEmail('purchasing@' . $domain)
                    ->setFirstName('Purchasing')
                    ->setLastName('Desk');
                $second->setPassword($this->hasher->hashPassword($second, self::CUSTOMER_PASSWORD));
                $this->em->persist($second);
                ++$users;
            }

            $this->em->flush();

            $this->companyRegions->backfillForNewCompany($company);
            $this->em->flush();

            foreach ($this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]) as $row) {
                if ($row->getFulfillmentRegion()->getName() === $regionName) {
                    $row->setPriceList($priceLists[$priceListName])->setStatus('Active');
                }
            }

            $companies[$code] = $company;
        }

        $this->em->flush();

        return [$companies, $users, $addresses];
    }

    /**
     * The order book, and the invoices and payments that give it its statuses.
     *
     * Every status past `Approved` is produced by creating the invoices that imply it — see the
     * table on this class's docblock. Nothing here writes `sales_order.status`; there is no method
     * that would let it.
     *
     * @param array<string, Company>     $companies
     * @param array<string, ProductCore> $products
     *
     * @return array<string, int>
     */
    private function seedOrdersAndInvoices(array $companies, array $products, DocumentActor $actor): array
    {
        $counts = ['sales_order' => 0, 'sales_order_line' => 0, 'invoice' => 0, 'invoice_line' => 0, 'invoice_payment' => 0];

        /*
         * plan: [company code, region, days ago, PO number, [sku => quantity], outcome]
         *
         * The outcomes are the seven rows of the lifecycle table, each at least once, plus the two
         * awkward ones a reviewer needs: an order billed across two invoices, and an order that went
         * short on stock and had to backorder part of a line.
         */
        $plan = [
            ['DEMO-0001', 'Demo West', 64, 'PO-88412', ['DEMO-FS-1001' => 12, 'DEMO-FS-1002' => 20, 'DEMO-AD-2001' => 24], 'closed'],
            ['DEMO-0001', 'Demo West', 41, 'PO-88530', ['DEMO-SF-3001' => 40, 'DEMO-SF-3002' => 60], 'invoiced-part-paid'],
            ['DEMO-0001', 'Demo West', 12, 'PO-88711', ['DEMO-PT-4001' => 3, 'DEMO-PT-4003' => 2], 'partially-invoiced'],
            ['DEMO-0002', 'Demo West', 57, 'REQ-2213', ['DEMO-AD-2001' => 48, 'DEMO-AD-2003' => 36], 'closed'],
            ['DEMO-0002', 'Demo West', 29, 'REQ-2287', ['DEMO-FS-1003' => 10, 'DEMO-FS-1004' => 15], 'invoiced'],
            ['DEMO-0002', 'Demo West', 9, 'REQ-2340', ['DEMO-SF-3003' => 24, 'DEMO-SF-3004' => 12], 'approved'],
            ['DEMO-0003', 'Demo East', 48, 'NG-77120', ['DEMO-SF-3001' => 80, 'DEMO-SF-3004' => 30], 'invoiced'],
            ['DEMO-0003', 'Demo East', 21, 'NG-77245', ['DEMO-FS-1001' => 8, 'DEMO-AD-2002' => 12], 'partially-invoiced'],
            // `-20` is not a quantity: it is "twenty more than this warehouse can currently supply",
            // resolved against live availability when the line is built. Hard-coding a number here
            // would make the partial-backorder case depend on nothing else having taken the stock
            // first, which is exactly the sort of fixture that works once and then quietly stops
            // demonstrating what it was written to demonstrate.
            ['DEMO-0003', 'Demo East', 6, 'NG-77390', ['DEMO-PT-4002' => 14, 'DEMO-AD-2002' => -20], 'backordered'],
            ['DEMO-0004', 'Demo East', 17, '', ['DEMO-FS-1002' => 30, 'DEMO-SF-3002' => 25], 'draft-invoice'],
            ['DEMO-0004', 'Demo East', 4, '', ['DEMO-AD-2003' => 18], 'draft'],
            ['DEMO-0005', 'Demo West', 35, 'CMR-441', ['DEMO-PT-4003' => 1, 'DEMO-SF-3002' => 10], 'void'],
            ['DEMO-0005', 'Demo West', 2, 'CMR-468', ['DEMO-AD-2001' => 6], 'draft'],
            ['DEMO-0001', 'Demo West', 76, 'PO-88104', ['DEMO-FS-1004' => 25, 'DEMO-AD-2001' => 30, 'DEMO-SF-3002' => 40], 'closed'],
            ['DEMO-0002', 'Demo West', 23, 'REQ-2301', ['DEMO-FS-1001' => 6, 'DEMO-SF-3003' => 18], 'processing'],
            ['DEMO-0003', 'Demo East', 38, 'NG-77188', ['DEMO-AD-2001' => 60], 'completed'],
            ['DEMO-0001', 'Demo West', 15, 'PO-88690', ['DEMO-PT-4003' => 1, 'DEMO-FS-1002' => 12], 'awaiting-payment'],
            ['DEMO-0002', 'Demo West', 44, 'REQ-2255', ['DEMO-SF-3001' => 20], 'cancelled-and-reraised'],
        ];

        foreach ($plan as [$code, $region, $daysAgo, $poNumber, $lines, $outcome]) {
            $company = $companies[$code];
            $order = $this->buildOrder($company, $region, $daysAgo, $poNumber, $lines, $products);

            $this->em->persist($order);
            $this->em->flush();

            $counts['sales_order']++;
            $counts['sales_order_line'] += $order->getLines()->count();

            if ($outcome === 'draft') {
                continue;
            }

            if ($outcome === 'backordered') {
                // The app's own split, not a hand-written number. It reads availability, the SKU's
                // backorder opt-in and its remaining cap, and writes each line's backordered
                // quantity — which is what SalesOrderLine::setBackorderedQuantity() then turns into
                // the line's fulfilment status, and what BackorderQueueSynchronizer turns into a
                // queue entry on the next flush.
                $this->backorders->applyToOrderLines($order, 'Approved', $this->em);
            }

            $order->setStatus('Approved', $actor, 'Order approved.');
            $this->em->flush();

            if ($outcome === 'approved' || $outcome === 'backordered') {
                continue;
            }

            if ($outcome === 'void') {
                $order->setStatus(
                    'Void',
                    $actor,
                    sprintf('Order voided (was %s): Customer cancelled before shipment.', $order->getStatus()),
                );
                $this->em->flush();

                continue;
            }

            $counts = $this->invoiceOrder($order, $outcome, $actor, $daysAgo, $counts);
        }

        return $counts;
    }

    /**
     * Raises whichever invoices the outcome calls for, and lets the deriver name the order's status.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function invoiceOrder(SalesOrder $order, string $outcome, DocumentActor $actor, int $daysAgo, array $counts): array
    {
        if ($outcome === 'partially-invoiced') {
            // Half of the first line and none of the rest: the remainder stays on the order, which is
            // what leaves it at Partially Invoiced and gives the "convert to invoice" screen
            // something still outstanding to offer.
            $first = $order->getLines()->first();
            $half = max(1.0, floor((float) $first->getQuantity() / 2));

            $invoice = $this->invoicing->invoiceFromOrder(
                $order,
                [['line' => $first, 'quantity' => number_format($half, 2, '.', ''), 'price' => null]],
                $this->em,
                $actor,
                InvoiceIssueIntent::Issue,
                [],
            );
            $this->em->flush();

            $counts['invoice']++;
            $counts['invoice_line'] += $invoice->getLines()->count();

            return $counts;
        }

        if ($outcome === 'cancelled-and-reraised') {
            // An invoice is never deleted and never leaves the numbering sequence, so "we billed the
            // wrong thing" ends as a Cancelled invoice sitting beside its replacement. Cancelling
            // also returns its quantity to the order's sales hold, which is why the order can be
            // invoiced in full a second time: invoiceInFull() refuses only when a COUNTING invoice
            // exists, and a cancelled one counts for nothing.
            $spoiled = $this->invoicing->invoiceInFull($order, $this->em, $actor, InvoiceIssueIntent::Issue);
            $spoiled->setDueDate($this->date($daysAgo - 30));
            $this->em->flush();

            $spoiled->setStatus(
                'Cancelled',
                $actor,
                'Invoice cancelled: Billed to the wrong site address; re-raised as a replacement.',
            );
            $this->em->flush();

            $counts['invoice']++;
            $counts['invoice_line'] += $spoiled->getLines()->count();
        }

        $intent = match ($outcome) {
            'draft-invoice' => InvoiceIssueIntent::KeepDraft,
            // On Hold is a different claim from Pending, not a weaker one: the invoice is issued but
            // holds no stock, and it is the only state the stale-unpaid sweep looks at.
            'awaiting-payment' => InvoiceIssueIntent::AwaitingPayment,
            default => InvoiceIssueIntent::Issue,
        };

        $invoice = $this->invoicing->invoiceInFull($order, $this->em, $actor, $intent);
        $invoice->setDueDate($this->date($daysAgo - 30));
        $this->em->flush();

        $counts['invoice']++;
        $counts['invoice_line'] += $invoice->getLines()->count();

        // A draft invoice counts for nothing — the order stays Approved and holds its stock — so
        // there is nothing to pay against it. That is the point of seeding one.
        if ($outcome === 'draft-invoice' || $outcome === 'awaiting-payment') {
            return $counts;
        }

        if ($outcome === 'processing' || $outcome === 'completed') {
            // Fulfilment is the invoice's lifecycle, not the order's (#539). Walking it one named
            // action at a time is the only way to reach these two states — each refuses any from-state
            // but its own predecessor, which is exactly why they are actions and not a setter.
            $invoice->startProcessing($actor);

            if ($outcome === 'completed') {
                $invoice->setStatus('Completed', $actor);
                $this->recordPayment($invoice, $actor, $invoice->getTotal(), 'EFT', $daysAgo - 5, 'Paid on delivery.');
                $counts['invoice_payment']++;
            }

            $this->em->flush();

            return $counts;
        }

        if ($outcome === 'closed') {
            // Paid in full, which is the only thing that moves an order to Closed. Two instalments
            // rather than one, so the payment history on the invoice screen has more than one row.
            $total = (float) $invoice->getTotal();
            $first = round($total * 0.4, 2);

            $this->recordPayment($invoice, $actor, number_format($first, 2, '.', ''), 'EFT', $daysAgo - 10, 'Deposit on account.');
            $this->recordPayment($invoice, $actor, number_format($total - $first, 2, '.', ''), 'Cheque', $daysAgo - 3, 'Balance, cheque 40118.');
            $counts['invoice_payment'] += 2;
            $this->em->flush();

            return $counts;
        }

        if ($outcome === 'invoiced-part-paid') {
            // Short of the total, so the invoice is Partially Paid and its order stays Invoiced
            // rather than closing. This is the pair a reviewer needs in order to see that the two
            // statuses are answering different questions.
            $part = round((float) $invoice->getTotal() * 0.35, 2);
            $this->recordPayment($invoice, $actor, number_format($part, 2, '.', ''), 'Credit Card', $daysAgo - 8, 'Part payment.');
            $counts['invoice_payment']++;
            $this->em->flush();
        }

        // 'invoiced' falls through with no payment at all: fully invoiced, nothing received.
        return $counts;
    }

    private function recordPayment(
        Invoice $invoice,
        DocumentActor $actor,
        string $amount,
        string $method,
        int $daysAgo,
        string $comment,
    ): void {
        // recordPayment() rather than a collection add: there is deliberately no addPayment() on
        // Invoice, because a payment attached without an actor loses the one fact the timeline
        // exists to record. The derived payment status follows at flush, via
        // InvoicePaymentStatusSubscriber.
        $payment = (new InvoicePayment())
            ->setAmount($amount)
            ->setMethod($method)
            ->setComment($comment)
            ->setReceivedAt(new \DateTimeImmutable($this->date(max(0, $daysAgo)) . ' 10:15:00'));

        $invoice->recordPayment($actor, $payment);
        $this->em->persist($payment);
    }

    /**
     * @param array<string, int>         $lines sku => quantity
     * @param array<string, ProductCore> $products
     */
    private function buildOrder(
        Company $company,
        string $region,
        int $daysAgo,
        string $poNumber,
        array $lines,
        array $products,
    ): SalesOrder {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($this->orderNumbers->next($this->em))
            ->setFulfillmentRegion($region)
            ->setDocumentDate($this->date($daysAgo))
            ->setSource('admin')
            ->setUserName($company->getFirstName() . ' ' . $company->getLastName())
            ->setPaymentMethod('Net Terms')
            ->setPaymentTerm('Net 30');

        if ($poNumber !== '') {
            $order->setPoNumber($poNumber);
        }

        // Frozen copies of the address book rows, not references to them — see
        // AbstractDocumentAddress for why a document owns its addresses. Doing it any other way
        // would let a customer's later address edit rewrite the record of where goods went.
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());
        $order->snapshotCompany($company);

        $subtotal = 0.0;
        $sortOrder = 0;

        foreach ($lines as $sku => $quantity) {
            $product = $products[$sku];

            if ($quantity < 0) {
                $quantity = $this->overAvailableBy($product, $region, -$quantity);
            }

            $price = (float) $product->getDefaultPrice();
            $lineSubtotal = $price * $quantity;
            $subtotal += $lineSubtotal;

            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($sku)
                    ->setUnit($product->getUnit())
                    ->setQuantity(number_format((float) $quantity, 2, '.', ''))
                    ->setCost((string) $product->getCostPrice())
                    ->setPrice(number_format($price, 2, '.', ''))
                    ->setSubtotal(number_format($lineSubtotal, 2, '.', ''))
                    ->setTaxCode('Taxable')
                    ->setSortOrder($sortOrder++),
            );
        }

        // A flat 5% stands in for the tax engine. Nothing here calls the calculators: an order's
        // tax_lines snapshot is a record of what was charged, and inventing one would be claiming a
        // calculation that never ran.
        $tax = round($subtotal * 0.05, 2);

        return $order
            ->setSubtotal(number_format($subtotal, 2, '.', ''))
            ->setTax(number_format($tax, 2, '.', ''))
            ->setTotal(number_format($subtotal + $tax, 2, '.', ''));
    }

    /**
     * A quantity that this warehouse can partly supply and no more — availability plus $over.
     *
     * Read through ProductInventory::getAvailableQuantity() rather than off `quantity`, because
     * availability is the number the split is measured against and it is stock minus every hold. A
     * line built against the raw stock figure would come out fully fulfilled the moment another
     * order in this same run had already taken some, and the partially-backordered case this exists
     * to produce would silently vanish.
     */
    private function overAvailableBy(ProductCore $product, string $regionName, int $over): int
    {
        $warehouse = $this->warehouses->warehouseForRegionName($regionName);
        $inventory = $warehouse instanceof Warehouse
            ? $this->backorders->inventoryFor($product, $warehouse, $this->em)
            : null;

        return max(1, ($inventory?->getAvailableQuantity() ?? 0)) + $over;
    }

    /**
     * Quotes, one in each EstimateStatus.
     *
     * Estimate is the one sales document here that does have a public `setStatus()`, because its
     * statuses are not derived from anything: a quote is submitted, priced, accepted or rejected by
     * people, and there is no second document whose existence implies the answer. Using it is
     * therefore going through the app, not around it — the same test every other line in this file
     * is held to.
     *
     * Since the status seam it is the seam's door, so it takes an actor and returns the resulting
     * status rather than `$this` — which is why it is called after the chain rather than inside it.
     * The seeder signs its rows as automation, the same actor every other named action here uses.
     *
     * @param array<string, Company>     $companies
     * @param array<string, ProductCore> $products
     */
    private function seedEstimates(array $companies, array $products): int
    {
        $plan = [
            ['DEMO-0001', 'Demo West', 31, 'Accepted', ['DEMO-FS-1001' => 20, 'DEMO-FS-1003' => 15], true],
            ['DEMO-0002', 'Demo West', 18, 'Priced', ['DEMO-AD-2002' => 30, 'DEMO-AD-2003' => 24], true],
            ['DEMO-0003', 'Demo East', 11, 'Submitted', ['DEMO-PT-4001' => 4, 'DEMO-PT-4002' => 8], false],
            ['DEMO-0004', 'Demo East', 7, 'Rejected', ['DEMO-SF-3004' => 45], true],
            ['DEMO-0005', 'Demo West', 3, 'Draft', ['DEMO-SF-3001' => 12], false],
        ];

        $actor = DocumentActor::automation(DemoSeed::ACTOR_LABEL);
        $seeded = 0;

        foreach ($plan as [$code, $region, $daysAgo, $status, $lines, $priced]) {
            $company = $companies[$code];

            $estimate = (new Estimate())
                ->setCompany($company)
                ->setDocumentNumber($this->estimateNumbers->next($this->em))
                ->setFulfillmentRegion($region)
                ->setDocumentDate($this->date($daysAgo))
                ->setSource('customer')
                ->setUserName($company->getFirstName() . ' ' . $company->getLastName());

            // A new quote is born Draft, so the Draft row here is a silent no-op and each of the
            // other four is a real Draft -> X move the vocabulary allows.
            $estimate->setStatus($status, $actor);
            $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
            $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
            $estimate->snapshotCompany($company);

            $subtotal = 0.0;
            $sortOrder = 0;

            foreach ($lines as $sku => $quantity) {
                $product = $products[$sku];

                // An unpriced line is what a Submitted quote actually looks like — the customer has
                // asked, and nobody has quoted yet. Estimate::isFullyPriced() is the check the
                // screens run, so seeding one of each is what makes that distinction visible.
                $price = $priced ? (float) $product->getDefaultPrice() : null;
                $lineSubtotal = $price === null ? null : $price * $quantity;
                $subtotal += $lineSubtotal ?? 0.0;

                $estimate->addLine(
                    (new EstimateLine())
                        ->setProduct($product)
                        ->setName($product->getName())
                        ->setSku($sku)
                        ->setUnit($product->getUnit())
                        ->setQuantity(number_format((float) $quantity, 2, '.', ''))
                        ->setCost((string) $product->getCostPrice())
                        ->setPrice($price === null ? null : number_format($price, 2, '.', ''))
                        ->setSubtotal($lineSubtotal === null ? null : number_format($lineSubtotal, 2, '.', ''))
                        ->setTaxCode('Taxable')
                        ->setSortOrder($sortOrder++),
                );
            }

            $estimate
                ->setSubtotal(number_format($subtotal, 2, '.', ''))
                ->setTax(number_format(round($subtotal * 0.05, 2), 2, '.', ''))
                ->setTotal(number_format($subtotal + round($subtotal * 0.05, 2), 2, '.', ''));

            $this->em->persist($estimate);
            ++$seeded;
        }

        $this->em->flush();

        return $seeded;
    }

    /** The depth-layer invariant, asserted on this command's own output. See DemoInventoryInvariant. */
    private function assertInvariant(SymfonyStyle $io): int
    {
        $failures = $this->invariant->failures();
        $pairs = $this->invariant->pairsChecked();

        if ($failures !== []) {
            $io->error([
                'The inventory invariant does not hold after seeding.',
                DemoInventoryInvariant::describe($failures),
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Sell side seeded. Inventory invariant holds across %d product/warehouse pair(s) with detail rows.',
            $pairs,
        ));

        if ($pairs === 0) {
            $io->note(
                'No detail rows exist yet, which is expected: every product this command seeds is in'
                . ' simple inventory mode. Run app:seed-warehouse-data for the depth layer.',
            );
        }

        return Command::SUCCESS;
    }

    /** A 'Y-m-d' date $daysAgo before today, which is the shape every document date column takes. */
    private function date(int $daysAgo): string
    {
        return (new \DateTimeImmutable(sprintf('-%d days', max(0, $daysAgo))))->format('Y-m-d');
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        $lines = [];
        foreach ($counts as $table => $rows) {
            $lines[] = sprintf('  %-26s %6d', $table, $rows);
        }

        return implode("\n", $lines);
    }
}
