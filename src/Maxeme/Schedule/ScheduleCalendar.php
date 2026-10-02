<?php

declare(strict_types=1);

namespace App\Maxeme\Schedule;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Repository\AppointmentRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The JSON the appointment calendar and the booking form's calendar read (legacy
 * AppointmentController feeds), in shop time: FullCalendar is given local times without an offset.
 */
final class ScheduleCalendar
{
    private const LOCAL = 'Y-m-d\TH:i:s';

    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ScheduleSettings $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Calendar events for [$start, $end) (shop dates), legacy convertAppointmentToArray().
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $start, string $end): array
    {
        return array_map(
            $this->event(...),
            $this->appointments->startingBetween($this->settings->dayStartsAt($start), $this->settings->dayStartsAt($end)),
        );
    }

    /**
     * One event: "[status] vehicle", the customer, the phone and the description (the repair order's
     * short description, else the appointment's note) on lines of their own; coloured by its
     * service (or its own colour), else by its status.
     *
     * @return array<string, mixed>
     */
    public function event(Appointment $appointment): array
    {
        $client = $appointment->getClient();
        $id = ['id' => $appointment->getId()];
        $description = $appointment->getRepairOrder()?->getName() ?: $appointment->getNote();
        $lines = [
            sprintf('[%s] %s', $appointment->getStatus()->label(), $appointment->getVehicle()->getFullName()),
            $client->getFullName(),
            (string) $client->getPhone1(),
            sprintf('Description: %s', $description),
        ];
        $colour = $appointment->getCalendarColour();

        return [
            'id' => (string) $appointment->getId(),
            'title' => implode(' ', $lines),
            'start' => $this->local($appointment->getStartTime()),
            'end' => $this->local($appointment->getEndTime()),
            'classNames' => ['appt-' . $appointment->getStatus()->value],
            ...($colour !== null ? ['backgroundColor' => $colour, 'borderColor' => $colour] : []),
            'extendedProps' => [
                'status' => $appointment->getStatus()->value,
                'lines' => $lines,
                'urls' => [
                    'client' => $this->urls->generate('maxeme_appointment_client', $id),
                    'invoice' => $this->urls->generate('maxeme_invoice_for_appointment', $id),
                    'time' => $this->urls->generate('maxeme_appointment_time', $id),
                    'delete' => $this->urls->generate('maxeme_appointment_delete', $id),
                ],
            ],
        ];
    }

    /**
     * Every slot of $date's working day with how many appointments start in it (legacy
     * getAppointmentCountsInTimeslots, which only counted exact start matches; this counts any start
     * inside the slot).
     *
     * @return list<array{title: string, start: string, end: string, count: int}>
     */
    public function slotCounts(string $date): array
    {
        $zone = $this->settings->timezone();
        $slot = new \DateTimeImmutable($date . ' ' . $this->settings->dayStart, $zone);
        $close = new \DateTimeImmutable($date . ' ' . $this->settings->dayEnd, $zone);
        $step = new \DateInterval(sprintf('PT%dM', $this->settings->slotMinutes));

        $counts = [];
        foreach ($this->appointments->startingBetween($slot->setTimezone(new \DateTimeZone('UTC')), $close->setTimezone(new \DateTimeZone('UTC'))) as $appointment) {
            $key = $this->slotOf($this->settings->toLocal($appointment->getStartTime()));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $slots = [];
        for (; $slot < $close; $slot = $slot->add($step)) {
            $slots[] = [
                'title' => $slot->format('g:i A'),
                'start' => $slot->format(self::LOCAL),
                'end' => $slot->add($step)->format(self::LOCAL),
                'count' => $counts[$slot->format(self::LOCAL)] ?? 0,
            ];
        }

        return $slots;
    }

    /**
     * How many appointments start on each day of [$start, $end) (legacy getAppointmentCountsInDays).
     *
     * @return list<array{title: string, start: string, allDay: true}>
     */
    public function dayCounts(string $start, string $end): array
    {
        $days = [];
        foreach ($this->appointments->startingBetween($this->settings->dayStartsAt($start), $this->settings->dayStartsAt($end)) as $appointment) {
            $day = $this->settings->toLocal($appointment->getStartTime())->format('Y-m-d');
            $days[$day] = ($days[$day] ?? 0) + 1;
        }

        $events = [];
        foreach ($days as $day => $count) {
            $events[] = ['title' => (string) $count, 'start' => $day, 'allDay' => true];
        }

        return $events;
    }

    /**
     * Who starts in the slot beginning at $slotStart (shop time), legacy getEventsStartOnDatetime.
     *
     * @return list<array{name: string, vehicle: string, start: string, end: string}>
     */
    public function slotAppointments(string $slotStart): array
    {
        $from = $this->settings->toUtc($slotStart);
        $to = $from->add(new \DateInterval(sprintf('PT%dM', $this->settings->slotMinutes)));

        return array_map(fn (Appointment $appointment): array => [
            'name' => $appointment->getClient()->getFullName(),
            'vehicle' => $appointment->getVehicle()->getFullName(),
            'start' => $this->settings->toLocal($appointment->getStartTime())->format('h:i A'),
            'end' => $this->settings->toLocal($appointment->getEndTime())->format('h:i A'),
        ], $this->appointments->startingBetween($from, $to));
    }

    private function local(\DateTimeImmutable $time): string
    {
        return $this->settings->toLocal($time)->format(self::LOCAL);
    }

    /** The start of the slot $time falls in, as a local calendar string. */
    private function slotOf(\DateTimeImmutable $time): string
    {
        $minutes = (int) $time->format('i');

        return $time->setTime((int) $time->format('H'), $minutes - $minutes % $this->settings->slotMinutes)->format(self::LOCAL);
    }
}
