<?php

namespace App\Controller\Customer;

use App\Twig\SandboxedTemplateRenderer;
use App\Entity\CustomerUser;
use App\Repository\ApiCredentialRepository;
use App\Service\AppSettings;
use App\Service\CustomerUrlGenerator;
use App\Service\DocumentActor;
use App\Service\ResetTokenService;
use App\Validation\Constraint\ValidCompanyUserRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use App\Service\Email\EmailTemplateRenderer;

#[Route('/company-users')]
final class CompanyUserController extends AbstractCustomerController
{
    #[Route('', name: 'customer_company_users', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, ApiCredentialRepository $apiCredentials): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage company users.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));

        $repo = $entityManager->getRepository(CustomerUser::class);

        $qb = $repo->createQueryBuilder('u')
            ->andWhere('u.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('u.createdAt', 'ASC');

        if ($q !== '') {
            $qb->andWhere('(LOWER(u.email) LIKE :q OR LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q)')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $countQb->select('COUNT(u.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        /** @var list<CustomerUser> $users */
        $users = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Who currently holds an active API key, for the read-only column below. Visible to any
        // viewer so an owner can see at a glance who on the team has API access — but only ever
        // shown, never operated: a key is managed by its owner alone, on /profile/api-key. See #521.
        $apiKeyFlags = $apiCredentials->activeKeyFlagsForUserIds(
            array_values(array_filter(array_map(static fn (CustomerUser $u): ?int => $u->getId(), $users)))
        );

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'html' => $this->renderView('customer/company_user/_list_rows.html.twig', [
                    'users' => $users,
                    'apiKeyFlags' => $apiKeyFlags,
                ]),
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pageCount,
            ]);
        }

        return $this->render('customer/company_user/index.html.twig', [
            'users' => $users,
            'apiKeyFlags' => $apiKeyFlags,
            'q' => $q,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    #[Route('/create', name: 'customer_company_users_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage company users.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $actor->getCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        // Staff may add teammates, but only an owner can mint another owner. The "Owner" option is
        // hidden from the form below for a staff actor; this is the enforcing half, since hiding an
        // <option> stops nothing that posts directly. See #522.
        $actorIsOwner = in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true);

        /** @var array<string, string> $values */
        $values = [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'status' => 'Active',
            'role' => 'Company Staff',
        ];

        /** @var array<string, string> $errors */
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            $values['first_name'] = trim((string) $request->request->get('first_name', ''));
            $values['last_name'] = trim((string) $request->request->get('last_name', ''));
            $values['email'] = trim((string) $request->request->get('email', ''));
            $values['status'] = trim((string) $request->request->get('status', 'Active')) ?: 'Active';
            $values['role'] = trim((string) $request->request->get('role', 'Company Staff')) ?: 'Company Staff';

            $password = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('confirm_password', '');

            if (!in_array($values['status'], ['Active', 'Inactive'], true)) {
                $values['status'] = 'Active';
            }
            if (!in_array($values['role'], ['Company Staff', 'Owner'], true) || !$actorIsOwner) {
                $values['role'] = 'Company Staff';
            }

            $violations = Validation::createValidator()->validate($values['email'], new ValidCompanyUserRequest(
                firstName: $values['first_name'],
                lastName: $values['last_name'],
                password: $password,
                confirmPassword: $confirm,
                passwordRequired: true,
                entityManager: $entityManager,
                emailLookupInsensitive: false,
            ));
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            if ($errors === []) {
                $user = new CustomerUser();
                $user->setCompany($company);
                $user->setFirstName($values['first_name']);
                $user->setLastName($values['last_name']);
                $user->setEmail($values['email']);
                $actor = $this->getUser();
                $user->setStatus($values['status'], $actor instanceof CustomerUser ? DocumentActor::forCustomer($actor) : DocumentActor::system());
                $user->setRoles($values['role'] === 'Owner' ? ['ROLE_COMPANY_OWNER'] : ['ROLE_COMPANY_STAFF']);
                $user->setPassword($passwordHasher->hashPassword($user, $password));

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Company user created.');
                return $this->redirectToRoute('customer_company_users');
            }
        }

