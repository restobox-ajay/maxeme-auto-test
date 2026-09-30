<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Schedule\CalendarView;
use App\Maxeme\Schedule\ScheduleCalendar;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\AppointmentService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule › Appointments: the shop calendar, its JSON feeds and the event actions (legacy
 * AppointmentController display / feed / time edit / status / delete / client info).
 */
#[Route('/admin/appointments', name: 'maxeme_appointment_')]
final class ScheduleController extends AbstractMaxemeController
{
    /** Flash type for the one-shot alert above the calendar (legacy session flags, e.g. "Invoice not found."). */
    public const ALERT = 'calendar_alert';

    public function __construct(
        private readonly ScheduleCalendar $calendar,
        private readonly AppointmentService $appointments,
    ) {
    }

    #[Route('', name: 'calendar', methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_VIEW)]
    public function index(Request $request, ScheduleSettings $settings): Response
    {
        return $this->render('maxeme/schedule/calendar.html.twig', [
            'view' => CalendarView::fromRequest($request),
            'settings' => $settings,
        ]);
    }

    /** FullCalendar's event source: `start` and `end` (shop dates). */
    #[Route('/feed', name: 'feed', methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_VIEW)]
    public function feed(Request $request): JsonResponse
    {
        return $this->json($this->calendar->events(self::date($request, 'start'), self::date($request, 'end')));
    }

    /** The booking form's day view: every slot with its count. */
    #[Route('/slots', name: 'slots', methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function slots(Request $request): JsonResponse
    {
        return $this->json($this->calendar->slotCounts(self::date($request, 'date')));
    }

    /** The booking form's month view: appointments per day. */
    #[Route('/days', name: 'days', methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function days(Request $request): JsonResponse
    {
        return $this->json($this->calendar->dayCounts(self::date($request, 'start'), self::date($request, 'end')));
    }

    /** Who starts in one slot (`at`, shop time). */
    #[Route('/slot', name: 'slot', methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function slot(Request $request): JsonResponse
    {
        return $this->json($this->calendar->slotAppointments((string) $request->query->get('at', '')));
    }

    /** Dragged or resized: `start` and `end`, shop time. */
    #[Route('/{id}/time', name: 'time', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function time(#[MapEntity] Appointment $appointment, Request $request): JsonResponse
    {
        return $this->act(fn () => $this->appointments->move($appointment, (string) $request->request->get('start'), (string) $request->request->get('end')), $appointment, 'Appointment time saved.');
    }

    #[Route('/{id}/check-in', name: 'check_in', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function checkIn(#[MapEntity] Appointment $appointment): JsonResponse
    {
        return $this->act(fn () => $this->appointments->checkIn($appointment), $appointment, 'Checked in.');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function delete(#[MapEntity] Appointment $appointment): JsonResponse
    {
        try {
            $this->appointments->delete($appointment);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'Appointment deleted.']);
    }

    /** The event strip's client icon: the client's profile, on its Appointments tab. */
    #[Route('/{id}/client', name: 'client', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[RequiresPermission(Permission::APPOINTMENT_VIEW)]
    public function client(#[MapEntity] Appointment $appointment): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $appointment->getClient()->getId(), 'tab' => ClientProfileTab::Appointments->value]);
    }

    /** Runs $change, answering the appointment's updated calendar event, or the refusal. */
    private function act(callable $change, Appointment $appointment, string $message): JsonResponse
    {
        try {
            $change();
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => $message, 'event' => $this->calendar->event($appointment)]);
    }

    /** A Y-m-d query parameter (FullCalendar also sends times and offsets: only the date is used). */
    private static function date(Request $request, string $name): string
    {
        $date = substr((string) $request->query->get($name, ''), 0, 10);

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date) !== false ? $date : (new \DateTimeImmutable())->format('Y-m-d');
    }
}
