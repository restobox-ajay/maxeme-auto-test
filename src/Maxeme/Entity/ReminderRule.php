<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

/**
 * A rule that reminds a customer to come back: a number of days after the work was done (an
 * invoice completed), an email template, and a message dropped into the template's {{message}}.
 * ServiceReminder is one per service; sending and the schedule are specified later.
 */
interface ReminderRule
{
    /** Days after the invoice is completed. */
    public function getReminderDays(): int;

    public function getTemplate(): ?ServiceReminderTemplate;

    public function getMessage(): ?string;

    /** The day the reminder is due for work completed at $completedAt. */
    public function dueAfter(\DateTimeImmutable $completedAt): \DateTimeImmutable;

    /** How many reminder emails this rule has sent. */
    public function getEmailsSent(): int;

    public function recordEmailSent(): void;
}
