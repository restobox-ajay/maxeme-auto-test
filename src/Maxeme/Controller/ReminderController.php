<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Accounting\ReportPeriod;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\ReminderRequest;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Enum\PhoneStatus;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Reminder\ReminderList;
use App\Service\AppSettings;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Reminder;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ReminderStatus;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\ReminderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use App\Maxeme\Service\ReminderGenerator;
use App\Service\BusinessDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Reminders (legacy ReminderController; mockups 1b7f972, aa322bb): the reminders and the
 * service reminder queue in one paginated list (ReminderList), this week by default. Opening the page
 * creates the day's reminders, as in the legacy app; `app:maxeme:generate-reminders` does the same
 * from a scheduler.
 */
final class ReminderController extends AbstractMaxemeController
{
    #[Route('/admin/reminders', name: 'maxeme_reminder_index', methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function index(Request $request, ReminderGenerator $generator, ReminderList $list, ClientRepository $clients, AppSettings $appSettings): Response
    {
        $generator->generate();
        $period = self::period($request, $appSettings);
        $query = ListQuery::fromRequest($request, ReminderList::SORTS, 'desc');
        $rows = $list->rows($period, $query);
        $page = ListPage::fromArray($rows, $query);
        // Each row shows its client's newest note.
        $byId = [];
        foreach ($page->items as $row) {
            $client = ($row['reminder'] ?? $row['entry'])->getClient();
            $byId[$client->getId()] = $client;
        }
        $clients->loadAddressesAndNotes(array_values($byId));
        $latest = $rows === [] ? $list->latestDate()?->setTimezone($appSettings->timezone()) : null;

        return $this->render('maxeme/reminder/index.html.twig', [
            'period' => $period,
            'page' => $page,
            'statuses' => ReminderList::STATUSES,
            'emailStatuses' => ReminderList::EMAIL_STATUSES,
            'phoneStatuses' => PhoneStatus::cases(),
            'latestDay' => $latest?->format('Y-m-d'),
            'later' => $rows !== [] ? $list->countAfter($period) : 0,
        ]);
    }

    /** Export CSV (mockup a38e7a2): every row of the period in the current search, filters and sort. */
    #[Route('/admin/reminders/export.csv', name: 'maxeme_reminder_export', methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function export(Request $request, ReminderList $list, AppSettings $appSettings, ActivityRecorder $activity): Response
    {
        $period = self::period($request, $appSettings);
        $query = ListQuery::fromRequest($request, ReminderList::SORTS, 'desc');
        $rows = $list->rows($period, $query);
        $filename = sprintf('reminders-%s.csv', $period->slug());
        $activity->exported('reminder', 'Reminder', $filename, count($rows), sprintf('%s to %s, %s', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $query->describe()));
        $zone = $appSettings->timezone();

        return CsvExport::response($filename, ['Date', 'Kind', 'Status', 'Client', 'Vehicle', 'Reminder note', 'Phone numbers', 'Phone Status', 'Email', 'Email Status'], (static function () use ($rows, $zone): \Generator {
            foreach ($rows as $row) {
                $source = $row['reminder'] ?? $row['entry'];
                yield [
                    $row['date']->setTimezone($zone)->format('m/d/Y h:i A'),
                    $row['kind'] === 'queue' ? 'Service reminder' : 'Reminder',
                    $row['statusLabel'],
                    $source->getClient()->getFullName(),
                    $source->getVehicle()->getFullName(),
                    $row['reminder'] !== null ? trim(($row['reminder']->getNote() ?? '') . ($row['reminder']->getDelayNote() !== null ? ' (Delayed: ' . $row['reminder']->getDelayNote() . ')' : '')) : $row['entry']->getServiceName(),
                    implode(' / ', $source->getClient()->getPhones()),
                    PhoneStatus::from($row['phoneStatus'])->label(),
                    $row['entry']?->getEmail() ?? $source->getClient()->getEmail(),
                    $row['emailStatus'] !== null ? ReminderList::EMAIL_STATUSES[$row['emailStatus']] : null,
                ];
            }
        })());
    }

