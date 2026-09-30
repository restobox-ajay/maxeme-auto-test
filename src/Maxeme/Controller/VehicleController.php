<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\VehicleData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** The Vehicles tab's add / edit / delete (legacy VehiclesController, ClientProfileController::removeClientCarAction). */
final class VehicleController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
    ) {
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
