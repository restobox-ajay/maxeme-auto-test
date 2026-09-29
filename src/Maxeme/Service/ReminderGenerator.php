<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Entity\Reminder;
use App\Maxeme\Repository\AppointmentRepository;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\ReminderRepository;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the day's follow-up reminders (legacy ReminderService::generateReminders()): every
 * appointment that started on this shop day `maxeme.reminder_after_months` months ago becomes a
 * reminder for its vehicle, unless the vehicle already has an appointment from today on or a
 * reminder dated today or later. Safe to run again the same day: it finds its own reminders.
 */
final class ReminderGenerator
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ReminderRepository $reminders,
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $appSettings,
        #[Autowire(param: 'maxeme.reminder_after_months')]
        private readonly int $afterMonths,
    ) {
    }

    /** @return int how many reminders were created */
    public function generate(?\DateTimeImmutable $now = null): int
    {
        $utc = new \DateTimeZone('UTC');
        $today = ($now ?? new \DateTimeImmutable())->setTimezone($this->appSettings->timezone())->setTime(0, 0);
        $dayStart = $today->modify(sprintf('-%d months', $this->afterMonths));
        $todayUtc = $today->setTimezone($utc);

        $created = 0;
        foreach ($this->appointments->startingBetween($dayStart->setTimezone($utc), $dayStart->modify('+1 day')->setTimezone($utc)) as $appointment) {
            $vehicle = $appointment->getVehicle();
            if ($this->appointments->hasStartingSince($vehicle, $todayUtc) || $this->reminders->hasSince($vehicle, $todayUtc)) {
                continue;
            }

            $this->entityManager->persist(new Reminder(
                $appointment->getClient(),
                $vehicle,
                $this->invoices->findOneByAppointment($appointment),
                $appointment->getNote(),
                $todayUtc,
            ));
            $this->entityManager->flush();
            ++$created;
        }

        return $created;
    }
}
