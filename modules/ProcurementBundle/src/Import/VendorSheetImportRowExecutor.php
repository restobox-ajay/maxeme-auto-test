<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Contract\Import\ImportRowExecutorInterface;
use App\Contract\Import\ImportRunFinalizableInterface;
use App\Entity\ImportRun;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Enum\ImportAction;
use App\Repository\ProductCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\UnmatchedVendorSku;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Repository\UnmatchedVendorSkuRepository;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;

/**
 * Per-row logic for the vendor sheet importer (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md, §2):
 *
 *  - our_sku present -> resolve + upsert VendorPrice directly; also resolves any pending
 *    UnmatchedVendorSku for that vendor SKU.
 *  - our_sku blank -> refresh the existing VendorPrice by vendor_sku, or (nothing mapped yet)
 *    upsert into UnmatchedVendorSku rather than silently dropping the row.
 *
 * Three independent on/off switches (2026-09-21), each defaulting to what a sheet has always done
 * without them — see forOptions():
 *
 *  - ADD: our_sku given but no product has that sku. Off (default), the row fails exactly as
 *    before. On, a new ProductCore is created with that sku so the row can still price it — the
 *    "new internal sku creation" path docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md's
 *    own owner-quote asked for and the original "Screens" section of that same doc never actually
 *    built.
 *  - UPDATE: a row resolves to a product that already has (or is getting) a VendorPrice. Off, the
 *    row is refused rather than silently touching a price nobody asked to change this run.
 *  - DELETE: not a per-row switch at all — see finalize(). A vendor's OTHER VendorPrice rows, the
 *    ones no row in this sheet named, are zeroed out and deactivated when on, left alone when off.
 *
 * forVendor() must be called (by VendorSheetImportRunnerFactory) before ImportRunner starts
 * calling execute() — this executor is stateful per run, one instance per queued ImportRun,
 * exactly like ProductImportService's own beginRun()/execute() pair.
 */
final class VendorSheetImportRowExecutor implements ImportRowExecutorInterface, ImportRunFinalizableInterface
{
    private ?Vendor $vendor = null;
    private bool $allowAdd = false;
    private bool $allowUpdate = true;
    private bool $allowDelete = false;

    /**
     * The objects, not their ids: ImportRunner flushes after every row, so a VendorPrice created
     * two rows ago already has one by the time finalize() runs — but a row processed THIS instant
     * may not yet, and object identity survives that regardless. Keyed by spl_object_id() so the
     * same vendor_sku seen twice in one sheet is tracked once, not twice.
     *
     * @var array<int, VendorPrice>
     */
    private array $touchedVendorPrices = [];

    public function __construct(
        private readonly VendorSkuResolver $resolver,
        private readonly UnmatchedVendorSkuRepository $unmatched,
        private readonly ProductCategoryRepository $categories,
        private readonly EntityManagerInterface $em,
        private readonly VendorPriceUpserter $upserter,
    ) {
    }

    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function forOptions(bool $allowAdd, bool $allowUpdate, bool $allowDelete): self
    {
        $this->allowAdd = $allowAdd;
        $this->allowUpdate = $allowUpdate;
        $this->allowDelete = $allowDelete;

        return $this;
    }

    public function execute(array $mappedData): ImportAction
    {
        if ($this->vendor === null) {
            throw new \LogicException('forVendor() must be called before execute().');
        }

        $ourSku = trim((string) ($mappedData['our_sku'] ?? ''));
        $vendorSku = trim((string) ($mappedData['vendor_sku'] ?? ''));
        // VendorSheetMoney::strip(), not a bare cast: see that class's own docblock — a real
        // vendor sheet's price cell reads "$1.82", which (float) silently turns into 0.0. A blank
        // cell (vendor gave no price at all) is treated as 0, matching the validator's own
        // blank-is-not-an-error rule rather than failing the row.
        $priceRaw = VendorSheetMoney::strip((string) ($mappedData['vendor_price'] ?? ''));
        $price = $priceRaw !== '' ? $priceRaw : '0';
        $quantityRaw = VendorSheetMoney::strip((string) ($mappedData['vendor_quantity'] ?? ''));
        // Kept as the raw numeric string, not (int)-cast: available_quantity is the 'quantity'
        // type (NUMERIC(14,4), #601) precisely so a vendor's fractional figure isn't truncated.
        $quantity = $quantityRaw !== '' ? $quantityRaw : null;
        $name = trim((string) ($mappedData['vendor_name'] ?? '')) ?: null;

        $resolution = $this->resolver->resolve($this->vendor, $ourSku, $vendorSku);
        $justCreated = false;

        // our_sku given, product not found: the one failure ADD is allowed to turn into a success.
        // vendor_sku-only rows never reach here as a failure — VendorSkuResolver::resolve() routes
        // those to isUnmatched instead, and there is no our_sku to create the product under anyway.
        if ($resolution->failureReason !== null) {
            if ($this->allowAdd && $ourSku !== '') {
                $resolution = VendorSkuResolution::success($this->createProduct($ourSku, $name), null);
                $justCreated = true;
            } else {
                throw new \RuntimeException($resolution->failureReason);
            }
        }

        if ($resolution->isUnmatched) {
            return $this->upsertUnmatched((string) $resolution->unmatchedVendorSku, $name, $price, $quantity);
        }

        // Gated on whether the PRODUCT existed before this row, not whether it already had a
        // VendorPrice — a product ADD just created here has no price yet either, and pricing it
        // for the first time is part of adding it, not "updating" a price nobody asked to touch.
        if (!$this->allowUpdate && !$justCreated) {
            throw new \RuntimeException(sprintf(
                'Update is switched off for this import, and %s already exists.',
                $ourSku !== '' ? 'our_sku "' . $ourSku . '"' : 'vendor_sku "' . $vendorSku . '"',
            ));
        }

        $category = $this->resolveCategory($mappedData);

        return $this->upsertVendorPrice($resolution, $ourSku, $vendorSku, $price, $quantity, $name, $category);
    }

