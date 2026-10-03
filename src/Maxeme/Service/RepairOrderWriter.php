<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AdminUser;
use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\RepairOrderCalculator;
use App\Maxeme\Dto\RepairOrderAppointmentData;
use App\Maxeme\Dto\DocumentChargeData;
use App\Maxeme\Dto\RepairOrderData;
use App\Maxeme\Dto\RepairOrderForm;
use App\Maxeme\Dto\RepairOrderJobData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\RepairOrderJobLine;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\Technician;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Schedule\ScheduleSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Saves the repair order page: checks everything first (each message keyed by the field it is
 * about), and only when nothing is wrong applies it all and saves in one flush, recalculating
 * the totals. Nothing is written when anything is refused.
 */
final class RepairOrderWriter
{
    /** A new appointment's length. */
    private const APPOINTMENT_LENGTH = 'PT1H';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ServiceLineBuilder $lineBuilder,
        private readonly RepairOrderCalculator $calculator,
        private readonly InvoiceSettings $taxRates,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    /** A new, unsaved repair order: today's tax rates, the person starting it as advisor, and the customer when known. */
    /** A new repair order, for $client (and $vehicle, when it is one of the client's) if given. */
    public function start(?AdminUser $advisor, ?Client $client = null, ?Vehicle $vehicle = null): RepairOrder
    {
        $repairOrder = new RepairOrder($this->taxRates->gstRate(), $this->taxRates->pstRate());
        $repairOrder->setAdvisor($advisor);
        if ($client !== null) {
            $repairOrder->setCustomer($client, $vehicle !== null && $vehicle->getClient() === $client && $vehicle->isActive() ? $vehicle : null);
        }

        return $repairOrder;
    }

    /** @return array<string, string> field => message; empty when it was saved */
    public function save(RepairOrder $repairOrder, RepairOrderForm $form): array
    {
        $data = $form->data;
        $errors = FieldErrors::from($this->validator->validate($data));

        [$client, $clientErrors] = $this->client($form);
        [$vehicle, $vehicleErrors] = $this->vehicle($form, $client);
        $errors += $clientErrors + $vehicleErrors;

        $advisor = $data->advisorId !== null ? $this->entityManager->find(AdminUser::class, (int) $data->advisorId) : null;
        if ($data->advisorId !== null && $advisor === null) {
            $errors['advisorId'] = 'Choose an advisor from the list.';
        }
        $technician = $data->masterTechnicianId !== null ? $this->entityManager->find(Technician::class, (int) $data->masterTechnicianId) : null;
        if ($data->masterTechnicianId !== null && $technician === null) {
            $errors['masterTechnicianId'] = 'Choose a master technician from the list.';
        }

        [$jobs, $jobErrors] = $this->jobs($repairOrder, $form->jobs);
        [$charges, $chargeErrors] = $this->charges($repairOrder, $form->charges);
        $errors += $jobErrors + $chargeErrors;

        $appointmentErrors = $this->checkAppointments($repairOrder, $form->appointments, $client, $vehicle);
        $errors += $appointmentErrors;

        if ($errors !== []) {
            return $errors;
        }

        // A customer or vehicle typed in on the page is created with it.
        foreach ([$client, $vehicle] as $new) {
            if ($new !== null && $new->getId() === null) {
                $this->entityManager->persist($new);
            }
        }

        $data->applyTo($repairOrder);
        $repairOrder
            ->setStatus(RepairOrderStatus::from($data->status ?? $repairOrder->getStatus()->value))
            ->setCustomer($client, $vehicle)
            ->setAdvisor($advisor)
            ->setMasterTechnician($technician);
        $repairOrder->replaceJobs($jobs);
        $repairOrder->replaceCharges($charges);
        $this->calculator->apply($repairOrder);

        $this->entityManager->persist($repairOrder);
        $this->applyAppointments($repairOrder, $form->appointments, $vehicle);
        $this->entityManager->flush();

        return [];
    }