        return $this->render('customer/company_user/create.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'actorIsOwner' => $actorIsOwner,
        ]);
    }

    #[Route('/{id<\\d+>}/edit', name: 'customer_company_users_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage company users.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        // Owner-only, and deliberately not "owner-or-self": this form sets role and email, so a staff
        // account editing its own row could promote itself to Owner, and editing anyone else's could
        // repoint their email and then harvest a reset link. Staff edit their own name/password on
        // /profile instead. See #522.
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can manage company users.');
            return $this->redirectToRoute('customer_company_users');
        }

        $user = $entityManager->getRepository(CustomerUser::class)->find($id);
        if (!$user instanceof CustomerUser || $user->getCompany()?->getId() !== $companyId) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('customer_company_users');
        }

        /** @var array<string, string> $values */
        $values = [
            'first_name' => (string) ($user->getFirstName() ?? ''),
            'last_name' => (string) ($user->getLastName() ?? ''),
            'email' => $user->getEmail(),
            'status' => $user->getStatus() ?: 'Active',
            'role' => in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true) ? 'Owner' : 'Company Staff',
            'password' => '',
            'confirm_password' => '',
        ];

        /** @var array<string, string> $errors */
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            $values['first_name'] = trim((string) $request->request->get('first_name', ''));
            $values['last_name'] = trim((string) $request->request->get('last_name', ''));
            $values['email'] = trim((string) $request->request->get('email', ''));
            $values['status'] = trim((string) $request->request->get('status', 'Active')) ?: 'Active';
            $values['role'] = trim((string) $request->request->get('role', 'Company Staff')) ?: 'Company Staff';
            $values['password'] = (string) $request->request->get('password', '');
            $values['confirm_password'] = (string) $request->request->get('confirm_password', '');

            if (!in_array($values['status'], ['Active', 'Inactive'], true)) {
                $values['status'] = 'Active';
            }
            if (!in_array($values['role'], ['Company Staff', 'Owner'], true)) {
                $values['role'] = 'Company Staff';
            }

            $passwordProvided = $values['password'] !== '' || $values['confirm_password'] !== '';

            $violations = Validation::createValidator()->validate($values['email'], new ValidCompanyUserRequest(
                firstName: $values['first_name'],
                lastName: $values['last_name'],
                password: $values['password'],
                confirmPassword: $values['confirm_password'],
                passwordRequired: false,
                entityManager: $entityManager,
                emailLookupInsensitive: true,
                excludeUserId: $user->getId(),
            ));
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            if ($errors === []) {
                $user->setFirstName($values['first_name']);
                $user->setLastName($values['last_name']);
                $user->setEmail($values['email']);
                $actor = $this->getUser();
                $user->setStatus($values['status'], $actor instanceof CustomerUser ? DocumentActor::forCustomer($actor) : DocumentActor::system());
                $user->setRoles($values['role'] === 'Owner' ? ['ROLE_COMPANY_OWNER'] : ['ROLE_COMPANY_STAFF']);

                if ($passwordProvided) {
                    $user->setPassword($passwordHasher->hashPassword($user, $values['password']));
                }

                $entityManager->flush();
                $this->addFlash('success', 'Company user updated.');
                return $this->redirectToRoute('customer_company_users');
            }
        }

        return $this->render('customer/company_user/edit.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'user' => $user,
        ]);
    }

    #[Route('/{id<\\d+>}/reset', name: 'customer_company_users_reset', methods: ['POST'])]
    public function sendResetLink(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        CustomerUrlGenerator $customerUrlGenerator,
        ResetTokenService $resetTokenService,
        \App\Service\AppSettings $appSettings,
    ): Response {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to continue.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        // Owner-only: mailing a password-reset link is a takeover primitive in staff hands — pair it
        // with an email change on /company-users/{id}/edit and it hands over any teammate's account.
        // See #522.
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can send password reset links.');
            return $this->redirectToRoute('customer_company_users');
        }

        $token = (string) $request->request->get('_token', '');
        $user = $entityManager->getRepository(CustomerUser::class)->find($id);
        if (!$user instanceof CustomerUser || $user->getCompany()?->getId() !== $companyId) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('customer_company_users');
        }

        $resetToken = $resetTokenService->generate();
        $user->setResetToken($resetTokenService->hash($resetToken));
        $user->setResetTokenExpiresAt($appSettings->inviteTokenExpiresAt());
        $entityManager->flush();

        $resetUrl = $customerUrlGenerator->generate('customer_password_reset', ['token' => $resetToken]);

        $ctx = [
            'user' => $user,
            'user_email' => $user->getEmail(),
            'reset_url' => $resetUrl,
            // Company-admin-issued, not self-service — the token above uses the invite lifetime,
            // not the 1 hour the template defaults to, so the copy has to say so too. See #450.
            'expiry_description' => $appSettings->inviteTokenExpiryDescription(),
        ];

        $rendered = $emailTemplates->render('forgot_password', $ctx);
        $subject = $rendered?->subject ?? 'Password Reset Request';
        $body = $rendered?->body ?? $this->renderView('emails/forgot_password.html.twig', $ctx);

        $email = $appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to($user->getEmail())
            ->subject($subject)
            ->html($body);

        try {
            $mailer->send($email);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not send reset link right now. Please try again.');
            return $this->redirectToRoute('customer_company_users');
        }

        $this->addFlash('success', 'Password reset link sent to '.$user->getEmail().'.');
        return $this->redirectToRoute('customer_company_users');
    }

    // There is deliberately no delete action here. Removing a teammate is done by setting their
    // status to Inactive on the edit form: reversible, and it keeps the row the company's orders and
    // logs still point at. A hard delete had no legitimate use next to that. See #522.
}
