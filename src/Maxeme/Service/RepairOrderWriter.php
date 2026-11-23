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
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\RepairOrderJobLine;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Enum\WorkStatus;
use App\Maxeme\Staff\Technicians;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\MileageUnit;
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
    /** A new appointment's length when none is typed, in hours (mockup 2371126). */
    private const APPOINTMENT_HOURS = 2.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ServiceLineBuilder $lineBuilder,
        private readonly RepairOrderCalculator $calculator,
        private readonly InvoiceSettings $taxRates,
        private readonly ScheduleSettings $schedule,
        private readonly Technicians $technicians,
        private readonly RepairOrderStatusChange $statusChange,
        private readonly DocumentNumbers $numbers,
        private readonly RecordRules $rules,
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

    /** The key of save()'s errors that means "the status move needs a yes": the page shows the confirm box. */
    public const CONFIRM_MOVE = '_confirmMove';

    /** @return array<string, string> field => message; empty when it was saved */
    public function save(RepairOrder $repairOrder, RepairOrderForm $form): array
    {
        $data = $form->data;
        // Paid, so locked (mockup ee7a843): only notes, which are saved elsewhere.
        if ($repairOrder->isLocked()) {
            return ['status' => sprintf('%s is paid, so it is locked. Mark its invoice unpaid or cancelled first (a refund) and it unlocks.', $this->numbers->repairOrderNumber($repairOrder))];
        }
        $errors = FieldErrors::from($this->validator->validate($data));
        $to = RepairOrderStatus::tryFrom((string) $data->status) ?? $repairOrder->getStatus();
        $from = $repairOrder->getStatus();
        $errors += $this->statusRules($repairOrder, $to, $form->confirmMove);
        $before = self::fingerprint($repairOrder);
        $savedTotals = [$repairOrder->getSubtotal(), $repairOrder->getChargeTotal(), $repairOrder->getGst(), $repairOrder->getPst(), $repairOrder->getTotal()];

        [$client, $clientErrors] = $this->client($form, $repairOrder);
        [$vehicle, $vehicleErrors] = $this->vehicle($form, $client, $repairOrder);
        $errors += $clientErrors + $vehicleErrors;

        // The advisor is no longer on the page (mockup 986d771): it stays the person who started it.
        try {
            $technician = $this->technicians->pick($data->masterTechnicianId, $repairOrder->getMasterTechnician());
        } catch (\DomainException) {
            $technician = null;
            $errors['masterTechnicianId'] = 'Choose an assigned technician from the list.';
        }

        // Every repair order is for a customer and one of their vehicles (audit fix 34181b0).
        if ($client === null && !isset($errors['clientId'])) {
            $errors['clientId'] = 'Choose the customer, or add a new one.';
        }
        if ($vehicle === null && !isset($errors['vehicleId'])) {
            $errors['vehicleId'] = 'Choose the vehicle, or add a new one.';
        }
        // The pickup address: one of the customer's address book, or none (mockup a38e7a2).
        $pickup = null;
        if ($data->pickupAddressId !== null && $client !== null && $client->getId() !== null) {
            $pickup = $this->entityManager->find(ClientAddress::class, (int) $data->pickupAddressId);
            if ($pickup === null || $pickup->getClient() !== $client) {
                $pickup = null;
                $errors['pickupAddressId'] = 'Choose one of the customer\'s addresses, or none.';
            }
        }
        // Started from an appointment that has no repair order (?appointment=): it becomes this one's.
        $fromAppointment = $repairOrder->getId() === null && $form->fromAppointmentId !== null ? $this->entityManager->find(Appointment::class, (int) $form->fromAppointmentId) : null;
        if ($fromAppointment !== null && ($fromAppointment->getRepairOrder() !== null || !$fromAppointment->getType()->hasRepairOrder() || ($client !== null && $fromAppointment->getClient() !== $client))) {
            $fromAppointment = null;
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
        // Into Invoiced from Completed / Picked Up keeps which it was (to go back to on a refund).
        if (!($to === RepairOrderStatus::Invoiced && $from !== $to && $repairOrder->followPaidInvoice(true))) {
            $repairOrder->setStatus($to);
        }
        $repairOrder
            ->setCustomer($client, $vehicle)
            ->setPickupAddress($pickup)
            ->setMasterTechnician($technician);
        $repairOrder->replaceJobs($jobs);
        $repairOrder->replaceCharges($charges);
        if (!$this->calculator->apply($repairOrder)) {
            return ['charges' => "A discount can't be more than the subtotal plus fees: it would take the total below zero."];
        }
        // Finished work saved without touching its services, fees or rates keeps the money it was saved
        // with (audit fix 34181b0): a later change in a tax class or price never re-prices it.
        if ($from->isWorkDone() && $to->isWorkDone() && self::fingerprint($repairOrder) === $before) {
            $repairOrder->setTotals(...$savedTotals);
        }

        $this->entityManager->persist($repairOrder);
        if ($fromAppointment !== null && $vehicle !== null) {
            if ($fromAppointment->getVehicle() !== $vehicle) {
                $fromAppointment->changeVehicle($vehicle);
            }
            $fromAppointment->attachTo($repairOrder);
            $fromAppointment->setTechnician($repairOrder->getMasterTechnician() ?? $fromAppointment->getTechnician());
        }
        $this->applyAppointments($repairOrder, $form->appointments, $vehicle);
        self::updateVehicleMileage($repairOrder, $vehicle);
        $this->entityManager->flush();

        return [];
    }

    /**
     * The repair order's Odometer In is kept on the vehicle (as the invoice form does, legacy
     * setupVehicleInfo) when it is higher than the vehicle's reading, so the vehicle page and the
     * reminders show the latest mileage. A lower reading (a typo, an older repair order saved again)
     * leaves the vehicle as it is; a vehicle with no reading, or free text, takes it.
     */
    private static function updateVehicleMileage(RepairOrder $repairOrder, ?Vehicle $vehicle): void
    {
        $reading = self::mileageNumber($repairOrder->getMileage());
        if ($vehicle === null || $reading === null) {
            return;
        }
        $current = self::mileageNumber($vehicle->getMileage());
        if ($current !== null && $vehicle->getMileageUnit() !== $repairOrder->getMileageUnit()) {
            // Compared in the repair order's unit: 1 mile = 1.609344 km.
            $current = $repairOrder->getMileageUnit() === MileageUnit::Km ? $current * 1.609344 : $current / 1.609344;
        }
        if ($current !== null && $reading <= $current) {
            return;
        }
        $vehicle
            ->setMileage($repairOrder->getMileage())
            ->setMileageUnit($repairOrder->getMileageUnit());
    }

    /** "120000", "120,000" or "120000.5" as a number; null for blank or free text ("unknown"). */
    private static function mileageNumber(?string $mileage): ?float
    {
        $text = str_replace(',', '', trim((string) $mileage));

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? (float) $text : null;
    }

    /**
     * The status rules of a save (the board's, RepairOrderStatusChange): a new repair order cannot start
     * Invoiced or Completed, Picked Up (it has no invoice); a saved one is refused or asked first as on
     * the board.
     *
     * @return array<string, string>
     */
    private function statusRules(RepairOrder $repairOrder, RepairOrderStatus $to, bool $confirmed): array
    {
        if ($repairOrder->getId() === null) {
            return match ($to) {
                RepairOrderStatus::Invoiced => ['status' => 'A new repair order has no paid invoice, so it cannot be Invoiced. Issue an invoice and mark it Paid: the repair order then becomes Invoiced by itself.'],
                RepairOrderStatus::CompletedPickedUp => ['status' => 'A new repair order has no invoice yet. Save it, issue the invoice, then mark it Completed, Picked Up.'],
                default => [],
            };
        }
        try {
            $question = $this->statusChange->confirmationFor($repairOrder, $to);
        } catch (\DomainException $exception) {
            return ['status' => $exception->getMessage()];
        }

        return $question !== null && !$confirmed ? ['status' => $question, self::CONFIRM_MOVE => '1'] : [];
    }

    /** What its money is made of: services, their lines, fees / discounts and rates. */
    private static function fingerprint(RepairOrder $repairOrder): string
    {
        $number = static fn (?string $value): string => sprintf('%.4f', (float) $value);
        $jobs = array_map(static fn (RepairOrderJob $job): array => [
            $job->getService()?->getId(), $job->getName(), $number($job->getPrice()),
            array_map(static fn (RepairOrderJobLine $line): array => [$line->getType()->value, $line->getItemId(), $number($line->getQuantity()), $number($line->getUnitPrice()), $line->isChargeThrough()], $job->getLines()),
        ], $repairOrder->getJobs());
        $charges = array_map(static fn (RepairOrderCharge $charge): array => [$charge->getKind()->value, $number($charge->getAmount())], $repairOrder->getCharges());

        return (string) json_encode([$repairOrder->getGstRate(), $repairOrder->getPstRate(), $jobs, $charges]);
    }

    /** An appointment's Check-in: the car is here; work starts if it had not. */
    public function checkIn(Appointment $appointment): void
    {
        $appointment->assertUnlocked();
        $appointment->checkIn();
        $repairOrder = $appointment->getRepairOrder();
        if ($repairOrder !== null && $repairOrder->getStatus()->isBeforeWork()) {
            $repairOrder->setStatus(RepairOrderStatus::InProgress);
            $repairOrder->touch();
        }
        $this->entityManager->flush();
    }

    /** @return array{0: ?Client, 1: array<string, string>} */
    private function client(RepairOrderForm $form, RepairOrder $repairOrder): array
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
            if ($errors === [] && ($duplicate = $this->rules->clientDuplicate($form->newClient)) !== null) {
                $errors['new_client.email'] = $duplicate;
            }
            $client = new Client();
            if ($errors === []) {
                $form->newClient->applyTo($client);
            }

            return [$client, $errors];
        }

        // Its own customer stays even once deleted (a deleted client keeps their repair orders); a new pick must be active.
        $saved = $repairOrder->getClient();
        $client = $saved !== null && $saved->getId() === (int) $id
            ? $saved
            : $this->entityManager->getRepository(Client::class)->findOneBy(['id' => (int) $id, 'active' => true]);

        return $client !== null ? [$client, []] : [null, ['clientId' => 'Choose a customer from the list.']];
    }

    /** @return array{0: ?Vehicle, 1: array<string, string>} */
    private function vehicle(RepairOrderForm $form, ?Client $client, RepairOrder $repairOrder): array
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
            if ($errors === [] && ($taken = $this->rules->vinTaken($form->newVehicle->vin)) !== null) {
                $errors['new_vehicle.vin'] = $taken;
            }
            $vehicle = new Vehicle($client);
            if ($errors === []) {
                $form->newVehicle->applyTo($vehicle);
            }

            return [$vehicle, $errors];
        }

        $saved = $repairOrder->getVehicle();
        $vehicle = $saved !== null && $saved->getId() === (int) $id
            ? $saved
            : $this->entityManager->getRepository(Vehicle::class)->findOneBy(['id' => (int) $id, 'active' => true]);
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
            $jobTechnician = null;
            if (!isset($rowErrors['technicianId'])) {
                try {
                    $jobTechnician = $this->technicians->pick($row->technicianId, $job->getTechnician());
                } catch (\DomainException $exception) {
                    $errors[sprintf('jobs.%d.technicianId', $n)] = sprintf('Service %d: %s', $n + 1, $exception->getMessage());
                    continue;
                }
            }
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

            $job->setService($service)->setName((string) $row->name)->setPrice((string) Money::rounded($row->price))
                ->setTechnician($jobTechnician)
                ->setWorkStatus(WorkStatus::tryFrom((string) $row->workStatus) ?? WorkStatus::NotStarted);
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
            $seconds = $row->duration !== null
                ? (int) round((float) $row->duration * 3600)
                : ($appointment !== null ? $appointment->getEndTime()->getTimestamp() - $appointment->getStartTime()->getTimestamp() : (int) (self::APPOINTMENT_HOURS * 3600));
            $end = $start->add(new \DateInterval(sprintf('PT%dS', $seconds)));
            if ($appointment === null) {
                $appointment = new Appointment($vehicle->getClient(), $vehicle, $start, $end);
                $appointment->attachTo($repairOrder);
                $this->entityManager->persist($appointment);
            } else {
                $appointment->reschedule($start, $end);
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
