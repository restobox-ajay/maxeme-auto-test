<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\DbConsoleSession;
use App\Security\ConsoleCookie;
use App\Service\AppSettings;
use App\Service\AuditLogger;
use App\Service\RateLimiterGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Super Admin / Tech Support access to the phpLiteAdmin console (ROLE_SUPER_ADMIN, which Tech Support
 * inherits).
 *
 * The console is off by default and auto-disables after a window, so a stolen admin session cannot
 * simply walk into raw SQL: it has to take the extra, noisy step of switching the console on, which
 * writes an audit row and sends a security alert.
 *
 * This controller only mints the capability. The gateway that actually serves phpLiteAdmin
 * (public/db-admin.php) runs outside the kernel and re-derives every check for itself, so nothing here
 * is load-bearing for authorisation at serve time.
 */
#[Route('/admin/db')]
final class DatabaseConsoleController extends AbstractAdminController
{
    /**
     * The audit action the gateway looks for when binding a console session to an IP. Changing this
     * string without changing public/db-admin.php silently breaks the binding — the gateway would find
     * no row and deny, which fails closed but for the wrong reason.
     */
    public const AUDIT_OPEN = 'db_console_open';
    public const AUDIT_ENABLED = 'db_console_enabled';

    #[Route('', name: 'admin_db_console', methods: ['GET'])]
    public function console(AppSettings $settings): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $remaining = max(0, (int) $settings->get(AppSetting::DB_CONSOLE_ENABLED_UNTIL, '0') - time());

