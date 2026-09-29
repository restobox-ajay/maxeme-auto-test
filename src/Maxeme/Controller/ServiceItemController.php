<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ServiceItemData;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Parts & Services › Services (legacy CNSServiceBundle manageController). Deleting asks for confirmation. */
#[Route('/admin/services', name: 'maxeme_service_')]
#[IsGranted(StaffRole::MANAGER)]
final class ServiceItemController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ServiceItemRepository $repository): Response
    {
        return $this->render('maxeme/service/index.html.twig', [
            'page' => $repository->findPage(ListQuery::fromRequest($request, array_keys(ServiceItemRepository::SORTS))),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        return $this->save(new ServiceItem(), $request, '%s added.');
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(#[MapEntity] ServiceItem $service, Request $request): RedirectResponse
    {
        return $this->save($service, $request, '%s saved.');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity] ServiceItem $service): JsonResponse
    {
        $this->records->delete($service);

        return $this->json(['message' => sprintf('%s deleted.', $service->getName())]);
    }

    private function save(ServiceItem $service, Request $request, string $message): RedirectResponse
    {
        $data = ServiceItemData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($service, $data);
            $this->addFlash('success', sprintf($message, $service->getName()));
        }

        return $this->redirectBack($request, 'maxeme_service_index');
    }
}
