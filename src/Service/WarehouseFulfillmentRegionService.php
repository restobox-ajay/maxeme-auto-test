<?php

namespace App\Service;

use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\WarehouseFulfillmentRegion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that turns a fulfillment region into the warehouse whose stock answers for it,
 * and back (#546).
 *
 * Before #546 there was nothing to resolve: one row was both the sales territory and the
 * building, so "which stock does this region draw on" was a property read. Splitting them makes
 * it a lookup, and this is the only place that lookup lives — every availability check, every
 * deduction and every reservation goes through here, so there is one definition of the mapping
 * rather than one per caller.
 *
 * ## Everything here takes the single result
 *
 * `uniq_wfr_region` forces one warehouse per region, so `warehouseForRegion()` returning a
 * single `?Warehouse` is a statement about the constraint, not an assumption. Enabling
 * many-to-many means changing these signatures to return lists and deciding an allocation
 * policy; that is logic, and it is deliberately not written yet. Nothing else in the app has to
 * change shape for it, which is the point of the join table.
 *
 * ## Pairing on create
 *
 * A region with no warehouse can be bought from but has nowhere to draw stock from, and a
 * warehouse with no region holds stock nobody can reach. Neither is a state worth being able to
 * reach through the admin, so creating either side creates its counterpart and the link row.
 * Names are copied across at creation for the same reason the migration copies them: the two
 * halves start life as one thing, and the admin can diverge them afterwards if the building and
 * the territory really do have different names.
 *
 * Creating the counterpart means creating the one that is MISSING, which is not the same as always
 * creating a new one: both directions first look for a row already wearing that name and link to
 * that instead. Region-to-warehouse has always been reached through
 * {@see warehouseForRegionNameOrCreate()}, which does exactly that.
 * {@see createRegionForWarehouse()} did not, and a warehouse named after a region that already
 * existed minted a SECOND region of that name. It does now; the note on that method says what the
 * second row cost.
 *
 * **The two directions stopped being symmetrical at queue item 61.** Warehouse-to-region still
 * needs nothing but the warehouse: a region is a name, a status and some commercial flags, and the
 * warehouse has all of that. Region-to-warehouse needs something the region does not have and never
 * will — a province — because a warehouse without one makes every purchase order raised against it
 * compute $0.00 tax from nothing. So `createRegionForWarehouse()` is unchanged and
 * `createWarehouseForRegion()` now demands a province and a country from its caller and throws
 * without them. The consequence, spelled out there: a region CAN now exist without a warehouse,
 * because the alternative was inventing an address. The Fulfillment Regions screen says which ones
 * are in that state, and the Warehouses screen is where it is answered.
 */
final class WarehouseFulfillmentRegionService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** The warehouse serving this region, or null if the region has none. */
    public function warehouseForRegion(?FulfillmentRegion $region): ?Warehouse
    {
        if (!$region instanceof FulfillmentRegion) {
            return null;
        }

        $link = $this->entityManager->getRepository(WarehouseFulfillmentRegion::class)
            ->findOneBy(['fulfillmentRegion' => $region], ['priority' => 'ASC', 'id' => 'ASC']);

        return $link instanceof WarehouseFulfillmentRegion ? $link->getWarehouse() : null;
    }

    /**
     * The warehouse serving the region with this name, matched case-insensitively.
     *
     * Region names are how documents record where they are fulfilled from —
     * AbstractSalesDocument::$fulfillmentRegion is a string snapshot, not a foreign key — so a
     * name is what most of the inventory code has to start from.
     */
    public function warehouseForRegionName(?string $regionName): ?Warehouse
    {
        $regionName = strtolower(trim((string) $regionName));
        if ($regionName === '') {
            return null;
        }

        return $this->warehousesByLowerRegionName()[$regionName] ?? null;
    }

    /**
     * Every region name mapped to the warehouse that serves it, keyed by strtolower(trim(name)).
     *
     * Built in one pass because the inventory code resolves a region name per document LINE, and
     * doing that as a query each time is how a 200-line order becomes 200 queries.
     *
     * @return array<string, Warehouse>
     */
    public function warehousesByLowerRegionName(): array
    {
        $map = [];

        foreach ($this->links() as $link) {
            $key = strtolower(trim($link->getFulfillmentRegion()->getName()));
            if ($key === '' || isset($map[$key])) {
                continue;
            }

            $map[$key] = $link->getWarehouse();
        }

        return $map;
    }

    /**
     * Every mapping row with both sides already loaded, in priority order.
     *
     * Joined and fetch-joined rather than left to lazy proxies: the reconciler builds a map on
     * every order and invoice flush, and reading `$link->getFulfillmentRegion()->getName()` off a
     * proxy is a SELECT per row. Ordered so that when a region does gain a second warehouse, the
     * "single result" every caller takes is the highest-priority one rather than an arbitrary one.
     *
     * @return list<WarehouseFulfillmentRegion>
     */
    private function links(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('link', 'warehouse', 'region')
            ->from(WarehouseFulfillmentRegion::class, 'link')
            ->join('link.warehouse', 'warehouse')
            ->join('link.fulfillmentRegion', 'region')
            ->orderBy('link.priority', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The canonical region name for every paired warehouse, keyed by warehouse id.
     *
     * The inverse of the map above, and it exists for the same reason: once inventory is keyed by
     * warehouse, anything that has to SAY where stock is — an admin refusal, a shortfall email, an
     * order log line — needs the region name back, and asking per row is a query per row.
     *
     * Canonical rather than whatever the document typed: region matching is case-insensitive, so
     * a line saying "main" must still read back as "Main".
     *
     * @return array<int, string>
     */
    public function regionNamesByWarehouseId(): array
    {
        $map = [];

        foreach ($this->links() as $link) {
            $warehouseId = $link->getWarehouse()->getId();
            if ($warehouseId === null || isset($map[$warehouseId])) {
                continue;
            }

            $map[$warehouseId] = $link->getFulfillmentRegion()->getName();
        }

        return $map;
    }

    /**
     * Warehouse id for every paired region id.
     *
     * For the recalc paths, which sum from a table keyed by region (cart items) and write into one
     * keyed by warehouse (product inventory): both sides have to end up on the same key or every
     * row looks like a discrepancy.
     *
     * @return array<int, int>
     */
    public function warehouseIdsByRegionId(): array
    {
        $map = [];

        foreach ($this->links() as $link) {
            $regionId = $link->getFulfillmentRegion()->getId();
            $warehouseId = $link->getWarehouse()->getId();
            if ($regionId === null || $warehouseId === null || isset($map[$regionId])) {
                continue;
            }

            $map[$regionId] = $warehouseId;
        }

        return $map;
    }

    /** The region this warehouse serves, or null if it serves none. */
    public function regionForWarehouse(?Warehouse $warehouse): ?FulfillmentRegion
    {
        if (!$warehouse instanceof Warehouse) {
            return null;
        }

        $link = $this->entityManager->getRepository(WarehouseFulfillmentRegion::class)
            ->findOneBy(['warehouse' => $warehouse], ['priority' => 'ASC', 'id' => 'ASC']);

        return $link instanceof WarehouseFulfillmentRegion ? $link->getFulfillmentRegion() : null;
    }

    /**
     * The warehouse serving the region with this name, creating whichever half is missing.
     *
     * For the paths that write stock against a region NAME rather than an entity — the admin
     * product form's per-region boxes and the CSV/API importers — where a name that does not
     * resolve used to auto-create the region rather than drop the stock on the floor. It still
     * does; there is simply a second row to create now.
     *
     * It carries the province for the same reason the factory below it does (queue item 61), and
     * for the same reason it refuses rather than defaults. The admin product form no longer calls
     * this: a per-region stock box knows a region name and nothing about where that region's
     * building is, so it reads {@see warehouseForRegionName()} and says out loud that the stock had
     * nowhere to go. What is left here are the callers that author their own world and can state an
     * address — the demo seeder and the test fixtures.
     *
     * ## Both lookups are case-insensitive, and they have to agree
     *
     * The existing-region lookup was `findOneBy(['name' => $regionName])`, an exact and (on SQLite)
     * case-SENSITIVE match, while the warehouse lookup a line above it has always folded case. So
     * this method asked two different questions about one name: given `Main` on file and `main`
     * requested, the first lookup found the building and returned early — but with no building yet,
     * the second minted a SECOND region called `main` beside it. That is the duplicate
     * `uniq_fulfillment_region_name` (`Version20260918120000`) now forbids outright, which would
     * have turned this path's next flush into a `UniqueConstraintViolationException`. It adopts the
     * existing row instead, which is what {@see createRegionForWarehouse()} does on the mirror path
     * and what this method's own caller wanted in the first place: the region that name means.
     */
    public function warehouseForRegionNameOrCreate(string $regionName, string $province, string $country): Warehouse
    {
        $regionName = trim($regionName);

        $existing = $this->warehouseForRegionName($regionName);
        if ($existing instanceof Warehouse) {
            return $existing;
        }

        $region = $this->regionNamed($regionName);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName($regionName)->setStatus('Active');
            $this->entityManager->persist($region);
        }

        $warehouse = $this->createWarehouseForRegion($region, $province, $country);
        $this->entityManager->flush();

        return $warehouse;
    }

    /** Links an existing pair. Persists but does not flush. */
    public function pair(Warehouse $warehouse, FulfillmentRegion $region, int $priority = 0): WarehouseFulfillmentRegion
    {
        $link = (new WarehouseFulfillmentRegion())
            ->setWarehouse($warehouse)
            ->setFulfillmentRegion($region)
            ->setPriority($priority);

        $this->entityManager->persist($link);

        return $link;
    }

    /**
     * Creates the warehouse that serves a newly created region, and links it. Persists but does
     * not flush; returns whatever already serves the region if one does.
     *
     * ## Why this takes a province, and why it is not optional (queue item 61)
     *
     * Until item 61 this built a warehouse from the region's name and status and nothing else, and
     * it could not do better: a `FulfillmentRegion` carries no province, country or city, and the
     * owner has ruled it never will — a region is a delivery area and may legitimately span
     * several. So every warehouse born here had a null province, item 37 derived every purchase
     * order's tax province from its warehouse, and the tax came out $0.00 with nothing on the
     * document able to say the zero was invented. A building is somewhere; whoever asks for one to
     * be created is the only one who can say where, so they are made to say it.
     *
     * **Refused rather than defaulted.** There is a system-wide "where this company is" —
     * `company_state` in Settings, which `ProcurementBundle`'s `PurchaseDocumentTax::buyerProvince()`
     * already prices RFQs from — and it is exactly the wrong thing to reach for here. The callers
     * that cannot supply a province are the ones creating a warehouse from a name somebody typed on
     * some other screen: a CSV `LOCATION` column reading "Toronto DC" is not at the company's own
     * address, and stamping BC on it computes GST-only on an Ontario building, confidently and
     * silently, forever. A wrong province is worse than a missing one — the missing one is refused
     * at the gate, the wrong one is believed. So the callers that do not know STOP CREATING
     * WAREHOUSES; they do not guess.
     *
     * Both codes are validated here rather than trusted, because `Warehouse::setProvince()` stores
     * an unrecognised value as null and this method's whole promise is that the row it returns has
     * a province.
     *
     * @param string $province a province or state, as a code or a name — 'BC', 'bc' or
     *                         'British Columbia'. Stored as the code.
     * @param string $country  the country the province belongs to, as a code or a name.
     *
     * @throws \InvalidArgumentException when either does not resolve, or the province is not a
     *                                   province OF that country
     */
    public function createWarehouseForRegion(FulfillmentRegion $region, string $province, string $country): Warehouse
    {
        $existing = $this->warehouseForRegion($region);
        if ($existing instanceof Warehouse) {
            return $existing;
        }

        $resolvedCountry = RegionSeedData::resolveCountry(trim($country));
        if ($resolvedCountry === null || $resolvedCountry === '') {
            throw new \InvalidArgumentException(sprintf(
                'Cannot create the warehouse for fulfillment region "%s": "%s" is not a country this application knows. '
                    . 'Use a two-letter code such as CA or US.',
                $region->getName(),
                $country,
            ));
        }

        $resolvedProvince = RegionSeedData::resolveProvince($resolvedCountry, trim($province));
        if ($resolvedProvince === null) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot create the warehouse for fulfillment region "%s": "%s" is not a province or state of %s. '
                    . 'A warehouse is a building and a building is somewhere, and its province is what every '
                    . 'purchase order and vendor bill raised against it computes tax from.',
                $region->getName(),
                $province,
                $resolvedCountry,
            ));
        }

        $warehouse = (new Warehouse())
            ->setName($region->getName())
            ->setStatus($region->getStatus())
            ->setProvince($resolvedProvince)
            ->setCountry($resolvedCountry);

        $this->entityManager->persist($warehouse);
        $this->pair($warehouse, $region);

        return $warehouse;
    }

    /**
     * The region wearing this name, matched case-insensitively, or null when nothing wears it.
     *
     * Case-insensitively because that is how the name is matched everywhere it decides anything:
     * {@see warehousesByLowerRegionName()} keys on `strtolower(trim(...))`, so "main" and "Main"
     * are already ONE region as far as every stock lookup is concerned, and two rows spelt that
     * way are two rows of which one is unreachable rather than two regions.
     *
     * Lowest id wins, so this answers with the same row the rest of the app finds first —
     * `CustomerPricingResolver::resolveFulfillmentRegionEntity()` walks `findAll()` in id order,
     * and {@see links()} is ordered by priority then id.
     */
    public function regionNamed(?string $name): ?FulfillmentRegion
    {
        $needle = strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }

        foreach ($this->entityManager->getRepository(FulfillmentRegion::class)->findBy([], ['id' => 'ASC']) as $region) {
            if ($region instanceof FulfillmentRegion && strtolower(trim($region->getName())) === $needle) {
                return $region;
            }
        }

        return null;
    }

    /**
     * Creates the region a newly created warehouse serves, and links it. Persists but does not
     * flush; returns whatever it already serves if it serves one.
     *
     * ## It asks whether the NAME is taken, not only whether THIS warehouse has a region
     *
     * It used to ask only the second question, and the first is the one that matters. A region's
     * NAME is its identity everywhere outside this table: documents snapshot it as a string
     * (`AbstractSalesDocument::$fulfillmentRegion`), every stock lookup starts from it
     * ({@see warehousesByLowerRegionName()}), and the company checklist, the guest list and the
     * import template all label rows with it. So creating a warehouse called "Main" while a region
     * called "Main" already existed produced a SECOND region of that name, and the two halves of
     * the application then disagreed about which one "Main" meant: the customer cart binds to the
     * first by id and finds no warehouse and therefore no stock, while the admin order screen goes
     * through the name map, finds the link, and sees the full quantity. Stock split in silence,
     * against the same word on the same screen.
     *
     * **An unpaired region of that name is ADOPTED, not duplicated.** That is not a compromise, it
     * is the documented remedy: since queue item 61 a region can exist with no warehouse (the
     * seeder creates three such rows on first login, because it has no address to invent), the
     * Fulfillment Regions screen marks them, and "the Warehouses screen is where it is answered" —
     * this method is that answer. Nothing on the region is overwritten: its status, guest
     * visibility, price list and default-for-new-company are the admin's settings on the region
     * screen, and a building appearing underneath one does not restate them.
     *
     * **A region already served by another warehouse is refused.** `uniq_wfr_region` allows a
     * region exactly one warehouse, so there is no pairing to be had; creating a second row of the
     * same name instead is the defect this refusal exists to stop. It throws for the same reason
     * {@see createWarehouseForRegion()} throws on an unresolvable province — the caller is the only
     * one who can answer it, so it is made to. `ConfigController::handleWarehouseForm()` asks this
     * question at the name box before it writes anything, so an admin meets a field error and not
     * this exception.
     *
     * @throws \InvalidArgumentException when a region of that name exists and another warehouse
     *                                   already serves it
     */
    public function createRegionForWarehouse(Warehouse $warehouse): FulfillmentRegion
    {
        $existing = $this->regionForWarehouse($warehouse);
        if ($existing instanceof FulfillmentRegion) {
            return $existing;
        }

        $named = $this->regionNamed($warehouse->getName());
        if ($named instanceof FulfillmentRegion) {
            $servedBy = $this->warehouseForRegion($named);
            if ($servedBy instanceof Warehouse) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot create the fulfillment region for warehouse "%s": the region "%s" already exists and '
                        . 'warehouse "%s" already serves it. A region draws its stock from exactly one building, so '
                        . 'this one cannot serve it too, and a second region of the same name would split the stock '
                        . 'between them without saying so.',
                    $warehouse->getName(),
                    $named->getName(),
                    $servedBy->getName(),
                ));
            }

            $this->pair($warehouse, $named);

            return $named;
        }

        $region = (new FulfillmentRegion())
            ->setName($warehouse->getName())
            ->setStatus($warehouse->getStatus());

        $this->entityManager->persist($region);
        $this->pair($warehouse, $region);

        return $region;
    }

    /** Every link row for a region — what has to go when the region does. */
    public function removeLinksForRegion(FulfillmentRegion $region): void
    {
        foreach ($this->entityManager->getRepository(WarehouseFulfillmentRegion::class)->findBy(['fulfillmentRegion' => $region]) as $link) {
            $this->entityManager->remove($link);
        }
    }
}
