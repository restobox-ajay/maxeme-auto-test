<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ReminderRequest;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Reminder;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\ReminderStatus;
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
 * Schedule › Reminder (legacy ReminderController). Opening the page creates the day's reminders,
 * as in the legacy app; `app:maxeme:generate-reminders` does the same from a scheduler.
 */
final class ReminderController extends AbstractMaxemeController
{
    #[Route('/admin/reminders', name: 'maxeme_reminder_index', methods: ['GET'])]
    #[RequiresPermission(Permission::REMINDER_VIEW)]
    public function index(ReminderGenerator $generator, ReminderRepository $reminders): Response
    {
        $generator->generate();

        return $this->render('maxeme/reminder/index.html.twig', ['reminders' => $reminders->findOpen()]);
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

    /** Resolved (`booked`), Declined or Delayed (with a `note`). */
    #[Route('/admin/reminders/{id}/status', name: 'maxeme_reminder_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::REMINDER_EDIT)]
    public function status(#[MapEntity] Reminder $reminder, Request $request, EntityManagerInterface $entityManager): Response
    {
        $status = ReminderStatus::tryFrom((string) $request->request->get('status', ''));
        if (!in_array($status, [ReminderStatus::Booked, ReminderStatus::Declined, ReminderStatus::Delayed], true)) {
            return $this->json(['message' => 'Choose Resolved, Delayed or Declined.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reminder->changeStatus($status, trim((string) $request->request->get('note', '')) ?: null);
        $entityManager->flush();

        $message = sprintf('Reminder for %s: %s.', $reminder->getClient()->getFullName(), $status === ReminderStatus::Booked ? 'resolved' : $status->value);
        if ($request->request->has(self::RETURN_FIELD)) {
            $this->addFlash('success', $message);

            return $this->redirectBack($request, 'maxeme_reminder_index');
        }

        return new JsonResponse(['message' => $message]);
    }
}
