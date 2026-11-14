<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AdminUser;
use App\Maxeme\Dto\AppointmentData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
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
        private readonly RepairOrderWriter $repairOrders,
    ) {
    }

    /**
     * A new appointment. A repair-order appointment must pick one of the client's open repair orders
     * or make a new one (mockup 25368c7): the new repair order is for the same client and vehicle,
     * with the note as its internal comment.
     *
     * @param AppointmentData $data already validated
     */
    public function book(Client $client, AppointmentData $data, ?AdminUser $user = null): Appointment
    {
        $type = $data->appointmentType();
        [$start, $end] = $this->times((string) $data->start, (string) $data->end);
        $appointment = new Appointment($client, $this->vehicle($client, $data->vehicleId), $start, $end);
        $appointment->setType($type);
        $appointment->setNote($data->note);

        if ($type->hasRepairOrder()) {
            if ($data->repairOrderId === null) {
                throw new \DomainException('Choose the repair order this appointment is for, or "+ Create new repair order".');
            }
            if ($data->repairOrderId === AppointmentData::NEW_REPAIR_ORDER) {
                if ($appointment->getVehicle() === null) {
                    throw new \DomainException('Choose the vehicle before making a repair order for it.');
                }
                $repairOrder = $this->repairOrders->start($user, $client, $appointment->getVehicle());
                $repairOrder->setConcern($data->note);
                $this->entityManager->persist($repairOrder);
                $appointment->attachTo($repairOrder);
            } else {
                $this->useRepairOrder($appointment, $data);
            }
        }

        $this->entityManager->persist($appointment);
        $this->entityManager->flush();

        return $appointment;
    }

    /** @param AppointmentData $data already validated */
    public function update(Appointment $appointment, AppointmentData $data): void
    {
        $appointment->changeVehicle($this->vehicle($appointment->getClient(), $data->vehicleId, $appointment->getVehicle()));
        $appointment->reschedule(...$this->times((string) $data->start, (string) $data->end));
        $appointment->setNote($data->note);
        $appointment->setType($data->appointmentType());
        if ($data->appointmentType()->hasRepairOrder()) {
            $this->useRepairOrder($appointment, $data);
        }
        if ($data->status !== null) {
            $status = AppointmentStatus::from($data->status);
            // Extended means the vehicle has to stay longer: only once it has arrived (mockup 551ab32).
            if ($status === AppointmentStatus::Extended && !in_array($appointment->getStatus(), [AppointmentStatus::InProgress, AppointmentStatus::Extended], true)) {
                throw new \DomainException('Extended is only for a vehicle that has arrived: check it in (In Progress) first.');
            }
            $appointment->setStatus($status);
        }
        $this->entityManager->flush();
    }

    /** Dragged or resized on the calendar. */
    public function move(Appointment $appointment, string $start, string $end): void
    {
        $appointment->reschedule(...$this->times($start, $end));
        $this->entityManager->flush();
    }

    /** The car is here: the appointment is In Progress, and its repair order's work starts (as on the repair order page). */
    public function checkIn(Appointment $appointment): void
    {
        $this->repairOrders->checkIn($appointment);
    }

    /** Only while it is not completed; its invoice goes with it. */
    public function delete(Appointment $appointment): void
    {
        if ($appointment->getStatus() === AppointmentStatus::Complete) {
            throw new \DomainException('A completed appointment cannot be deleted.');
        }
        $this->invoices->deleteFor($appointment);
        $this->entityManager->remove($appointment);
        $this->entityManager->flush();
    }

    /** The repair order picked on the form: one of the client's, for the appointment's vehicle, or none. */
    private function useRepairOrder(Appointment $appointment, AppointmentData $data): void
    {
        if ($data->repairOrderId === null || $data->repairOrderId === AppointmentData::NEW_REPAIR_ORDER) {
            $appointment->detachRepairOrder();

            return;
        }
        $repairOrder = $this->entityManager->find(RepairOrder::class, (int) $data->repairOrderId);
        if ($repairOrder === null || $repairOrder->getClient() !== $appointment->getClient()) {
            throw new \DomainException("Choose one of the client's repair orders.");
        }
        if ($repairOrder->getVehicle() !== $appointment->getVehicle()) {
            throw new \DomainException('That repair order is for another vehicle; choose the vehicle it is for, or another repair order.');
        }
        $appointment->attachTo($repairOrder);
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

    /** One of the client's active vehicles, or the one the appointment already has even if no longer active; null for none. */
    private function vehicle(Client $client, ?string $vehicleId, ?Vehicle $current = null): ?Vehicle
    {
        if ($vehicleId === null) {
            return null;
        }
        $vehicle = $this->entityManager->find(Vehicle::class, (int) $vehicleId);
        if (!$vehicle instanceof Vehicle || $vehicle->getClient() !== $client || (!$vehicle->isActive() && $vehicle !== $current)) {
            throw new \DomainException('Choose one of this client\'s vehicles.');
        }

        return $vehicle;
    }
}
