<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Dto\VehicleData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Repository\ServiceCategoryRepository;
use App\Maxeme\Repository\VehicleRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * People › Vehicles: every active vehicle (and the sidebar Vehicle box's results), a vehicle's own
 * page (its stats, repair order history and notes), its add and edit pages (where it can be
 * assigned to another client), and delete (legacy VehiclesController,
 * ClientProfileController::removeClientCarAction).
 */
final class VehicleController extends AbstractMaxemeController
{
    public function __construct(
        private readonly RecordWriter $records,
        private readonly VehicleRepository $vehicles,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The vehicle page: its details and stats, its repair orders (by service or service category), its notes. */
    #[Route('/admin/vehicles/{id}', name: 'maxeme_vehicle_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[RequiresPermission(Permission::CAR_VIEW)]
    public function show(#[MapEntity] Vehicle $vehicle, Request $request, RepairOrderRepository $repairOrders, ServiceCategoryRepository $categories): Response
    {
        if (!$vehicle->isActive()) {
            throw $this->createNotFoundException('This vehicle has been deleted.');
        }
        $serviceId = self::idParam($request->query, 'service');
        $categoryId = self::idParam($request->query, 'category');
        $category = $categoryId !== null ? $categories->find($categoryId) : null;
        $history = $repairOrders->findHistoryForVehicle($vehicle, $serviceId, $category !== null ? $categories->idsWithin($category) : []);
        $all = $serviceId === null && $category === null ? $history : $repairOrders->findHistoryForVehicle($vehicle);

        // The services this vehicle has had, for the Service filter.
        $usedServices = [];
        foreach ($all as $repairOrder) {
            foreach ($repairOrder->getJobs() as $job) {
                if ($job->getService() !== null) {
                    $usedServices[$job->getService()->getId()] = $job->getService();
                }
            }
        }
        uasort($usedServices, static fn (ServiceItem $a, ServiceItem $b): int => strcasecmp($a->getName(), $b->getName()));

        return $this->render('maxeme/vehicle/show.html.twig', [
            'vehicle' => $vehicle,
            'history' => $history,
            'stats' => self::stats($all),
            'services' => array_values($usedServices),
            'categories' => $categories->findTree(),
            'serviceId' => $serviceId,
            'category' => $category,
        ]);
    }

    /** Add a vehicle (?client= picks its owner, as the client's Vehicles tab links). */
    #[Route('/admin/vehicles/new', name: 'maxeme_vehicle_new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function new(Request $request): Response
    {
        $clientId = self::idParam($request->query, 'client');
        $client = $clientId !== null ? $this->entityManager->getRepository(Client::class)->findOneBy(['id' => $clientId, 'active' => true]) : null;

        return $this->form($request, null, $client, 'Add Vehicle');
    }

    #[Route('/admin/vehicles/{id}/edit', name: 'maxeme_vehicle_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function edit(#[MapEntity] Vehicle $vehicle, Request $request): Response
    {
        return $this->form($request, $vehicle, $vehicle->getClient(), sprintf('Edit Vehicle: %s', $vehicle->getFullName()));
    }

    /** The add / edit page's client box: active clients by name or phone. */
    #[Route('/admin/vehicles/clients', name: 'maxeme_vehicle_clients', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function clients(Request $request, ClientRepository $clients): JsonResponse
    {
        return $this->json(array_map(
            static fn (Client $client): array => ['value' => $client->getId(), 'label' => $client->getFullName(), 'category' => null, 'price' => null],
            $clients->suggest(trim((string) $request->query->get('q', ''))),
        ));
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

    /** The sidebar Vehicle box: one match opens its vehicle page, otherwise the Vehicles list of matches. */
    #[Route('/admin/vehicles/search', name: 'maxeme_vehicle_search', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::CAR_VIEW)]
    public function search(Request $request): RedirectResponse
    {
        $find = SearchTerm::fromRequest($request);
        $matches = $this->vehicles->findPage($find, ListQuery::fromRequest($request, array_keys(VehicleRepository::LIST_SORTS)));

        if ($matches->total === 1) {
            return $this->redirectToRoute('maxeme_vehicle_show', ['id' => $matches->items[0]->getId()]);
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

    private function form(Request $request, ?Vehicle $vehicle, ?Client $client, string $title): Response
    {
        $data = $vehicle !== null ? VehicleData::fromEntity($vehicle) : new VehicleData();
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = VehicleData::fromRequest($request);
            $errors = $this->records->validate($data);
            $clientId = self::idParam($request->request, 'client_id');
            $client = $clientId !== null ? $this->entityManager->getRepository(Client::class)->findOneBy(['id' => $clientId, 'active' => true]) : null;
            if ($client === null) {
                $errors['clientId'] = 'Choose the client the vehicle belongs to.';
            }
            if ($errors === [] && $client !== null) {
                if ($vehicle === null) {
                    $vehicle = new Vehicle($client);
                } elseif ($vehicle->getClient() !== $client) {
                    $vehicle->assignTo($client);
                }
                $this->records->save($vehicle, $data);
                $this->addFlash('success', sprintf('%s saved.', $vehicle->getFullName() ?: 'Vehicle'));

                return $this->redirectToRoute('maxeme_vehicle_show', ['id' => $vehicle->getId()]);
            }
        }

        return $this->render('maxeme/vehicle/form.html.twig', [
            'title' => $title,
            'vehicle' => $vehicle,
            'client' => $client,
            'data' => $data,
            'errors' => $errors,
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param list<RepairOrder> $repairOrders newest first
     *
     * @return array{visits: int, spent: string, first: ?\DateTimeImmutable, last: ?\DateTimeImmutable, services: int}
     */
    private static function stats(array $repairOrders): array
    {
        $spent = 0;
        $services = 0;
        foreach ($repairOrders as $repairOrder) {
            if ($repairOrder->getStatus() !== RepairOrderStatus::Cancelled) {
                $spent += Money::toCents($repairOrder->getTotal());
            }
            $services += count($repairOrder->getJobs());
        }

        return [
            'visits' => count($repairOrders),
            'spent' => Money::fromCents($spent),
            'first' => $repairOrders !== [] ? $repairOrders[array_key_last($repairOrders)]->getCreatedOn() : null,
            'last' => $repairOrders !== [] ? $repairOrders[0]->getCreatedOn() : null,
            'services' => $services,
        ];
    }

    private function toVehiclesTab(Client $client): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId(), 'tab' => ClientProfileTab::Vehicles->value]);
    }
}
