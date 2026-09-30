<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\VehicleData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\VehicleRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * People › Vehicles (every active vehicle, and the sidebar Vehicle box's results) and the Vehicles
 * tab's add / edit / delete (legacy VehiclesController, ClientProfileController::removeClientCarAction).
 */
final class VehicleController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
        private readonly VehicleRepository $vehicles,
    ) {
    }

    #[Route('/admin/vehicles', name: 'maxeme_vehicle_index', methods: ['GET'])]
    #[RequiresPermission(Permission::CAR_VIEW)]
    public function index(Request $request): Response
    {
        $find = SearchTerm::fromRequest($request);

        return $this->render('maxeme/vehicle/index.html.twig', [
            'page' => $this->vehicles->findPage($find, ListQuery::fromRequest($request, array_keys(VehicleRepository::LIST_SORTS))),
            'find' => $find,
        ]);
    }

    /**
     * The sidebar Vehicle box: one match opens its owner's Vehicles tab (for a role that can open
     * clients), otherwise the Vehicles list of matches.
     */
    #[Route('/admin/vehicles/search', name: 'maxeme_vehicle_search', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::CAR_VIEW)]
    public function search(Request $request): RedirectResponse
    {
        $find = SearchTerm::fromRequest($request);
        $matches = $this->vehicles->findPage($find, ListQuery::fromRequest($request, array_keys(VehicleRepository::LIST_SORTS)));

        if ($matches->total === 1 && $this->isGranted(Permission::PEOPLE_VIEW)) {
            return $this->toVehiclesTab($matches->items[0]->getClient());
        }

        return $this->redirectToRoute('maxeme_vehicle_index', [SearchTerm::PARAM => $find->text]);
    }

    #[Route('/admin/clients/{id}/vehicles', name: 'maxeme_vehicle_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function create(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $data = VehicleData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($vehicle = new Vehicle($client), $data);
            $this->addFlash('success', sprintf('%s added.', $vehicle->getFullName()));
        }

        return $this->toVehiclesTab($client);
    }

    #[Route('/admin/vehicles/{id}', name: 'maxeme_vehicle_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function update(#[MapEntity] Vehicle $vehicle, Request $request): RedirectResponse
    {
        $data = VehicleData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($vehicle, $data);
            $this->addFlash('success', sprintf('%s saved.', $vehicle->getFullName()));
        }

        return $this->toVehiclesTab($vehicle->getClient());
    }

    #[Route('/admin/vehicles/{id}/delete', name: 'maxeme_vehicle_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function delete(#[MapEntity] Vehicle $vehicle): JsonResponse
    {
        $this->records->delete($vehicle);

        return $this->json(['message' => sprintf('%s deleted.', $vehicle->getFullName())]);
    }

    private function toVehiclesTab(Client $client): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId(), 'tab' => ClientProfileTab::Vehicles->value]);
    }
}
