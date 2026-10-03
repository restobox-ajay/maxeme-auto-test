<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Entity\AdminUser;
use App\Maxeme\Audit\ActivityLog;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Dto\RepairOrderAppointmentData;
use App\Maxeme\Dto\RepairOrderForm;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceLine;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\DocumentChargeKind;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\RepairOrderRepository;
use App\Maxeme\Repository\ServiceItemRepository;
use App\Maxeme\Repository\TechnicianRepository;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\AppointmentService;
use App\Maxeme\Service\RepairOrderWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Repair Order: the list, and the repair order page (new / edit), which saves its own
 * fields, services with their lines, custom fees and discounts, and upcoming appointments in one
 * go. Notes are RepairOrderNoteController's; an appointment's Check-in and Delete are here.
 */
#[Route('/admin/repair-orders', name: 'maxeme_repair_order_')]
final class RepairOrderController extends AbstractMaxemeController
{
    /** How many of its latest Activity Log rows the page shows; the Logs button has them all. */
    private const HISTORY_ROWS = 15;

    public function __construct(
        private readonly RepairOrderRepository $repairOrders,
        private readonly RepairOrderWriter $writer,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentNumbers $numbers,
        private readonly TechnicianRepository $technicians,
        private readonly ActivityLog $activityLog,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function index(Request $request): Response
    {
        return $this->render('maxeme/repair_order/index.html.twig', [
            'page' => $this->repairOrders->findPage(ListQuery::fromRequest($request, array_keys(RepairOrderRepository::SORTS), 'desc')),
            'statuses' => RepairOrderStatus::cases(),
        ]);
    }

    /** Repair Orders › Export CSV: every repair order of the current view (search boxes, status, sort), not just the page. */
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $list = ListQuery::fromRequest($request, array_keys(RepairOrderRepository::SORTS), 'desc');
        $repairOrders = $this->repairOrders->findAllInView($list);
        $filename = sprintf('repair-orders-%s.csv', (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));
        $activity->exported('work-order', 'RepairOrder', $filename, count($repairOrders), $list->describe());
        $numbers = $this->numbers;

        return CsvExport::response($filename, ['RO #', 'Date', 'Customer', 'Vehicle', 'Licence Plate', 'Repair Name', 'Status', 'Total'], (static function () use ($repairOrders, $numbers, $timezone): \Generator {
            foreach ($repairOrders as $repairOrder) {
                yield [
                    $numbers->repairOrderNumber($repairOrder),
                    $repairOrder->getCreatedOn()->setTimezone(new \DateTimeZone($timezone))->format('m/d/Y'),
                    $repairOrder->getClient()?->getFullName(),
                    $repairOrder->getVehicle()?->getFullName(),
                    $repairOrder->getVehicle()?->getLicensePlate(),
                    $repairOrder->getName(),
                    $repairOrder->getStatus()->label(),
                    $repairOrder->getTotal(),
                ];
            }
        })());
    }

    /** A new repair order; ?id= starts it for that client (Choose a client links so). */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        $clientId = self::idParam($request->query, 'id');
        $client = $clientId !== null ? $this->entityManager->getRepository(Client::class)->findOneBy(['id' => $clientId, 'active' => true]) : null;

        return $this->form($this->writer->start($user instanceof AdminUser ? $user : null, $client), $request, 'New Repair Order');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function edit(int $id, Request $request): Response
    {
        $repairOrder = $this->repairOrders->findForEdit($id) ?? throw new NotFoundHttpException('No such repair order.');

        return $this->form($repairOrder, $request, sprintf('Repair Order %s', $this->numbers->repairOrderNumber($repairOrder)));
    }

    /** The Customer box: active clients by name, preferred name or phone, with their vehicles. */
    #[Route('/clients', name: 'clients', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function clients(Request $request, ClientRepository $clients): JsonResponse
    {
        return $this->json(array_map(
            static fn (Client $client): array => [
                'value' => $client->getId(),
                'label' => $client->getFullName(),
                'category' => null,
                'price' => null,
                'phones' => $client->getPhones(),
                'email' => $client->getEmail(),
                'vehicles' => array_map(self::vehicleOption(...), array_values($client->getVehicles()->toArray())),
            ],
            $clients->suggest(trim((string) $request->query->get('q', ''))),
        ));
    }

    /** The Add Service box: active services, each with its default price, category and lines to pre-load. */
    #[Route('/services', name: 'services', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function services(Request $request, ServiceItemRepository $services): JsonResponse
    {
        return $this->json(array_map(
            static fn (ServiceItem $service): array => [
                'value' => $service->getId(),
                'label' => $service->getName(),
                'category' => $service->getCategory()?->getPath(),
                'price' => $service->getPrice(),
                'lines' => array_map(static fn (ServiceLine $line): array => [
                    'type' => $line->getType()->value,
                    'itemId' => $line->getItemId(),
                    'itemLabel' => $line->getType()->hasItem() ? $line->getItemLabel() : '',
                    'quantity' => $line->getQuantity(),
                    'unitPrice' => $line->getUnitPrice(),
                    'chargeThrough' => $line->isChargeThrough(),
                ], $service->getLines()),
            ],
            $services->searchWithLines(trim((string) $request->query->get('q', ''))),
        ));
    }

