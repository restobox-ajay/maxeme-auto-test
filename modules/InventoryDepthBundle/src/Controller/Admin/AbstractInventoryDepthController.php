<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\Product\ProductPicker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Service\Inventory\InventoryModeResolver;

/**
 * Shared plumbing for the bundle's admin screens.
 *
 * `/admin` is already gated by `access_control` and AdminHostSubscriber, so nothing here re-checks
 * the role. What it does add is the bundle's own Active/Inactive kill-switch: enforcement in this
 * codebase is per-seam rather than central, and a route stays reachable by URL after the nav link
 * for it disappears — so each screen has to refuse for itself.
 */
abstract class AbstractInventoryDepthController extends AbstractController
{
    public const SOURCE = 'InventoryDepthBundle';

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly BundleStatusRepository $bundleStatusRepo,
        protected readonly InventoryModeResolver $inventoryModes,
    ) {
    }

    /** 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden. */
    protected function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive(self::SOURCE)) {
            throw new NotFoundHttpException('Inventory Depth is not active.');
        }
    }

    /**
     * Filter state lives in URL GET params on every list screen here, so a copied URL reproduces
     * the exact result. Standing requirement in this codebase and restated in the #550 plan.
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
            // `?filters[bin][]=x` makes this an array, and casting one to string raises a PHP
            // notice — which the dev error handler turns into a 500 from nothing but a crafted URL,
            // and which prod would quietly filter on the literal string "Array". A non-scalar is not
            // a filter anyone typed, so it reads as absent.
            $filters[$key] = \is_scalar($value) ? trim((string) $value) : '';
        }

        return $filters;
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

    /**
     * The product id a posted form names — the picker's select first, then its no-JS id box.
     *
     * `admin/_partials/product_field.html.twig` renders two controls past the inline limit, under
     * two different names, so which one won has to be decided in code rather than by whichever PHP
     * happened to parse last. The rule lives in core beside the markup that writes those names
     * (#8); this is the same call ProcurementBundle's screens make, not a second copy of it.
     *
     * @param array<string, mixed> $row the posted bag, or one row of a repeated form
     */
    protected function productIdFrom(array $row, string $key = 'product_id'): int
    {
        return ProductPicker::idFromPostedRow($row, $key);
    }

    protected function productOr404(int $id): ProductCore
    {
        $product = $this->em->find(ProductCore::class, $id);
        if (!$product instanceof ProductCore) {
            throw new NotFoundHttpException('No such product.');
        }

        return $product;
    }

    /** Who did it, for the movement group's `actor`. */
    protected function actor(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }

    /**
     * The idempotency key for one submission of one form: a digest of that form's CSRF token.
     *
     * The token is the right thing to key on — Symfony re-randomises it per render, so a
     * double-submitted form applies once while a deliberate second adjustment (a fresh page load)
     * gets a fresh token and applies again. It is the wrong thing to *store*, twice over:
     *
     *  - it does not fit. Symfony 7.4 returns a randomised token of 104–135 characters and
     *    `inventory_movement_group.client_operation_id` is VARCHAR(64). SQLite ignores the length so
     *    nothing here would ever notice, which is exactly what makes it worth fixing: on the MySQL
     *    DSN this app also supports, strict mode rejects the INSERT and non-strict mode truncates.
     *  - a movement group is an audit record admins read. A CSRF token is not something to copy into
     *    one, however randomised.
     *
     * A digest is 32 hex characters, keeps the one property that matters (same token ⇒ same key,
     * different token ⇒ different key), and fits with room to spare. Returns null when the field is
     * absent, so MovementRequest::of() falls back to a random key rather than to the empty string —
     * one stored empty key would swallow every later empty-key operation.
     */
    protected function operationKey(Request $request): ?string
    {
        $token = trim((string) $request->request->get('_token', ''));

        return $token === '' ? null : hash('xxh128', $token);
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
}
