<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Dto\AppointmentData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Schedule\ScheduleSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appointment changes (legacy AppointmentController save / edit / time edit / status / delete).
 * Times arrive in shop time and are stored in UTC.
 *
 * Every method throws \DomainException with a user-facing message when the change is refused.
 */
final class AppointmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduleSettings $settings,
        private readonly InvoiceService $invoices,
    ) {
    }

    /** @param AppointmentData $data already validated */
    public function book(Client $client, AppointmentData $data): Appointment
    {
        [$start, $end] = $this->times((string) $data->start, (string) $data->end);
        $appointment = new Appointment($this->vehicle($client, (int) $data->vehicleId), $start, $end);
        $appointment->setNote($data->note);

        $this->entityManager->persist($appointment);
        $this->entityManager->flush();

        return $appointment;
    }

    /** @param AppointmentData $data already validated */
    public function update(Appointment $appointment, AppointmentData $data): void
    {
        $appointment->changeVehicle($this->vehicle($appointment->getClient(), (int) $data->vehicleId));
        $appointment->reschedule(...$this->times((string) $data->start, (string) $data->end));
        $appointment->setNote($data->note);
        $this->entityManager->flush();
    }

    /** Dragged or resized on the calendar. */
    public function move(Appointment $appointment, string $start, string $end): void
    {
        $appointment->reschedule(...$this->times($start, $end));
        $this->entityManager->flush();
    }

    public function checkIn(Appointment $appointment): void
    {
        $appointment->checkIn();
        $this->entityManager->flush();
    }

    /** Only while it is not complete, as the calendar offers it; its invoice goes with it. */
    public function delete(Appointment $appointment): void
    {
        if (!$appointment->getStatus()->isPending()) {
            throw new \DomainException('A complete appointment cannot be deleted.');
        }
        $this->invoices->deleteFor($appointment);
        $this->entityManager->remove($appointment);
        $this->entityManager->flush();
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} UTC */
    private function times(string $start, string $end): array
    {
        try {
            return [$this->settings->toUtc($start), $this->settings->toUtc($end)];
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException($exception->getMessage(), previous: $exception);
        }
    }

    private function vehicle(Client $client, int $vehicleId): Vehicle
    {
        $vehicle = $this->entityManager->find(Vehicle::class, $vehicleId);
        if (!$vehicle instanceof Vehicle || $vehicle->getClient() !== $client || !$vehicle->isActive()) {
            throw new \DomainException('Choose one of this client\'s vehicles.');
        }

        return $vehicle;
    }
}