    #[Route('/{id}/appointments/{appointmentId}/check-in', name: 'appointment_check_in', requirements: ['id' => '\d+', 'appointmentId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function checkIn(#[MapEntity] RepairOrder $repairOrder, int $appointmentId): JsonResponse
    {
        try {
            $this->writer->checkIn($this->appointmentOf($repairOrder, $appointmentId));
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'Checked in.']);
    }

    #[Route('/{id}/appointments/{appointmentId}/delete', name: 'appointment_delete', requirements: ['id' => '\d+', 'appointmentId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function deleteAppointment(#[MapEntity] RepairOrder $repairOrder, int $appointmentId, AppointmentService $appointments): JsonResponse
    {
        $appointment = $this->appointmentOf($repairOrder, $appointmentId);
        if ($appointment->isPast()) {
            return $this->json(['message' => 'A past appointment cannot be deleted.'], Response::HTTP_CONFLICT);
        }
        try {
            $appointments->delete($appointment);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'Appointment deleted.']);
    }

    private function form(RepairOrder $repairOrder, Request $request, string $title): Response
    {
        $form = RepairOrderForm::fromEntity($repairOrder);
        $errors = [];

        if ($request->isMethod('POST')) {
            $form = RepairOrderForm::fromRequest($request);
            $errors = $this->writer->save($repairOrder, $form);
            if ($errors === []) {
                $this->addFlash('success', sprintf('Repair order %s saved.', $this->numbers->repairOrderNumber($repairOrder)));

                return $this->redirectToRoute('maxeme_repair_order_edit', ['id' => $repairOrder->getId()]);
            }
        }

        $client = $this->chosenClient($repairOrder, $form);

        return $this->render('maxeme/repair_order/form.html.twig', [
            'title' => $title,
            'repairOrder' => $repairOrder,
            'form' => $form,
            'errors' => $errors,
            'client' => $client,
            'clientVehicles' => array_map(self::vehicleOption(...), array_values($client?->getVehicles()->toArray() ?? [])),
            'appointmentRows' => $this->appointmentRows($repairOrder, $form, $request->isMethod('POST')),
            'statuses' => RepairOrderStatus::cases(),
            'lineTypes' => ServiceLineType::cases(),
            'chargeKinds' => DocumentChargeKind::cases(),
            'advisors' => $this->entityManager->getRepository(AdminUser::class)->findBy(['status' => 'Active'], ['firstName' => 'ASC', 'lastName' => 'ASC']),
            'technicians' => $this->technicians->findActive(),
            'history' => $repairOrder->getId() !== null ? $this->activityLog->latestFor($repairOrder, self::HISTORY_ROWS) : [],
            'timezone' => $this->schedule->timezone(),
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** The customer the form shows: the one picked (a refused save keeps the pick), else the saved one. */
    private function chosenClient(RepairOrder $repairOrder, RepairOrderForm $form): ?Client
    {
        $id = $form->data->clientId;
        if ($id === null || !ctype_digit($id)) {
            return $id === null ? null : $repairOrder->getClient();
        }

        return $this->entityManager->getRepository(Client::class)->findOneBy(['id' => (int) $id, 'active' => true]);
    }

    /**
     * The Appointments section: past ones shown as they were, upcoming ones editable (as posted, when a
     * save was refused), each numbered in time order.
     *
     * @return list<array{appointment: ?Appointment, data: ?RepairOrderAppointmentData}>
     */
    private function appointmentRows(RepairOrder $repairOrder, RepairOrderForm $form, bool $posted): array
    {
        $rows = [];
        $postedById = [];
        foreach ($form->appointments as $data) {
            if ($data->id !== null) {
                $postedById[$data->id] = $data;
            }
        }
        foreach ($repairOrder->getAppointments() as $appointment) {
            if ($appointment->isPast()) {
                $rows[] = ['appointment' => $appointment, 'data' => null];
                continue;
            }
            $data = $postedById[(string) $appointment->getId()] ?? null;
            if ($data === null) {
                $data = new RepairOrderAppointmentData();
                $data->id = (string) $appointment->getId();
                $data->start = $this->schedule->toLocal($appointment->getStartTime())->format('Y-m-d\TH:i');
                $data->promised = $appointment->getPromisedAt() !== null ? $this->schedule->toLocal($appointment->getPromisedAt())->format('Y-m-d\TH:i') : null;
            }
            $rows[] = ['appointment' => $appointment, 'data' => $data];
        }
        if ($posted) {
            foreach ($form->appointments as $data) {
                if ($data->id === null) {
                    $rows[] = ['appointment' => null, 'data' => $data];
                }
            }
        }

        return $rows;
    }

    private function appointmentOf(RepairOrder $repairOrder, int $appointmentId): Appointment
    {
        foreach ($repairOrder->getAppointments() as $appointment) {
            if ($appointment->getId() === $appointmentId) {
                return $appointment;
            }
        }

        throw new NotFoundHttpException('This appointment is not on the repair order.');
    }

    /** @return array{id: ?int, label: string, mileage: ?string} "2011 TOYOTA CAMRY · 008NLT" */
    private static function vehicleOption(Vehicle $vehicle): array
    {
        $plate = $vehicle->getLicensePlate();

        return ['id' => $vehicle->getId(), 'label' => $vehicle->getFullName() . ($plate !== null && $plate !== '' ? ' · ' . $plate : ''), 'mileage' => $vehicle->getMileage()];
    }
}
