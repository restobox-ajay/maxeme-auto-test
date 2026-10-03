<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Reminder\ServiceReminderEmail;
use App\Maxeme\Repository\ServiceReminderQueueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Sends the queued service reminders that are due; run it daily from cron. */
#[AsCommand(name: 'app:maxeme:send-service-reminders', description: 'Send the service reminders that are due')]
final class SendServiceRemindersCommand
{
    public function __construct(
        private readonly ServiceReminderQueueRepository $queue,
        private readonly ServiceReminderEmail $email,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $sent = 0;
        $failed = 0;
        foreach ($this->queue->findDue(new \DateTimeImmutable()) as $entry) {
            $this->email->send($entry) ? ++$sent : ++$failed;
        }
        $this->entityManager->flush();

        $io->success(sprintf('%d service reminder(s) sent, %d could not be sent.', $sent, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
