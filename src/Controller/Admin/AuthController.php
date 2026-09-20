<?php

namespace App\Controller\Admin;

use App\Twig\SandboxedTemplateRenderer;
use App\Entity\AdminUser;
use App\Security\Csrf\Attribute\CsrfExempt;
use App\Service\AppSettings;
use App\Service\ResetTokenService;
use App\Validation\Dto\PasswordChangeRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Service\Email\EmailTemplateRenderer;

#[Route('/admin')]
final class AuthController extends AbstractAdminController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    #[CsrfExempt(reason: "Login CSRF is enforced by the firewall itself (security.yaml firewalls.admin.form_login: enable_csrf, csrf_token_id 'authenticate'). This controller only re-renders the form after a failed attempt; demanding a second token here would turn a wrong password into a CSRF error.")]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $user = $this->getUser();
        if ($user instanceof AdminUser) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/auth/login.html.twig', [
            'error'         => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/password-reset', name: 'admin_password_reset', methods: ['GET', 'POST'])]
    #[Route('/forgot-password', name: 'admin_forgot_password', methods: ['GET', 'POST'])]
    public function passwordReset(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        ResetTokenService $resetTokenService,
        RateLimiterFactoryInterface $passwordResetRequestLimiter,
        \App\Service\AppSettings $appSettings,
        \App\Service\RateLimiterGate $rateLimiterGate,
    ): Response {
        $token = trim((string) $request->query->get('token', ''));
        if ($token !== '') {
            $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['resetToken' => $resetTokenService->hash($token)]);
            $now = new \DateTimeImmutable();

            $tokenValid = $user instanceof AdminUser
                && $user->getResetTokenExpiresAt() instanceof \DateTimeImmutable
                && $user->getResetTokenExpiresAt() >= $now;

            if ($request->isMethod('POST')) {
                $password = (string) $request->request->get('password', '');
                $confirm = (string) $request->request->get('confirm_password', '');

                if (!$tokenValid) {
                    $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');
                    return $this->redirectToRoute('admin_password_reset');
                }

                if ($password === '' || $confirm === '') {
                    $this->addFlash('error', 'Please enter and confirm your new password.');
                } elseif ($password !== $confirm) {
                    $this->addFlash('error', 'Passwords do not match.');
                } elseif (strlen($password) < 8) {
                    $this->addFlash('error', 'Password must be at least 8 characters long.');
                } else {
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                    $user->setResetToken(null);
                    $user->setResetTokenExpiresAt(null);
                    $entityManager->flush();

                    $this->addFlash('success', 'Password updated successfully. You can now log in.');
                    return $this->redirectToRoute('admin_login');
                }
            }

            if (!$tokenValid && !$request->isMethod('POST')) {
                $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');
            }

            return $this->render('admin/auth/password_reset.html.twig', [
                'token' => $token,
                'tokenValid' => $tokenValid,
                'mode' => 'reset',
                'loginRoute' => 'admin_login',
            ]);
        }

        if ($request->isMethod('POST')) {
            if ($rateLimiterGate->shouldEnforce($request) && !$passwordResetRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many reset requests. Please wait a while before trying again.');
                return $this->redirectToRoute('admin_password_reset');
            }

            $emailAddress = trim((string) $request->request->get('email', ''));
            if ($emailAddress === '') {
                $this->addFlash('error', 'Email is required.');
                return $this->redirectToRoute('admin_password_reset');
            }

            if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
                $this->addFlash('error', 'Please enter a valid email address.');
                return $this->redirectToRoute('admin_password_reset');
            }

            $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $emailAddress]);
            if ($user instanceof AdminUser) {
                $resetToken = $resetTokenService->generate();
                $user->setResetToken($resetTokenService->hash($resetToken));
                // Self-service: the short, configurable window (password_reset_expiry_hours),
                // not the invite lifetime an admin-issued reset gets. See AppSettings.
                $user->setResetTokenExpiresAt($appSettings->passwordResetExpiresAt());
                $entityManager->flush();

                $resetUrl = $this->generateUrl('admin_password_reset', ['token' => $resetToken], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

                $ctx = [
                    'user' => $user,
                    'user_email' => $user->getEmail(),
                    'reset_url' => $resetUrl,
                    // forgot_password is shared with the admin-issued reset, which has a different
                    // lifetime, so the duration has to travel with the context rather than live in
                    // the template. Not optional: the file template and the email_template row both
                    // print it bare, and omitting it is a Twig error under strict_variables — this
                    // endpoint would 500 instead of mailing a link (#475).
                    'expiry_description' => $appSettings->passwordResetExpiryDescription(),
                ];

                $rendered = $emailTemplates->render('forgot_password', $ctx);
                $subject = $rendered?->subject ?? 'Password Reset Request';
                $body = $rendered?->body ?? $this->renderView('emails/forgot_password.html.twig', $ctx);

                try {
                    $mailer->send(
                        $appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
                            ->to($user->getEmail())
                            ->subject($subject)
                            ->html($body)
                    );
                } catch (\Exception $e) {
                }
            }

            $this->addFlash('success', 'If an admin account exists for that email, a password reset link has been sent.');
            return $this->redirectToRoute('admin_password_reset');
        }

        return $this->render('admin/auth/password_reset.html.twig', [
            'token' => null,
            'tokenValid' => false,
            'mode' => 'request',
            'loginRoute' => 'admin_login',
        ]);
    }

    #[Route('/setup-account', name: 'admin_account_setup', methods: ['GET', 'POST'])]
    public function accountSetup(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ResetTokenService $resetTokenService,
    ): Response {
        $token = trim((string) $request->query->get('token', ''));
        if ($token === '') {
            $this->addFlash('error', 'This setup link is invalid.');
            return $this->redirectToRoute('admin_password_reset');
        }

        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['resetToken' => $resetTokenService->hash($token)]);
        $now = new \DateTimeImmutable();
        $tokenValid = $user instanceof AdminUser
            && $user->getResetTokenExpiresAt() instanceof \DateTimeImmutable
            && $user->getResetTokenExpiresAt() >= $now;

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('confirm_password', '');

            if (!$tokenValid) {
                $this->addFlash('error', 'This setup link is invalid or has expired. Please request a new one.');
                return $this->redirectToRoute('admin_password_reset');
            }

            if ($password === '' || $confirm === '') {
                $this->addFlash('error', 'Please enter and confirm your new password.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'Passwords do not match.');
            } elseif (strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                $entityManager->flush();

                $this->addFlash('success', 'Account setup complete. You can now log in.');
                return $this->redirectToRoute('admin_login');
            }
        }

        if (!$tokenValid && !$request->isMethod('POST')) {
            $this->addFlash('error', 'This setup link is invalid or has expired. Please request a new one.');
        }

        return $this->render('admin/auth/password_reset.html.twig', [
            'token' => $token,
            'tokenValid' => $tokenValid,
            'mode' => 'invite',
            'loginRoute' => 'admin_login',
        ]);
    }

    #[Route('/profile', name: 'admin_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, ValidatorInterface $validator): Response
    {
        /** @var AdminUser $admin */
        $admin = $this->getUser();

        $errors = [];
        $values = [
            'display_name' => trim(implode(' ', array_filter([$admin->getFirstName(), $admin->getLastName()]))) ?: 'Admin',
            'phone_number' => $admin->getPhoneNumber() ?? '',
        ];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            $formType = (string) $request->request->get('form_type', 'profile');

            if ($formType === 'profile') {
                $values['display_name'] = trim((string) $request->request->get('display_name', ''));
                $values['phone_number'] = trim((string) $request->request->get('phone_number', ''));

                $violations = $validator->validate($values['display_name'], new Assert\NotBlank(message: 'Display name is required.'));
                if (count($violations) > 0) {
                    $errors['display_name'] = (string) $violations[0]->getMessage();
                }

                if ($errors === []) {
                    [$firstName, $lastName] = $this->splitDisplayName($values['display_name']);
                    $admin->setFirstName($firstName);
                    $admin->setLastName($lastName);
                    $admin->setPhoneNumber($values['phone_number'] !== '' ? $values['phone_number'] : null);
                    $entityManager->flush();

                    $this->addFlash('success', 'Profile updated.');
                    return $this->redirectToRoute('admin_profile');
                }
            }

            if ($formType === 'password') {
                $currentPassword = (string) $request->request->get('current_password', '');
                $newPassword = (string) $request->request->get('new_password', '');
                $confirmNewPassword = (string) $request->request->get('confirm_new_password', '');

                $passwordChange = new PasswordChangeRequest($admin, $currentPassword, $newPassword, $confirmNewPassword);
                $errors = array_merge($errors, PasswordChangeRequest::fieldErrors($validator->validate($passwordChange)));

                if ($errors === []) {
                    $admin->setPassword($passwordHasher->hashPassword($admin, $newPassword));
                    $entityManager->flush();

                    $this->addFlash('success', 'Password updated.');
                    return $this->redirectToRoute('admin_profile');
                }
            }
        }

        return $this->render('admin/_main/profile.html.twig', [
            'admin' => $admin,
            'errors' => $errors,
            'values' => $values,
            'roleLabel' => $this->roleLabelForAdmin($admin),
        ]);
    }

    private function splitDisplayName(string $displayName): array
    {
        $parts = preg_split('/\s+/', trim($displayName), 2) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));
        $lastName = trim((string) ($parts[1] ?? ''));

        return [$firstName !== '' ? $firstName : null, $lastName !== '' ? $lastName : null];
    }

    private function roleLabelForAdmin(AdminUser $admin): string
    {
        $roles = $admin->getRoles();
        if (in_array('ROLE_TECH_SUPPORT', $roles, true)) {
            return 'Tech Support';
        }
        if (in_array('ROLE_SUPER_ADMIN', $roles, true) || in_array('ROLE_SUPERADMIN', $roles, true)) {
            return 'Super Admin';
        }
        if (in_array('ROLE_PLANT_STAFF', $roles, true)) {
            return 'Plant Staff';
        }

        return 'Administrator';
    }
}