    /** An appointment's Check-in: the car is here; work starts if it had not. */
    public function checkIn(Appointment $appointment): void
    {
        $appointment->checkIn();
        $repairOrder = $appointment->getRepairOrder();
        if ($repairOrder !== null && $repairOrder->getStatus()->isBeforeWork()) {
            $repairOrder->setStatus(RepairOrderStatus::InProgress);
            $repairOrder->touch();
        }
        $this->entityManager->flush();
    }

    /** @return array{0: ?Client, 1: array<string, string>} */
    private function client(RepairOrderForm $form): array
    {
        $id = $form->data->clientId;
        if ($id === null) {
            return [null, []];
        }
        if ($id === RepairOrderData::NEW) {
            $errors = self::prefixed('new_client.', FieldErrors::from($this->validator->validate($form->newClient)));
            if ($form->newClient->firstName === null && $form->newClient->lastName === null) {
                $errors['new_client.firstName'] = 'Enter the new customer\'s name.';
            }
            $client = new Client();
            if ($errors === []) {
                $form->newClient->applyTo($client);
            }

            return [$client, $errors];
        }

        $client = $this->entityManager->getRepository(Client::class)->findOneBy(['id' => (int) $id, 'active' => true]);

        return $client !== null ? [$client, []] : [null, ['clientId' => 'Choose a customer from the list.']];
    }

    /** @return array{0: ?Vehicle, 1: array<string, string>} */
    private function vehicle(RepairOrderForm $form, ?Client $client): array
    {
        $id = $form->data->vehicleId;
        if ($id === null) {
            return [null, []];
        }
        if ($client === null) {
            return [null, ['vehicleId' => 'Choose the customer first, then their vehicle.']];
        }
        if ($id === RepairOrderData::NEW) {
            $errors = self::prefixed('new_vehicle.', FieldErrors::from($this->validator->validate($form->newVehicle)));
            if ($form->newVehicle->manufacturer === null && $form->newVehicle->model === null && $form->newVehicle->licensePlate === null && $form->newVehicle->vin === null) {
                $errors['new_vehicle.model'] = 'Enter the new vehicle\'s make, model, licence plate or VIN.';
            }
            $vehicle = new Vehicle($client);
            if ($errors === []) {
                $form->newVehicle->applyTo($vehicle);
            }

            return [$vehicle, $errors];
        }

        $vehicle = $this->entityManager->getRepository(Vehicle::class)->findOneBy(['id' => (int) $id, 'active' => true]);
        if ($vehicle === null || $vehicle->getClient() !== $client) {
            return [null, ['vehicleId' => 'Choose one of the customer\'s vehicles.']];
        }

        return [$vehicle, []];
    }

    /**
     * @param list<RepairOrderJobData> $rows
     *
     * @return array{0: list<RepairOrderJob>, 1: array<string, string>}
     */
    private function jobs(RepairOrder $repairOrder, array $rows): array
    {
        $existing = [];
        foreach ($repairOrder->getJobs() as $job) {
            $existing[(string) $job->getId()] = $job;
        }

        $jobs = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $rowErrors = FieldErrors::from($this->validator->validate($row));
            $service = null;
            if ($row->serviceId !== null && !isset($rowErrors['serviceId'])) {
                $service = $this->entityManager->find(ServiceItem::class, (int) $row->serviceId);
                if ($service === null) {
                    $rowErrors['serviceId'] = 'Choose a service from the list.';
                }
            }
            foreach ($rowErrors as $property => $message) {
                $errors[sprintf('jobs.%d.%s', $n, $property)] = sprintf('Service %d: %s', $n + 1, $message);
            }

            $job = $existing[(string) $row->id] ?? new RepairOrderJob($repairOrder);
            $built = $this->lineBuilder->build(
                $job->getLines(),
                $row->lines,
                static fn (ServiceLineType $type): RepairOrderJobLine => new RepairOrderJobLine($job, $type),
                sprintf('jobs.%d.lines', $n),
                sprintf('Service %d, line', $n + 1),
            );
            $errors += $built['errors'];
            if ($rowErrors !== [] || $built['errors'] !== []) {
                continue;
            }

            $job->setService($service)->setName((string) $row->name)->setPrice((string) Money::rounded($row->price));
            $job->replaceLines($built['lines']);
            $jobs[] = $job;
        }

