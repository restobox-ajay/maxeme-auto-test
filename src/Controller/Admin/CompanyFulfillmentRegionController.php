<?php

namespace App\Controller\Admin;

use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Validation\Constraint\ValidFulfillmentRegionActivation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/company-fulfillment-region')]
final class CompanyFulfillmentRegionController extends AbstractAdminController
{
    #[Route('', name: 'admin_company_fulfillment_region_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $filterCompany = trim((string) ($filters['company'] ?? ''));
        $filterRegion = trim((string) ($filters['region'] ?? ''));
        $filterPriceList = trim((string) ($filters['priceList'] ?? ''));
        $filterStatus = trim((string) ($filters['status'] ?? ''));

        $qb = $entityManager->getRepository(CompanyFulfillmentRegion::class)->createQueryBuilder('cfr')
            ->join('cfr.company', 'c')
            ->join('cfr.fulfillmentRegion', 'r')
            ->leftJoin('cfr.priceList', 'pl');

        if ($filterCompany !== '') {
            $qb->andWhere('c.name LIKE :filterCompany')->setParameter('filterCompany', '%' . $filterCompany . '%');
        }
        if ($filterRegion !== '') {
            $qb->andWhere('r.name LIKE :filterRegion')->setParameter('filterRegion', '%' . $filterRegion . '%');
        }
        if ($filterPriceList !== '') {
            $qb->andWhere('pl.name LIKE :filterPriceList')->setParameter('filterPriceList', '%' . $filterPriceList . '%');
        }
        if ($filterStatus !== '') {
            $qb->andWhere('cfr.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(cfr.id)')->getQuery()->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $sort = trim((string) $request->query->get('sort', 'company'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderExpr = match ($sort) {
            'company' => 'c.name',
            'region' => 'r.name',
            'priceList' => 'pl.name',
            'status' => 'cfr.status',
            default => 'c.name',
        };

        $rows = $qb->select('cfr')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('r.name', 'ASC')
            ->addOrderBy('cfr.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rowData = array_map(
            fn (CompanyFulfillmentRegion $row): array => $this->companyFulfillmentRegionToRow($row),
            $rows
        );

        $priceLists = $this->priceListRowsFromDatabase($entityManager);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/company_fulfillment_region/_rows.html.twig', [
                    'rows' => $rowData,
                    'priceLists' => $priceLists,
                ]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pageCount,
            ]);
        }

        return $this->render('admin/company_fulfillment_region/index.html.twig', [
            'rows' => $rowData,
            'priceLists' => $priceLists,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pageCount,
            'filters' => [
                'company' => $filterCompany,
                'region' => $filterRegion,
                'priceList' => $filterPriceList,
                'status' => $filterStatus,
            ],
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
        ]);
    }

    #[Route('/{id}/update', name: 'admin_company_fulfillment_region_update', methods: ['POST'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): Response {
        $isXhr = $request->isXmlHttpRequest();
        $returnUrl = (string) $request->request->get('return_url', '');
        $fallback = function (string $type, string $message) use ($isXhr, $returnUrl): Response {
            if ($isXhr) {
                return new JsonResponse(
                    ['ok' => false, 'message' => $message],
                    $type === 'not_found' ? Response::HTTP_NOT_FOUND : ($type === 'csrf' ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY)
                );
            }
            $this->addFlash('error', $message);

            return $this->redirectSafely($returnUrl);
        };

        $row = $entityManager->find(CompanyFulfillmentRegion::class, $id);
        if (!$row instanceof CompanyFulfillmentRegion) {
            return $fallback('not_found', 'Row could not be found.');
        }

        $active = ((string) $request->request->get('status', 'Inactive')) === 'Active';
        $priceListId = trim((string) $request->request->get('price_list_id', ''));
        $priceList = $priceListId !== '' ? $entityManager->find(PriceList::class, (int) $priceListId) : null;

        // Delegated to the ValidationExceptionSubscriber (issue #309) rather than the $fallback
        // closure above: that closure exists for "the row/CSRF token isn't there at all", not for a
        // validation failure, which already has a uniform JSON/flash-and-redirect handler.
        $violations = $validator->validate($active, new ValidFulfillmentRegionActivation(
            $priceList instanceof PriceList,
            $row->getFulfillmentRegion()->getName(),
        ));
        if (count($violations) > 0) {
            throw new ValidationFailedException($active, $violations);
        }

        $row->setStatus($active ? 'Active' : 'Inactive')
            ->setPriceList($priceList instanceof PriceList ? $priceList : null)
            ->touch();

        $entityManager->flush();

        $successMessage = sprintf('Updated "%s" — %s.', $row->getCompany()->getName(), $row->getFulfillmentRegion()->getName());

        if (!$isXhr) {
            $this->addFlash('success', $successMessage);

            return $this->redirectSafely($returnUrl);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $successMessage,
            'status' => $row->getStatus(),
            'priceListName' => $row->getPriceList()?->getName() ?? '-',
        ]);
    }

    private function redirectSafely(string $returnUrl): Response
    {
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/admin/company-fulfillment-region')) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('admin_company_fulfillment_region_index');
    }

    /** @return array<string, string> */
    private function companyFulfillmentRegionToRow(CompanyFulfillmentRegion $row): array
    {
        return [
            'id' => (string) $row->getId(),
            'companyId' => (string) $row->getCompany()->getId(),
            'companyName' => $row->getCompany()->getName(),
            'regionId' => (string) $row->getFulfillmentRegion()->getId(),
            'regionName' => $row->getFulfillmentRegion()->getName(),
            'priceListId' => (string) ($row->getPriceList()?->getId() ?? ''),
            'priceListName' => $row->getPriceList()?->getName() ?? '-',
            'status' => $row->getStatus(),
        ];
    }
}
