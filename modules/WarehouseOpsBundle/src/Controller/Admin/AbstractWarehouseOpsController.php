<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the bundle's admin screens (#552).
 *
 * `/admin` is already gated by `access_control` and AdminHostSubscriber, so nothing here re-checks
 * the role. What it adds is the bundle's own Active/Inactive kill-switch, which has to be enforced
 * per screen because a route stays reachable by URL after the nav link for it disappears — the same
 * shape AbstractInventoryDepthController uses, deliberately, so the two bundles behave the same way
 * when either is switched off.
 *
 * ## And the depth bundle's own switch, on top
 *
 * Every screen here ultimately calls InventoryDepthBundle's movement service. With Inventory Depth
 * switched Inactive or removed there is nothing behind these screens at all, so they refuse too —
 * checked once, here, rather than remembered in six controllers.
 */
abstract class AbstractWarehouseOpsController extends AbstractController
{
    public const SOURCE = 'WarehouseOpsBundle';
    public const DEPTH_SOURCE = 'InventoryDepthBundle';

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** 404 rather than 403: an Inactive bundle's screens should read as absent, not forbidden. */
    protected function denyIfInactive(): void
    {
        if (!$this->bundleStatusRepo->isActive(self::SOURCE)) {
            throw new NotFoundHttpException('Warehouse Operations is not active.');
        }
        if (!$this->bundleStatusRepo->isActive(self::DEPTH_SOURCE)) {
            throw new NotFoundHttpException('Warehouse Operations needs Inventory Depth, which is not active.');
        }
    }

    /**
     * Filter state lives in URL GET params on every list screen. Standing requirement in this
     * codebase, restated in the #552 plan, and the reason a copied URL reproduces the exact result.
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
            // `?filters[x][]=y` makes this an array, and casting one to string raises a notice the
            // dev error handler turns into a 500 from nothing but a crafted URL. A non-scalar is not
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

    protected function binOrNull(int $id): ?WarehouseLocation
    {
        if ($id <= 0) {
            return null;
        }

        $bin = $this->em->find(WarehouseLocation::class, $id);

        return $bin instanceof WarehouseLocation ? $bin : null;
    }

    /** Who did it, for the movement group's `actor`. */
    protected function actor(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }

    /**
     * The idempotency key for one submission.
     *
     * **Warehouse wi-fi is bad**, and a submit that times out and gets retried must not move stock
     * twice. The device generates the key before the request and sends it in `op_id`; the scan
     * console does exactly that, which is what makes a retry from a handheld safe rather than
     * hopeful.
     *
     * A desktop form that sends no `op_id` falls back to a digest of its CSRF token. Symfony
     * re-randomises that per render, so a double-submitted form applies once while a deliberate
     * second action (a fresh page load) gets a fresh token and applies again. The token itself is
     * never stored — it does not fit `client_operation_id`'s VARCHAR(64), and a movement group is an
     * audit record admins read.
     *
     * Returns null when there is neither, so MovementRequest::of() falls back to a random key rather
     * than to the empty string: one stored empty key would swallow every later empty-key operation.
     */
    protected function operationKey(Request $request): ?string
    {
        $supplied = trim((string) $request->request->get('op_id', ''));
        if ($supplied !== '') {
            return substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $supplied) ?? '', 0, 48);
        }

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

    protected function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