        return $this->render('admin/database/console.html.twig', [
            'enabled' => $remaining > 0,
            'remainingMinutes' => (int) ceil($remaining / 60),
            'windowMinutes' => intdiv(ConsoleCookie::windowSeconds($this->windowMinutes()), 60),
        ]);
    }

    #[Route('/enable', name: 'admin_db_enable', methods: ['POST'])]
    public function enable(
        Request $request,
        AppSettings $settings,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
        MailerInterface $mailer,
        RateLimiterFactoryInterface $dbConsoleLimiter,
        RateLimiterGate $rateLimiterGate,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        /** @var AdminUser $user */
        $user = $this->getUser();

        if ($rateLimiterGate->shouldEnforce($request) && !$dbConsoleLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('error', 'Too many database console actions. Please wait a few minutes and try again.');

            return $this->redirectToRoute('admin_db_console');
        }

        $wasOff = (int) $settings->get(AppSetting::DB_CONSOLE_ENABLED_UNTIL, '0') <= time();
        $window = ConsoleCookie::windowSeconds($this->windowMinutes());

        $this->writeSetting($entityManager, $settings, (string) (time() + $window));

        $auditLogger->log(
            'security',
            'DatabaseConsole',
            null,
            self::AUDIT_ENABLED,
            sprintf('%s switched the database console on for %d minutes', $user->getEmail(), intdiv($window, 60)),
        );
        $entityManager->flush();

        // Only on a real off -> on transition. Extending an already-open window is routine use and
        // should not train the recipients to ignore the alert.
        if ($wasOff) {
            $this->alert($mailer, $settings, 'Database console switched on', $user, $request);
        }

        $this->addFlash('success', sprintf('Database console switched on for %d minutes.', intdiv($window, 60)));

        return $this->redirectToRoute('admin_db_console');
    }

    #[Route('/open', name: 'admin_db_open', methods: ['GET'])]
    public function open(
        Request $request,
        AppSettings $settings,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
        MailerInterface $mailer,
        RateLimiterFactoryInterface $dbConsoleLimiter,
        RateLimiterGate $rateLimiterGate,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ((int) $settings->get(AppSetting::DB_CONSOLE_ENABLED_UNTIL, '0') <= time()) {
            // Force it off rather than leaving a lapsed timestamp lying around, so the stored state
            // matches what the gateway will conclude independently.
            $this->writeSetting($entityManager, $settings, '0');
            $this->addFlash('error', 'The database console is off. Switch it on first.');

            return $this->redirectToRoute('admin_db_console');
        }

        /** @var AdminUser $user */
        $user = $this->getUser();

        if ($rateLimiterGate->shouldEnforce($request) && !$dbConsoleLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('error', 'Too many database console actions. Please wait a few minutes and try again.');

            return $this->redirectToRoute('admin_db_console');
        }

        // Mint a fresh, random, single session credential. Everything the gateway authorises against
        // lives on this row — the hashed token, the owning admin, the expiry, and the opening IP — and
        // it must be flushed before the redirect, because the gateway is a separate process that reads
        // it immediately. The raw token goes only into the cookie below; the DB keeps only its hash.
        $token = ConsoleCookie::generateToken();
        $window = ConsoleCookie::windowSeconds($this->windowMinutes());

        $session = (new DbConsoleSession())
            ->setTokenHash(ConsoleCookie::hashToken($token))
            ->setAdmin($user)
            ->setExpiresAt(new \DateTimeImmutable('@' . (time() + $window)))
            ->setIpAddress((string) $request->getClientIp());
        $entityManager->persist($session);

        // Permanent audit trail of who opened raw SQL access and from where (separate from the session
        // row, which is transient and gets deleted on expiry).
        $auditLogger->log(
            'security',
            'DatabaseConsole',
            $user->getId(),
            self::AUDIT_OPEN,
            sprintf('%s opened the database console', $user->getEmail()),
        );
        $entityManager->flush();

        $this->alert($mailer, $settings, 'Database console opened', $user, $request);

        $response = new RedirectResponse('/db-admin.php');
        $response->headers->setCookie(Cookie::create(
            ConsoleCookie::COOKIE_NAME,
            $token,                     // the opaque random token; only its hash is stored server-side
            0,                          // session cookie; the row's expiry is the real bound
            '/db-admin.php',            // scoped to the gateway, sent nowhere else
            null,
            $request->isSecure(),       // Secure when the request was, so it still works over plain http locally
            true,                       // httponly
            false,
            Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    private function windowMinutes(): string
    {
        return (string) ($_ENV['DB_CONSOLE_WINDOW_MINUTES'] ?? getenv('DB_CONSOLE_WINDOW_MINUTES') ?: '');
    }

    /**
     * AppSettings is read-only and cached, so the row is written directly and the cache dropped —
     * otherwise the console would keep reporting its previous state until the pool expired.
     */
    private function writeSetting(EntityManagerInterface $entityManager, AppSettings $settings, string $value): void
    {
        $row = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => AppSetting::DB_CONSOLE_ENABLED_UNTIL]);

        if (!$row instanceof AppSetting) {
            $row = (new AppSetting())
                ->setSettingKey(AppSetting::DB_CONSOLE_ENABLED_UNTIL)
                ->setName('Database Console Enabled Until')
                ->setDescription('Unix timestamp the raw SQL console stays reachable until. 0 means off.');
            $entityManager->persist($row);
        }

        $row->setSettingValue($value)->touch();
        $entityManager->flush();
        $settings->clearCache();
    }

    /**
     * Addressed to tech_support_email and nowhere else (#351).
     *
     * This alert says someone just took raw SQL access to the installation: who they are, their IP,
     * and when. That is operational intelligence for whoever runs the software, and it is confidential
     * to them — app_email, sales_email and support_email are all the *store owner's* addresses, so
     * falling back to any of them would mail an internal security notice to the customer.
     *
     * So there is deliberately no fallback recipient. tech_support_email resolves through AppSettings,
     * which already reads TECH_SUPPORT_EMAIL / APP_SETTING_TECH_SUPPORT_EMAIL from the environment —
     * that is the operator's channel for setting it at deploy time without touching the store's
     * settings screen. With it unset the mail is simply not sent; the audit row written by the caller
     * remains the durable record.
     */
    private function alert(MailerInterface $mailer, AppSettings $settings, string $subject, AdminUser $user, Request $request): void
    {
        $to = trim((string) $settings->get('tech_support_email', ''));
        if ($to === '') {
            return;
        }

        try {
            $mailer->send(
                $settings->applyFromAddress(new Email(), AppSettings::FROM_TECH_SUPPORT)
                    ->to($to)
                    ->subject($subject)
                    ->text(sprintf(
                        "%s\n\nBy: %s\nIP address: %s\nTime: %s",
                        $subject,
                        $user->getEmail(),
                        $request->getClientIp() ?: 'unknown',
                        (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
                    )),
            );
        } catch (\Throwable) {
            // A failed alert must not block the action or leak a stack trace; the audit row is the
            // durable record either way.
        }
    }
}