    /** Phone Status (mockup aa322bb): how the call went, on a reminder (`reminder`) or a queue entry (`queue`). */
    #[Route('/admin/reminders/{kind}/{id}/phone-status', name: 'maxeme_reminder_phone_status', requirements: ['kind' => 'reminder|queue', 'id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function phoneStatus(string $kind, int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $status = PhoneStatus::tryFrom((string) $request->request->get('phone_status', ''));
        $record = $entityManager->find($kind === 'queue' ? ServiceReminderQueueEntry::class : Reminder::class, $id);
        if ($status === null || $record === null) {
            return $this->json(['message' => 'Choose a phone status.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $record->setPhoneStatus($status);
        $entityManager->flush();

        return $this->json(['message' => sprintf('Phone status: %s.', $status->label())]);
    }

    /** This week (Sunday to today) unless the filter says otherwise (mockup aa322bb). */
    private static function period(Request $request, AppSettings $appSettings): ReportPeriod
    {
        $dayOfWeek = (int) (new \DateTimeImmutable('today', $appSettings->timezone()))->format('w');

        return ReportPeriod::fromRequest($request, $appSettings->timezone(), sprintf('-%d days', $dayOfWeek));
    }

    /**
     * Schedule › Reminders › "+" (after Choose a client): a reminder added by hand for one of the
     * client's vehicles, due on the chosen shop day. It joins the list like a generated one.
     */
    #[Route('/admin/clients/{id}/reminders/new', name: 'maxeme_reminder_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function new(#[MapEntity] Client $client, Request $request, RecordWriter $records, BusinessDate $businessDate, EntityManagerInterface $entityManager): Response
    {
        $data = new ReminderRequest();
        $data->reminderDate = $businessDate->today();
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = ReminderRequest::fromRequest($request);
            $errors = $records->validate($data);
            $vehicle = $client->getVehicles()->filter(static fn (Vehicle $vehicle): bool => $vehicle->getId() === $data->vehicleId)->first() ?: null;
            if (!isset($errors['vehicleId']) && $vehicle === null) {
                $errors['vehicleId'] = 'Choose one of the client\'s vehicles.';
            }

            if ($errors === []) {
                $day = $businessDate->localDayRangeUtc(new \DateTimeImmutable($data->reminderDate))[0];
                $entityManager->persist(new Reminder($client, $vehicle, null, $data->note, $day));
                $entityManager->flush();
                $this->addFlash('success', sprintf('Reminder added for %s.', $client->getFullName()));

                return $this->redirectToRoute('maxeme_reminder_index');
            }
        }

        return $this->render('maxeme/reminder/new.html.twig', [
            'client' => $client,
            'data' => $data,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Resolved, Declined or Delayed (with the reason, `note`), while the reminder is open. */
    #[Route('/admin/reminders/{id}/status', name: 'maxeme_reminder_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function status(#[MapEntity] Reminder $reminder, Request $request, EntityManagerInterface $entityManager): Response
    {
        $status = ReminderStatus::tryFrom((string) $request->request->get('status', ''));
        // "booked" is what the legacy Resolved button posted.
        $status = $status === ReminderStatus::Booked ? ReminderStatus::Resolved : $status;
        if (!in_array($status, [ReminderStatus::Resolved, ReminderStatus::Declined, ReminderStatus::Delayed], true)) {
            return $this->json(['message' => 'Choose Resolved, Delayed or Declined.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $reminder->changeStatus($status, trim((string) $request->request->get('note', '')) ?: null);
        } catch (\DomainException $exception) {
            if ($request->request->has(self::RETURN_FIELD)) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectBack($request, 'maxeme_reminder_index');
            }

            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $entityManager->flush();

        $message = sprintf('Reminder for %s: %s.', $reminder->getClient()->getFullName(), $status->value);
        if ($request->request->has(self::RETURN_FIELD)) {
            $this->addFlash('success', $message);

            return $this->redirectBack($request, 'maxeme_reminder_index');
        }

        return new JsonResponse(['message' => $message]);
    }
}
