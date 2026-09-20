<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\Email\EmailTemplateRenderer;
use Twig\Environment;

/**
 * Shared "resolved email template, else fallback Twig view" sender used by every
 * transactional email in the app. Failures never bubble up — the triggering action
 * (checkout, status change, etc.) must never fail because notification email did.
 */
final class EmailNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly EmailTemplateRenderer $emailTemplates,
        private readonly AppSettings $appSettings,
    ) {
    }

    /**
     * $fromCategory has no default — every caller has to consciously say which kind of mail this
     * is (AppSettings::FROM_SALES / FROM_SUPPORT) rather than silently inheriting whatever a
     * previous edit happened to default to. All categories resolve to the same sender since #474;
     * an unrecognised one still throws, so the choice stays a real one.
     *
     * @param array<string, mixed> $context
     * @param list<?string> $recipients
     */
    public function send(string $templateCode, string $fallbackSubject, string $fallbackView, array $context, array $recipients, string $fromCategory): void
    {
        $recipients = array_values(array_unique(array_filter(
            array_map(static fn (?string $r): string => trim((string) $r), $recipients),
            static fn (string $r): bool => $r !== '',
        )));
        if ($recipients === []) {
            return;
        }

        try {
            // The fallback view is now effectively unreachable for any shipped code, since those
            // resolve from ShippedEmailTemplates with no row present (#507). Kept because a caller
            // may still pass a code that ships nothing, and because removing the argument is a
            // wider change than this one.
            $rendered = $this->emailTemplates->render($templateCode, $context);
            $subject = $rendered?->subject ?? $fallbackSubject;
            $body = $rendered?->body ?? $this->twig->render($fallbackView, $context);

            $this->mailer->send(
                $this->appSettings->applyFromAddress(new Email(), $fromCategory)
                    ->to(...$recipients)
                    ->subject($subject)
                    ->html($body)
            );
        } catch (\Throwable) {
            // Never block the triggering action on email failure.
        }
    }

    /** @return list<string> */
    public function activeAdminEmails(): array
    {
        $admins = $this->entityManager->getRepository(AdminUser::class)->findBy(['status' => 'Active']);

        $emails = [];
        foreach ($admins as $admin) {
            $email = strtolower(trim($admin->getEmail()));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * Company::$primaryEmail is an optional, admin-filled marketing/billing contact field —
     * plenty of real companies never have it set. Falling back to $fallbackEmail (usually the
     * CustomerUser who triggered the event, when the caller has one handy) and then to any
     * active CustomerUser on the company means a "your request was received" email never
     * silently vanishes just because that one optional field is blank.
     *
     * @return list<string>
     */
    public function companyRecipientEmails(Company $company, ?string $fallbackEmail = null): array
    {
        $primary = trim((string) $company->getPrimaryEmail());
        if ($primary !== '') {
            return [$primary];
        }

        $fallback = trim((string) $fallbackEmail);
        if ($fallback !== '') {
            return [$fallback];
        }

        $users = $this->entityManager->getRepository(CustomerUser::class)->findBy(['company' => $company, 'status' => 'Active']);

        $emails = [];
        foreach ($users as $user) {
            $email = strtolower(trim($user->getEmail()));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * Issue #185: an order confirmation goes to BOTH the ordering customer user's email AND the
     * company's primary email, deduplicated case-insensitively (so an identical pair sends only
     * one copy). Either address may be blank, in which case the other stands alone. If both are
     * blank we fall back to any active CustomerUser on the company so the confirmation never
     * silently vanishes.
     *
     * @return list<string>
     */
    public function orderConfirmationRecipients(Company $company, ?string $userEmail = null): array
    {
        $emails = [];
        foreach ([$userEmail, $company->getPrimaryEmail()] as $candidate) {
            $email = strtolower(trim((string) $candidate));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }

        if ($emails !== []) {
            return array_values($emails);
        }

        return $this->companyRecipientEmails($company);
    }
}