    /**
     * ADD only ever creates from our_sku — the one column that names what the new product's OWN
     * sku should be. `vendor_name` becomes the product's name when the sheet gave one; a bare sku
     * is still a real, findable product rather than none at all when it did not.
     */
    private function createProduct(string $ourSku, ?string $name): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($ourSku)
            ->setName($name ?? $ourSku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->activate();
        $this->em->persist($product);

        return $product;
    }

    /**
     * Lookup-only, never auto-created: an admin maps Category 1/2 only once the category tree
     * they name already exists (App\Entity\ProductCategory). A Category 2 that doesn't resolve
     * under the matched Category 1 falls back to Category 1 itself, since that level DID match —
     * better than discarding a confirmed-correct parent over an unresolved child.
     */
    private function resolveCategory(array $mappedData): ?ProductCategory
    {
        $name1 = trim((string) ($mappedData['vendor_category1'] ?? ''));
        if ($name1 === '') {
            return null;
        }

        $top = $this->categories->findOneByNameAndParent($name1, null);
        if ($top === null) {
            return null;
        }

        $name2 = trim((string) ($mappedData['vendor_category2'] ?? ''));
        if ($name2 === '') {
            return $top;
        }

        return $this->categories->findOneByNameAndParent($name2, $top) ?? $top;
    }

    private function upsertUnmatched(string $vendorSku, ?string $name, string $price, ?string $quantity): ImportAction
    {
        $existing = $this->unmatched->findOneForVendorSku($this->vendor, $vendorSku);
        if ($existing instanceof UnmatchedVendorSku) {
            $existing->touch($name, $price, $quantity);

            return ImportAction::Update;
        }

        $row = (new UnmatchedVendorSku($this->vendor, $vendorSku))->touch($name, $price, $quantity);
        $this->em->persist($row);

        return ImportAction::Append;
    }

    private function upsertVendorPrice(
        VendorSkuResolution $resolution,
        string $ourSku,
        string $vendorSku,
        string $price,
        ?string $quantity,
        ?string $name,
        ?ProductCategory $category,
    ): ImportAction {
        $product = $resolution->product;
        $isNew = $resolution->existingPrice === null;

        if ($category !== null) {
            $product->setCategory($category);
        }

        // The resolver already found $resolution->existingPrice by whichever key matched (our_sku
        // or vendor_sku) — only reached for forPair()'s own lookup-then-create when it did not, the
        // same class VendorPriceController::save() uses for a brand new manual row.
        $vendorPrice = $resolution->existingPrice ?? $this->upserter->forPair($this->vendor, $product);

        if ($vendorSku !== '') {
            $vendorPrice->setVendorSku($vendorSku);
        }
        $vendorPrice->setUnitCost(number_format((float) $price, 4, '.', ''));
        if ($quantity !== null) {
            $vendorPrice->setAvailableQuantity($quantity);
        }
        if ($name !== null) {
            $vendorPrice->setVendorItemName($name);
        }
        // Revives a row a PRIOR run's DELETE pass zeroed out and deactivated (see finalize()) — the
        // vendor is supplying it again, named right here in this sheet, so it is not "missing" any
        // more by the exact same test finalize() uses to decide that in the first place.
        $vendorPrice->setIsActive(true);

        $this->touchedVendorPrices[spl_object_id($vendorPrice)] = $vendorPrice;

        // Creation only. our_sku present is what makes this branch reachable at all, and a vendor
        // SKU accompanying it is the one that a prior sheet may have already flagged unmatched.
        if ($ourSku !== '' && $vendorSku !== '') {
            $pending = $this->unmatched->findOneForVendorSku($this->vendor, $vendorSku);
            if ($pending instanceof UnmatchedVendorSku && $pending->isPending()) {
                $pending->resolveTo($product);
            }
        }

        return $isNew ? ImportAction::Append : ImportAction::Update;
    }

    /**
     * DELETE, run once after every row — never per row, because "no row in this sheet named it"
     * is a statement about the WHOLE file, not any single line. Soft, not a real delete: the price
     * history stays (a debit memo, a past PO line, may already reference this row), only
     * `available_quantity` and `is_active` say the vendor no longer lists it. Reversible the plain
     * way — see the `setIsActive(true)` a later sheet's own upsertVendorPrice() does the moment
     * this vendor_sku is named again.
     */
    public function finalize(ImportRun $run): void
    {
        if (!$this->allowDelete || $this->vendor === null) {
            return;
        }

        $touchedIds = [];
        foreach ($this->touchedVendorPrices as $vendorPrice) {
            $id = $vendorPrice->getId();
            if ($id !== null) {
                $touchedIds[$id] = true;
            }
        }

        /** @var list<VendorPrice> $allForVendor */
        $allForVendor = $this->em->getRepository(VendorPrice::class)->findBy(['vendor' => $this->vendor]);
        $changed = false;

        foreach ($allForVendor as $vendorPrice) {
            $id = $vendorPrice->getId();
            if ($id !== null && isset($touchedIds[$id])) {
                continue;
            }

            if ($vendorPrice->isActive() || (float) $vendorPrice->getAvailableQuantity() !== 0.0) {
                $vendorPrice->setAvailableQuantity('0')->setIsActive(false);
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }
    }
}
