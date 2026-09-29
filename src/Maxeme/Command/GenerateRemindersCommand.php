<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Service\ReminderGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Creates today's follow-up reminders; run it daily from cron so the list is ready before anyone opens it. */
#[AsCommand(name: 'app:maxeme:generate-reminders', description: 'Create today\'s follow-up reminders')]
final class GenerateRemindersCommand
{
    public function __construct(
        private readonly ReminderGenerator $generator,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(sprintf('%d reminder(s) created.', $this->generator->generate()));

        return Command::SUCCESS;
    }
}