        return [$jobs, $errors];
    }

    /**
     * @param list<DocumentChargeData> $rows
     *
     * @return array{0: list<RepairOrderCharge>, 1: array<string, string>}
     */
    private function charges(RepairOrder $repairOrder, array $rows): array
    {
        $existing = [];
        foreach ($repairOrder->getCharges() as $charge) {
            $existing[(string) $charge->getId()] = $charge;
        }

        $charges = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $rowErrors = FieldErrors::from($this->validator->validate($row));
            foreach ($rowErrors as $property => $message) {
                $errors[sprintf('charges.%d.%s', $n, $property)] = sprintf('Fee / discount %d: %s', $n + 1, $message);
            }
            $kind = $row->getKind();
            if ($rowErrors !== [] || $kind === null) {
                continue;
            }

            $charge = $existing[(string) $row->id] ?? new RepairOrderCharge($repairOrder, $kind);
            $charges[] = $charge->setKind($kind)->setLabel((string) $row->label)->setAmount((string) Money::rounded($row->amount));
        }

        return [$charges, $errors];
    }

    /**
     * Appointments need the vehicle, belong to the repair order's customer, and only upcoming ones
     * change; past ones are not posted.
     *
     * @param list<RepairOrderAppointmentData> $rows
     *
     * @return array<string, string>
     */
    private function checkAppointments(RepairOrder $repairOrder, array $rows, ?Client $client, ?Vehicle $vehicle): array
    {
        $errors = [];
        foreach ($repairOrder->getAppointments() as $appointment) {
            if ($appointment->getClient() !== $client) {
                $errors['clientId'] = 'This repair order has appointments for its customer; delete them before changing the customer.';
                break;
            }
        }

        foreach ($rows as $n => $row) {
            foreach (FieldErrors::from($this->validator->validate($row)) as $property => $message) {
                $errors[sprintf('appointments.%d.%s', $n, $property)] = sprintf('Appointment %d: %s', $n + 1, $message);
            }
            if ($row->id !== null && $this->appointment($repairOrder, $row)?->isPast() !== false) {
                $errors[sprintf('appointments.%d.start', $n)] = sprintf('Appointment %d: a past appointment cannot be changed.', $n + 1);
            }
            if ($vehicle === null) {
                $errors[sprintf('appointments.%d.start', $n)] = sprintf('Appointment %d: choose the vehicle before booking an appointment.', $n + 1);
            }
        }

        return $errors;
    }

    /** @param list<RepairOrderAppointmentData> $rows already checked */
    private function applyAppointments(RepairOrder $repairOrder, array $rows, ?Vehicle $vehicle): void
    {
        if ($vehicle === null) {
            return;
        }
        // The repair order's upcoming appointments follow it to another of the customer's vehicles.
        foreach ($repairOrder->getAppointments() as $appointment) {
            if (!$appointment->isPast() && $appointment->getVehicle() !== $vehicle) {
                $appointment->changeVehicle($vehicle);
            }
        }

        foreach ($rows as $row) {
            $start = $this->schedule->toUtc((string) $row->start);
            $appointment = $row->id !== null ? $this->appointment($repairOrder, $row) : null;
            if ($appointment === null) {
                $appointment = new Appointment($vehicle, $start, $start->add(new \DateInterval(self::APPOINTMENT_LENGTH)));
                $appointment->attachTo($repairOrder);
                $this->entityManager->persist($appointment);
            } else {
                $appointment->reschedule($start, $start->add(new \DateInterval(sprintf('PT%dS', $appointment->getEndTime()->getTimestamp() - $appointment->getStartTime()->getTimestamp()))));
            }
            $appointment->setPromisedAt($row->promised !== null ? $this->schedule->toUtc($row->promised) : null);
        }
    }

    private function appointment(RepairOrder $repairOrder, RepairOrderAppointmentData $row): ?Appointment
    {
        foreach ($repairOrder->getAppointments() as $appointment) {
            if ((string) $appointment->getId() === $row->id) {
                return $appointment;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private static function prefixed(string $prefix, array $errors): array
    {
        $keyed = [];
        foreach ($errors as $property => $message) {
            $keyed[$prefix . $property] = $message;
        }

        return $keyed;
    }
}
