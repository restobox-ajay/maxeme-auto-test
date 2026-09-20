<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Entity\DenominatedLine;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\CompanyListScope;
use App\Service\DocumentActor;
use App\Service\DocumentActorResolver;
use App\Service\Product\ProductPicker;
use App\Service\QuantityScale;
use App\Service\TextInput;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\AbstractPurchaseDocument;
use ProcurementBundle\Entity\AbstractPurchaseDocumentAddress;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use App\Http\RequestedParent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the bundle's admin screens (#555).
 *
 * `/admin` is already gated by `access_control` and AdminHostSubscriber, so nothing here re-checks
 * the role. What it does add is the bundle's own Active/Inactive kill-switch: enforcement in this
 * codebase is per-seam rather than central, and a route stays reachable by URL after the nav link
 * for it disappears — so each screen has to refuse for itself.
 */
abstract class AbstractProcurementController extends AbstractController
{
    public const SOURCE = 'ProcurementBundle';

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly BundleStatusRepository $bundleStatusRepo,
        protected readonly DocumentActorResolver $actors,
    ) {
    }

    /**
     * The store-wide quantity scale — see {@see applyLineQuantity()}.
     *
     * Through `AbstractController`'s own service locator rather than the constructor above, because
     * every subclass forwards that constructor's three arguments by hand and a fourth would mean
     * editing all of them. Same mechanism and same reasoning as the sell-side twin,
     * `App\Controller\Admin\AbstractAdminController`.
     *
     * @return array<string, string>
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            QuantityScale::class => QuantityScale::class,
        ]);
    }

    protected function quantityScale(): QuantityScale
    {
        return $this->container->get(QuantityScale::class);
    }

    /** 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden. */
    /** The bundle whose movement service receiving writes through. */
    private const DEPTH_SOURCE = 'InventoryDepthBundle';

    protected function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive(self::SOURCE)) {
            throw new NotFoundHttpException('Procurement is not active.');
        }

        // Receiving books stock through InventoryDepthBundle's movement service, so these screens
        // depend on it exactly as WarehouseOpsBundle's do — and that bundle has always said so.
        // This one did not, which is how /admin/bundles/procurement/receiving/new kept returning
        // 200 with Inventory Depth switched off (#566).
        //
        // Defence in depth, not the fix: StockMovementService now refuses on its own, because a
        // controller guard only closes the door it is on.
        if (!$this->bundleStatusRepo->isActive(self::DEPTH_SOURCE)) {
            throw new NotFoundHttpException('Procurement needs Inventory Depth, which is not active.');
        }
    }

    /**
     * Who is doing this, for the named actions on the documents.
     *
     * Resolved here and passed IN to every action rather than looked up by the entity, which is the
     * whole point of DocumentActor: an action that reached for the ambient user could not be called
     * by a console command or an import, and the same method has to be callable by both.
     */
    protected function actor(): DocumentActor
    {
        return $this->actors->resolve();
    }

    /**
     * Filter state lives in URL GET params on every list screen here, so a copied URL reproduces
     * the exact result. Standing requirement in this codebase and restated in the #555 plan.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    protected function filtersFromRequest(Request $request, array $keys): array
    {
        $raw = $request->query->all('filters');

        $filters = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? '';
            // `?filters[status][]=x` makes this an array, and casting one to string raises a PHP
            // notice — which the dev error handler turns into a 500 from nothing but a crafted URL,
            // and which prod would quietly filter on the literal string "Array". A non-scalar is not
            // a filter anyone typed, so it reads as absent.
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        return $filters;
    }

    /**
     * Page/limit/sort/dir, all from GET, all whitelisted.
     *
     * @return array{page: int, limit: int, sort: string, dir: string}
     */
    protected function paging(Request $request, string $defaultSort = 'id', string $defaultDir = 'desc'): array
    {
        $dir = strtolower(trim((string) $request->query->get('dir', $defaultDir))) === 'asc' ? 'ASC' : 'DESC';

        return [
            'page' => max(1, $request->query->getInt('page', 1)),
            'limit' => max(1, min(500, $request->query->getInt('limit', 100))),
            'sort' => trim((string) $request->query->get('sort', $defaultSort)),
            'dir' => $dir,
        ];
    }

    /**
     * The idempotency key for one submission of one form: a digest of that form's CSRF token.
     *
     * The same device AbstractInventoryDepthController uses, and it matters more here — a
     * double-submitted receiving form would otherwise book the same pallet in twice. Symfony
     * re-randomises the token per render, so a resubmit applies once while a deliberate second
     * delivery (a fresh page load) gets a fresh token and applies again.
     *
     * A digest rather than the token itself, for both of AbstractInventoryDepthController's
     * reasons: the token does not fit `client_operation_id`'s VARCHAR(64), and a movement group is
     * an audit record admins read rather than somewhere to copy a CSRF token into.
     *
     * Null when the field is absent, so MovementRequest::of() falls back to a random key rather
     * than to the empty string — one stored empty key would swallow every later empty-key delivery.
     */
    protected function operationKey(Request $request): ?string
    {
        $token = trim((string) $request->request->get('_token', ''));

        return $token === '' ? null : hash('xxh128', $token);
    }

    /** @return list<Warehouse> */
    protected function activeWarehouses(): array
    {
        /** @var list<Warehouse> $rows */
        $rows = $this->em->getRepository(Warehouse::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);

        return $rows;
    }

    protected function warehouseOr404(int $id): Warehouse
    {
        $warehouse = $this->em->find(Warehouse::class, $id);
        if (!$warehouse instanceof Warehouse) {
            throw new NotFoundHttpException('No such warehouse.');
        }

        return $warehouse;
    }

    protected function vendorOr404(int $id): Vendor
    {
        $vendor = $this->em->find(Vendor::class, $id);
        if (!$vendor instanceof Vendor) {
            throw new NotFoundHttpException('No such vendor.');
        }

        return $vendor;
    }

    protected function productOr404(int $id): ProductCore
    {
        $product = $this->em->find(ProductCore::class, $id);
        if (!$product instanceof ProductCore) {
            throw new NotFoundHttpException('No such product.');
        }

        return $product;
    }

    /**
     * The parent document a CREATE screen was opened against, when the URL names one (queue item 51).
     *
     * Lifted here rather than written out at each call site, for the same reason
     * `AbstractAdminController::companyListScope()` was lifted on the sell side: every one of them
     * had the identical two-line shape — `$request->query->getInt('po', 0)` then a `find()` whose
     * null answer was dropped on the floor — and that shape produced the identical two defects
     * everywhere it appeared. One builder cannot disagree with itself.
     *
     * Three states out, never a nullable entity: see {@see RequestedParent} for why collapsing
     * "nothing was asked for" into "what was asked for does not exist" is the whole defect. The id
     * is validated with core's own `CompanyListScope::isIdShaped()` rather than a fourth copy of
     * `^\d+$`, so `?po=12abc` is junk on the buy side exactly as `?company_id=12abc` is on the sell
     * side, instead of quietly reaching purchase order 12.
     *
     * Nothing here throws. `NotFoundHttpException` would be the obscure failure again with a
     * different number on it: the screen the admin asked for exists and renders perfectly well; it
     * is the thing the LINK named that is missing, and that is a sentence, not a status code.
     *
     * @template TParent of object
     *
     * @param class-string<TParent> $class
     * @param string                $noun  what to call it on screen — 'purchase order', 'vendor'
     *
     * @return RequestedParent<TParent>
     */
    protected function requestedParent(Request $request, string $key, string $class, string $noun): RequestedParent
    {
        $requestedId = RequestedParent::requestedIdIn($request->query->all(), $key);

        if ($requestedId === null) {
            return RequestedParent::none($noun);
        }

        if (!CompanyListScope::isIdShaped($requestedId)) {
            return RequestedParent::unresolved($requestedId, $noun);
        }

        $entity = $this->em->find($class, (int) $requestedId);

        return $entity !== null
            ? RequestedParent::of($entity, $requestedId, $noun)
            : RequestedParent::unresolved($requestedId, $noun);
    }

    /**
     * The first of several requested parents that actually resolved, and otherwise the first one
     * that was requested at all.
     *
     * Three of these screens accept more than one spelling of "what is this raised against" — a
     * debit memo takes `?vendor_return=`, `?bill=` and `?vendor=` — and the precedence between them
     * is already decided by each controller. What this adds is that an UNRESOLVED one is not
     * discarded on the way past: `?bill=99999` used to be indistinguishable from no `?bill=` at all
     * once the more specific parameter had had its turn, so the screen fell through to the bare form
     * with nothing said. The first REQUESTED one is what the reader is told about, because that is
     * the link they clicked.
     *
     * @param RequestedParent<object> ...$candidates in precedence order
     *
     * @return list<RequestedParent<object>> the ones worth telling the reader about, in order
     */
    protected function unresolvedParents(RequestedParent ...$candidates): array
    {
        return array_values(array_filter($candidates, static fn (RequestedParent $p): bool => $p->isUnresolved()));
    }

    /** @return list<Vendor> */
    protected function activeVendors(): array
    {
        /** @var \ProcurementBundle\Repository\VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);

        return $repo->findActive();
    }

    protected function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * An optional related-document id, posted from a hidden input or a "— none —" select whose own
     * blank option is `value=""` — both render an EMPTY STRING for "no document", never omit the
     * field (#773). `Request::getInt()` throws on that: `FILTER_VALIDATE_INT` refuses `""` outright,
     * so the exact form these screens render — untouched, submitted as a browser would — 400s on the
     * one case that should be the default. Every caller here already treats 0 as "none" via `> 0 ?`,
     * so an absent, blank, or genuinely zero value all collapse to the same thing this returns.
     */
    protected function optionalId(Request $request, string $key): int
    {
        $raw = trim((string) $request->request->get($key, ''));

        return $raw === '' ? 0 : $request->request->getInt($key, 0);
    }

    /**
     * A posted 'Y-m-d', or null when it is absent or not a calendar date.
     *
     * Validated rather than trusted because these columns are plain strings: the format IS the
     * invariant, and 'Y-m-d' sorting and range-filtering only work in calendar order while every
     * row actually holds one.
     */
    protected function calendarDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * The product id a posted row names — the select first, then the no-JS box.
     *
     * `_product_field.html.twig` renders two controls past the inline limit: the `js-searchable-select`
     * that scripting drives, and a `<noscript>` id box for when it cannot. They carry DIFFERENT
     * names on purpose, so which one won is decided in code rather than by whichever PHP happened
     * to parse last.
     *
     * The rule itself now lives beside the markup that writes those names, in core's
     * `ProductPicker::idFromPostedRow()` (#8) — two bundles render that field, and a convention
     * about form field names restated in each of them is a convention that drifts. This method
     * keeps its name and signature because five controllers here call it.
     *
     * @param array<string, mixed> $row  a posted line, or the whole request bag for a single-product form
     */
    protected function productIdFrom(array $row, string $key = 'product_id'): int
    {
        return ProductPicker::idFromPostedRow($row, $key);
    }

    /**
     * Shared order-to/ship-from/remit-to row shape for the PO and Bill address panels — the
     * buy-side twin of `App\Controller\Admin\AbstractAdminController::addressToRow()`.
     *
     * Accepts either an address-book row or a document's own frozen snapshot — they expose the
     * same accessors, and both forms need to render whichever one they have. Falls back to the
     * vendor's own contact fields when no address-specific value is set, same rule as the sell
     * side's company fallback.
     *
     * @return array<string, string>
     */
    protected function addressToRow(VendorAddress|AbstractPurchaseDocumentAddress|null $address, Vendor $vendor): array
    {
        // 'id' is deliberately the linked BOOK entry's id, not the snapshot row's own primary key:
        // it round-trips into `{type}_address_id`, which applyPurchaseAddressFromRequest() looks up
        // as a VendorAddress id. Posting the snapshot's own id there would look up the wrong table's
        // row under a coincidentally-matching number once both tables' id sequences run long enough.
        $bookId = $address instanceof AbstractPurchaseDocumentAddress
            ? $address->getSourceAddress()?->getId()
            : $address?->getId();

        return [
            'id' => (string) ($bookId ?? ''),
            'companyName' => $address?->getCompanyName() ?? $vendor->getName(),
            'firstName' => $address?->getFirstName() ?? '',
            'lastName' => $address?->getLastName() ?? '',
            'phone' => $address?->getPhone() ?? $vendor->getPhone() ?? '',
            'addressLine1' => $address?->getAddressLine1() ?? '',
            'addressLine2' => $address?->getAddressLine2() ?? '',
            'city' => $address?->getCity() ?? '',
            'country' => $address?->getCountry() ?? 'CA',
            'province' => $address?->getProvince() ?? '',
            'postalCode' => $address?->getPostalCode() ?? '',
            'fax' => $address?->getFax() ?? '',
            'primaryEmail' => $address?->getEmailPrimary() ?? $vendor->getEmail() ?? '',
            'secondaryEmail' => $address?->getEmailSecondary() ?? '',
            'deliveryInstructions' => $address?->getDeliveryInstructions() ?? '',
        ];
    }

    /**
     * Applies whichever address fields the submitted form actually carried — the buy-side twin of
     * `AbstractAdminController::applyAddressEditsFromRequest()`, same field-presence rule: a field
     * absent from the POST leaves the stored value alone, only a field present and empty clears it.
     */
    protected function applyAddressEditsFromRequest(AbstractPurchaseDocumentAddress $address, string $prefix, Request $request): void
    {
        $setters = [
            '_company_name' => $address->setCompanyName(...),
            '_first_name' => $address->setFirstName(...),
            '_last_name' => $address->setLastName(...),
            '_phone' => $address->setPhone(...),
            '_address_1' => $address->setAddressLine1(...),
            '_address_2' => $address->setAddressLine2(...),
            '_city' => $address->setCity(...),
            '_country' => $address->setCountry(...),
            '_province' => $address->setProvince(...),
            '_postal_code' => $address->setPostalCode(...),
            '_fax' => $address->setFax(...),
            '_primary_email' => $address->setEmailPrimary(...),
            '_secondary_email' => $address->setEmailSecondary(...),
            '_delivery_instructions' => $address->setDeliveryInstructions(...),
        ];

        foreach ($setters as $suffix => $setter) {
            if (!$request->request->has($prefix . $suffix)) {
                continue;
            }

            $raw = $request->request->get($prefix . $suffix);

            $setter($suffix === '_delivery_instructions'
                ? TextInput::nullableStringMax($raw, TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH)
                : TextInput::nullableString($raw));
        }
    }

    /**
     * Build (or update) one of a purchase document's address snapshots from the posted form — the
     * buy-side twin of `OrderController::applyOrderAddressFromRequest()`.
     *
     * If a book entry was chosen, its fields seed the snapshot and it is recorded as the source;
     * the posted values then overwrite on top, so an admin's corrections apply to this document
     * only. The book entry itself is never modified.
     *
     * A no-op when the card was never rendered — same guard the sell side has: a post carrying no
     * `{type}_address_id` at all must not read as "no entry chosen" and sever the document's
     * source_address_id, which is a decision the submission never made.
     */
    protected function applyPurchaseAddressFromRequest(
        AbstractPurchaseDocument $document,
        Vendor $vendor,
        Request $request,
        string $type,
    ): void {
        if (!$request->request->has($type . '_address_id')) {
            return;
        }

        $snapshot = $document->addressForWriting($type);

        $addressId = (int) $request->request->get($type . '_address_id');
        if ($addressId > 0) {
            $source = $this->em->find(VendorAddress::class, $addressId);
            if ($source instanceof VendorAddress && $source->getVendor()->getId() === $vendor->getId()) {
                $snapshot->copyFrom($source);
            }
        } else {
            $snapshot->setSourceAddress(null);
        }

        $this->applyAddressEditsFromRequest($snapshot, $type, $request);
    }

    /** @return list<array<string, string>> */
    protected function addressBookRows(Vendor $vendor): array
    {
        $rows = [];
        foreach ($vendor->getAddresses() as $address) {
            if (!$address instanceof VendorAddress) {
                continue;
            }

            $row = $this->addressToRow($address, $vendor);
            $row['id'] = (string) $address->getId();
            $row['label'] = $this->addressLabel($address);
            $rows[] = $row;
        }

        return $rows;
    }

    protected function addressLabel(VendorAddress $address): string
    {
        $name = trim(($address->getFirstName() ?? '') . ' ' . ($address->getLastName() ?? ''));
        $parts = array_filter([
            implode('/', $address->purposeLabels()) ?: null,
            $name,
            $address->getAddressLine1(),
            $address->getCity(),
            $address->getProvince(),
            $address->getCountry(),
            $address->getPostalCode(),
        ], fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode(', ', $parts);
    }

    /**
     * The unit a posted line names, or null for the product's base unit — the buy-side twin of
     * `App\Controller\Admin\AbstractAdminController::lineUnitFor()`. Same rule, verbatim: a unit not
     * in this product's `product_available_unit` list reads as "the base unit" rather than a refused
     * edit, and the product's own base unit answers null rather than itself (NULL is how
     * DenominatedQuantity encodes "entered in base units").
     */
    protected function lineUnitFor(mixed $rawId, ?ProductCore $product, EntityManagerInterface $entityManager): ?UnitOfMeasure
    {
        if ($product === null) {
            return null;
        }

        $id = is_scalar($rawId) ? (int) $rawId : 0;
        if ($id <= 0 || $id === (int) ($product->getBaseUnit()?->getId() ?? 0)) {
            return null;
        }

        $unit = $entityManager->find(UnitOfMeasure::class, $id);
        if (!$unit instanceof UnitOfMeasure) {
            return null;
        }

        $offered = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ProductAvailableUnit::class, 'a')
            ->andWhere('a.product = :product')->setParameter('product', $product)
            ->andWhere('a.unit = :unit')->setParameter('unit', $unit)
            ->getQuery()->getSingleScalarResult();

        return $offered > 0 ? $unit : null;
    }

    /**
     * The units each named product may be expressed in, keyed by product id — the buy-side twin of
     * `AbstractAdminController::lineUnitChoicesFor()`. One query for a whole document rather than one
     * per row.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<array{id: int, code: string}>>
     */
    protected function lineUnitChoicesFor(array $productIds, EntityManagerInterface $entityManager): array
    {
        /** @var \App\Repository\ProductAvailableUnitRepository $repository */
        $repository = $entityManager->getRepository(ProductAvailableUnit::class);

        $choices = [];
        foreach ($repository->forProducts($productIds) as $productId => $rows) {
            foreach ($rows as $row) {
                $choices[$productId][] = ['id' => (int) $row->getUnit()->getId(), 'code' => $row->getUnit()->getCode()];
            }
        }

        return $choices;
    }

    /**
     * Writes a document line's quantity and the denomination it was said in — the buy-side twin of
     * `AbstractAdminController::applyLineQuantity()`.
     *
     * `$storeBase` takes the caller's own base setter rather than this method assuming one: the base
     * column is named differently on `PurchaseOrderLine` (`setQuantityOrdered()`) than on
     * `VendorBillLine` (`setQuantity()`) — the exact "named differently on each of the fourteen rows"
     * DenominatedQuantity's own docblock describes — so there is no one method name to call here.
     *
     * @param callable(string): void $storeBase
     */
    protected function applyLineQuantity(DenominatedLine $line, float $entered, float $baseQuantity, ?UnitOfMeasure $unit, ?UnitOfMeasure $base, callable $storeBase): void
    {
        // Both branches round through the one service, and the base branch used to be
        // `number_format($baseQuantity, 2, '.', '')` — two places against a `NUMERIC(14, 4)` column,
        // so a purchase order line for 12.3456 was stored as 12.35. See the sell-side twin,
        // `AbstractAdminController::applyLineQuantity()`, whose docblock carries the full note.
        if ($unit !== null) {
            $line->setEnteredQuantity($this->quantityScale()->round($entered), $unit, $base);

            return;
        }

        $storeBase($this->quantityScale()->round($baseQuantity));
    }

    /**
     * A unit cost at the scale its denomination needs — the buy-side twin of
     * `AbstractAdminController::lineRate()`.
     */
    protected function lineRate(string $value, ?UnitOfMeasure $unit): string
    {
        return number_format(
            (float) $value,
            $unit === null ? 4 : LineDenomination::RATE_SCALE,
            '.',
            '',
        );
    }
}
