<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\Reminder;
use App\Maxeme\Enum\ReminderStatus;
use App\Maxeme\Repository\ReminderRepository;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\ReminderGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Schedule › Reminder (legacy ReminderController). Opening the page creates the day's reminders,
 * as in the legacy app; `app:maxeme:generate-reminders` does the same from a scheduler.
 */
#[IsGranted(StaffRole::STAFF)]
final class ReminderController extends AbstractMaxemeController
{
    #[Route('/admin/reminders', name: 'maxeme_reminder_index', methods: ['GET'])]
    public function index(ReminderGenerator $generator, ReminderRepository $reminders): Response
    {
        $generator->generate();

        return $this->render('maxeme/reminder/index.html.twig', ['reminders' => $reminders->findOpen()]);
    }

    /** Resolved (`booked`), Declined or Delayed (with a `note`). */
    #[Route('/admin/reminders/{id}/status', name: 'maxeme_reminder_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(StaffRole::MANAGER)]
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
