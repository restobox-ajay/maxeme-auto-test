<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Reminder\ServiceReminderEmail;
use App\Maxeme\Reminder\ServiceReminderQueue;
use App\Maxeme\Repository\ServiceReminderQueueRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Service Reminder Queue: the reminder emails completed repair orders queued
 * (App\Maxeme\Reminder\ServiceReminderQueue), what each would say or said, Resend, Book (a new
 * repair order for the client's vehicle; booking its appointment books the queued reminders) and
 * Resolved (cancels the vehicle's queued reminders).
 */
#[Route('/admin/service-reminder-queue', name: 'maxeme_reminder_queue_')]
final class ServiceReminderQueueController extends AbstractMaxemeController
{
    public function __construct(
        private readonly ServiceReminderQueueRepository $queue,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function index(Request $request): Response
    {
        return $this->render('maxeme/reminder_queue/index.html.twig', [
            'page' => $this->queue->findPage(ListQuery::fromRequest($request, array_keys(ServiceReminderQueueRepository::SORTS), 'desc')),
            'statuses' => ServiceReminderQueueStatus::cases(),
        ]);
    }

    /** Export CSV: every entry of the current view (search boxes, status, sort), not just the page. */
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, DocumentNumbers $numbers, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $list = ListQuery::fromRequest($request, array_keys(ServiceReminderQueueRepository::SORTS), 'desc');
        $entries = $this->queue->findAllInView($list);
        $zone = new \DateTimeZone($timezone);
        $filename = sprintf('service-reminder-queue-%s.csv', (new \DateTimeImmutable('now', $zone))->format('Y-m-d'));
        $activity->exported('reminder', 'ServiceReminderQueueEntry', $filename, count($entries), $list->describe());

        return CsvExport::response($filename, ['Queued', 'Sent', 'Email Address', 'Phone', 'Client', 'Vehicle', 'Repair Order', 'Repair Order Date', 'Service', 'Reminder Days', 'Due', 'Status', 'Error'], (static function () use ($entries, $numbers, $zone): \Generator {
            foreach ($entries as $entry) {
                $repairOrder = $entry->getRepairOrder();
                yield [
                    $entry->getQueuedAt()->setTimezone($zone)->format('m/d/Y h:i A'),
                    $entry->getSentAt()?->setTimezone($zone)->format('m/d/Y h:i A'),
                    $entry->getEmail(),
                    implode(' / ', $entry->getClient()->getPhones()),
                    $entry->getClient()->getFullName(),
                    trim($entry->getVehicle()->getFullName() . ' ' . $entry->getVehicle()->getLicensePlate()),
                    $repairOrder !== null ? $numbers->repairOrderNumber($repairOrder) : null,
                    $repairOrder?->getCreatedOn()->setTimezone($zone)->format('m/d/Y'),
                    $entry->getServiceName(),
                    $entry->getReminderDays(),
                    $entry->getDueAt()->setTimezone($zone)->format('m/d/Y'),
                    $entry->getStatus()->label(),
                    $entry->getError(),
                ];
            }
        })());
    }

    /** [view email]: the email as it was sent, or as it will be. */
    #[Route('/{id}/email', name: 'email', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function email(#[MapEntity] ServiceReminderQueueEntry $entry, ServiceReminderEmail $email): Response
    {
        return $this->render('maxeme/reminder_queue/email.html.twig', ['entry' => $entry, 'email' => $email->render($entry)]);
    }

    /** [resend]: sends it now, whatever its status. */
    #[Route('/{id}/resend', name: 'resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function resend(#[MapEntity] ServiceReminderQueueEntry $entry, Request $request, ServiceReminderEmail $email): Response
    {
        $sent = $email->send($entry);
        $this->entityManager->flush();

        return $sent
            ? $this->done($request, sprintf('Service reminder sent to %s.', $entry->getEmail()))
            : $this->done($request, sprintf('Could not send the service reminder: %s', $entry->getError()), false);
    }

    /** [Resolved]: dealt with by hand; it and every queued reminder of the client's vehicle will not be sent. */
    #[Route('/{id}/resolve', name: 'resolve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function resolve(#[MapEntity] ServiceReminderQueueEntry $entry, Request $request, ServiceReminderQueue $queue): Response
    {
        $cancelled = count($queue->resolve($entry)) - 1;
        $this->entityManager->flush();

        return $this->done($request, sprintf('Service reminder for %s resolved%s.', $entry->getClient()->getFullName(), $cancelled > 0 ? sprintf('; %d more queued for the vehicle cancelled', $cancelled) : ''));
    }

    /** A form post (with the return field) goes back with a flash; a .js-post-action gets JSON { message }. */
    private function done(Request $request, string $message, bool $ok = true): Response
    {
        if ($request->request->has(self::RETURN_FIELD)) {
            $this->addFlash($ok ? 'success' : 'error', $message);

            return $this->redirectBack($request, 'maxeme_reminder_queue_index');
        }

        return new JsonResponse(['message' => $message], $ok ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
