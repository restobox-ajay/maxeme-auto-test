<?php

namespace App\Controller\Admin;

use App\Entity\PriceList;
use App\Service\AppSettings;
use App\Validation\Constraint\ValidPriceList;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/price-list')]
final class PriceListController extends AbstractAdminController
{
    #[Route('/index', name: 'admin_price_list_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');

        $qb = $entityManager->getRepository(PriceList::class)->createQueryBuilder('p');
        
        if ($search) {
            $qb->andWhere('p.name LIKE :q')->setParameter('q', '%' . $search . '%');
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderExpr = match ($sort) {
            'id' => 'p.id',
            'name' => 'p.name',
            'currency' => 'p.currency',
            'status' => 'p.status',
            'companies' => 'p.name',
            'companyCount' => 'p.id',
            default => 'p.id',
        };

        $priceLists = $qb->select('p')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = array_map(
            fn (PriceList $pl): array => $this->priceListToRow($pl, $entityManager),
            $priceLists
        );

        if ($sort === 'companyCount' || $sort === 'companies') {
            usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
                $desc = $dir === 'DESC';
                if ($sort === 'companyCount') {
                    $av = (int) ($a['companyCount'] ?? 0);
                    $bv = (int) ($b['companyCount'] ?? 0);
                } else {
                    $av = (string) ($a['companies'] ?? '');
                    $bv = (string) ($b['companies'] ?? '');
                }
                if ($av === $bv) { return 0; }
                $cmp = $av < $bv ? -1 : 1;
                return $desc ? -$cmp : $cmp;
            });
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/product/_price_list_rows.html.twig', ['priceLists' => $rows]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        return $this->render('admin/product/price_lists.html.twig', [
            'priceLists' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'search' => (string) $search,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
        ]);
    }

    #[Route('/create', name: 'admin_price_list_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $defaultCurrency = $appSettings->get('base_currency', 'USD') ?: 'USD';

        if ($request->isMethod('POST')) {
            $priceList = new PriceList();
            $this->applyPriceListRequest($priceList, $request, $defaultCurrency);
            $errors = $this->validatePriceList($priceList, $request, $defaultCurrency);

            if ($errors === []) {
                $entityManager->persist($priceList);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Price list "%s" was created successfully.', $priceList->getName()));
                return $this->redirectToRoute('admin_price_list_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/product/price_list_form.html.twig', [
                'mode' => 'Create',
                'priceList' => $this->priceListToRow($priceList, $entityManager),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/product/price_list_form.html.twig', [
            'mode' => 'Create',
            'priceList' => ['id' => '', 'name' => '', 'currency' => $defaultCurrency, 'status' => 'Active'],
        ]);
    }

    #[Route('/update/{id}', name: 'admin_price_list_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $priceList = $entityManager->find(PriceList::class, $id);
        if (!$priceList instanceof PriceList) {
            $this->addFlash('error', 'Price list could not be found.');
            return $this->redirectToRoute('admin_price_list_index');
        }

        if ($request->isMethod('POST')) {
            $defaultCurrency = $appSettings->get('base_currency', 'USD') ?: 'USD';
            $this->applyPriceListRequest($priceList, $request, $defaultCurrency);
            $errors = $this->validatePriceList($priceList, $request, $defaultCurrency);

            if ($errors === []) {
                $entityManager->flush();
                $this->addFlash('success', sprintf('Price list "%s" was updated successfully.', $priceList->getName()));
                return $this->redirectToRoute('admin_price_list_index');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/product/price_list_form.html.twig', [
                'mode' => 'Update',
                'priceList' => $this->priceListToRow($priceList, $entityManager),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/product/price_list_form.html.twig', [
            'mode' => 'Update',
            'priceList' => $this->priceListToRow($priceList, $entityManager),
        ]);
    }

    #[Route('/delete/{id}', name: 'admin_price_list_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $priceList = $entityManager->find(PriceList::class, $id);
        if ($priceList instanceof PriceList) {
            $name = $priceList->getName();
            $entityManager->remove($priceList);

            try {
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => sprintf('Price list "%s" is still in use (for example, by a company or a fulfillment region) and cannot be deleted.', $name),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse(['ok' => true, 'message' => sprintf('Price list "%s" was deleted successfully.', $name)]);
        }

        return new JsonResponse(['ok' => false, 'message' => 'Price list could not be found.'], Response::HTTP_NOT_FOUND);
    }

    private function applyPriceListRequest(PriceList $priceList, Request $request, string $defaultCurrency): void
    {
        $priceList
            ->setName(trim((string) $request->request->get('name', '')))
            ->setCurrency(trim((string) $request->request->get('currency', $defaultCurrency)) ?: $defaultCurrency)
            ->setStatus((string) $request->request->get('status', 'Active'));
    }

    /**
     * Ported to a symfony/validator constraint for #308, following ValidCompanyAddress's #309
     * pattern: ValidPriceListValidator runs the same rules this method used to run by hand — the
     * raw submitted currency, not $priceList->getCurrency(), for the reason explained on
     * ValidPriceList itself — so a future fix is inherited by create() and update() without either
     * changing anything.
     *
     * @return list<string>
     */
    private function validatePriceList(PriceList $priceList, Request $request, string $defaultCurrency): array
    {
        $rawCurrency = strtoupper(trim((string) $request->request->get('currency', $defaultCurrency)) ?: $defaultCurrency);

        $violations = Validation::createValidator()->validate($priceList, new ValidPriceList($rawCurrency));

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }
}
