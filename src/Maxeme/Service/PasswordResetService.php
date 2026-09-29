<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Maxeme\Security\PasswordResetOutcome;
use App\Repository\AdminUserRepository;
use App\Service\AppSettings;
use App\Service\ResetTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The self-service password reset, following the legacy FOSUserBundle flow: request by username
 * or email → one emailed link per validity window → choose a new password.
 *
 * Tokens are core's (ResetTokenService: only the hash is stored) and the window is core's
 * password_reset_expiry_hours setting, which app:maxeme:setup sets to the legacy 24 hours.
 *
 * Unlike the legacy app, resetting does not re-enable a de-activated account.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetTokenService $tokens,
        private readonly AppSettings $appSettings,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly StaffAccountService $accounts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{PasswordResetOutcome, ?AdminUser} */
    public function request(string $usernameOrEmail): array
    {
        $user = $this->users->loadUserByIdentifier($usernameOrEmail);
        if (!$user instanceof AdminUser || $user->getStatus() !== AdminUserStatus::Active->value) {
            return [PasswordResetOutcome::UnknownUser, null];
        }

        if ($this->hasValidToken($user)) {
            return [PasswordResetOutcome::AlreadyRequested, $user];
        }

        $token = $this->tokens->generate();
        $user->setResetToken($this->tokens->hash($token));
        $user->setResetTokenExpiresAt($this->appSettings->passwordResetExpiresAt());
        $this->entityManager->flush();

        $this->sendEmail($user, $token);

        return [PasswordResetOutcome::Sent, $user];
    }

    /** The account a link belongs to, or null when no account has that token at all. */
    public function findByToken(string $token): ?AdminUser
    {
        return $this->users->findOneBy(['resetToken' => $this->tokens->hash($token)]);
    }

    public function hasValidToken(AdminUser $user): bool
    {
        return $user->getResetToken() !== null
            && $user->getResetTokenExpiresAt() !== null
            && $user->getResetTokenExpiresAt() > new \DateTimeImmutable();
    }

    public function reset(AdminUser $user, string $plainPassword): void
    {
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $this->accounts->changePassword($user, $plainPassword);
    }

    public function windowHours(): int
    {
        return $this->appSettings->passwordResetExpiryHours();
    }

    private function sendEmail(AdminUser $user, string $token): void
    {
        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to($user->getEmail())
            ->subject('Reset Password')
            ->text($this->twig->render('maxeme/emails/password_reset.txt.twig', [
                'user' => $user,
                'confirmationUrl' => $this->urls->generate('maxeme_resetting_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Password reset email could not be sent.', ['user' => $user->getId(), 'exception' => $exception]);
        }
    }
}
