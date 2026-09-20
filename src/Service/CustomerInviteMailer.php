<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\AppSettings;
use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use App\Service\Email\EmailTemplateRenderer;

final class CustomerInviteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly EmailTemplateRenderer $emailTemplates,
        private readonly CustomerUrlGenerator $customerUrlGenerator,
        private readonly ResetTokenService $resetTokenService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AppSettings $appSettings,
    ) {
    }

    public function send(AdminUser|CustomerUser $user, EntityManagerInterface $entityManager): bool
    {
        try {
            $token = $this->resetTokenService->generate();
            $user->setResetToken($this->resetTokenService->hash($token));
            $user->setResetTokenExpiresAt($this->appSettings->inviteTokenExpiresAt());
            $entityManager->flush();

            $ctx = [
                'user' => $user,
                'user_email' => $user->getEmail(),
                'reset_url' => $user instanceof CustomerUser
                    ? $this->customerUrlGenerator->generate('customer_account_setup', ['token' => $token])
                    : $this->urlGenerator->generate('admin_account_setup', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'expiry_description' => $this->appSettings->inviteTokenExpiryDescription(),
            ];

            $rendered = $this->emailTemplates->render('new_user_invited', $ctx)
                ?? $this->emailTemplates->render('invite', $ctx);
            $subject = $rendered?->subject ?? 'Invitation to ' . $this->appSettings->siteName();
            $body = $rendered?->body ?? $this->twig->render('emails/invite.html.twig', $ctx);

            $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
                ->to($user->getEmail())
                ->subject($subject)
                ->html($body);

            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
