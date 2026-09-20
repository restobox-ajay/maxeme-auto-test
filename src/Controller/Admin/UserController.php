<?php

namespace App\Controller\Admin;

use App\Twig\SandboxedTemplateRenderer;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Service\AppSettings;
use App\Service\AuditLogger;
use App\Service\CustomerInviteMailer;
use App\Service\CustomerUrlGenerator;
use App\Service\DocumentActor;
use App\Service\ResetTokenService;
use App\Validation\Constraint\ValidUserRequest;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validation;
use App\Service\Email\EmailTemplateRenderer;

#[Route('/admin/user')]
final class UserController extends AbstractAdminController
{
    private const ROLE_ADMIN = 'Admin';
    private const ROLE_SUPERADMIN = 'Super Admin';
    private const ROLE_SUPERADMIN_LEGACY = 'Superadmin';
    private const ROLE_PLANT_STAFF = 'Plant Staff';
    private const ROLE_TECH_SUPPORT = 'Tech Support';
    private const ROLE_SALES_REP = 'Sales Rep';
    private const ROLE_COMPANY_OWNER = 'Owner';
    private const ROLE_COMPANY_STAFF = 'Company Staff';
    private const STAFF_ROLE_OPTIONS = [self::ROLE_SUPERADMIN, self::ROLE_ADMIN, self::ROLE_PLANT_STAFF, self::ROLE_SALES_REP, self::ROLE_TECH_SUPPORT];

    public function __construct(private readonly \App\Service\AppSettings $appSettings)
    {
    }

    #[Route('', name: 'admin_user_short', methods: ['GET'])]
    #[Route('/index', name: 'admin_user_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        // Combined "All users" view is deprecated: keep routes for backwards compatibility.
        // Default to Staff users page.
        return $this->redirectToRoute('admin_user_staff_index', $request->query->all());
    }

    #[Route('/staff', name: 'admin_user_staff_index', methods: ['GET'])]
    public function staffIndex(EntityManagerInterface $entityManager, Request $request): Response
    {
        return $this->indexForType('staff', $entityManager, $request);
    }

    #[Route('/customer', name: 'admin_user_customer_index', methods: ['GET'])]
    public function customerIndex(EntityManagerInterface $entityManager, Request $request): Response
    {
        return $this->indexForType('customer', $entityManager, $request);
    }

    private function indexForType(string $group, EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $rows = [];
        if ($group === 'staff') {
            $actor = $this->getUser();
            $viewerIsTechSupport = $actor instanceof AdminUser && $this->roleLabelForUser($actor) === self::ROLE_TECH_SUPPORT;

            $admins = $entityManager->getRepository(AdminUser::class)->findAll();
            foreach ($admins as $admin) {
                $roleLabel = $this->roleLabelForUser($admin);
                if ($roleLabel === self::ROLE_TECH_SUPPORT && !$viewerIsTechSupport) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) $admin->getId(),
                    'firstName' => $admin->getFirstName() ?? '',
                    'lastName' => $admin->getLastName() ?? '',
                    'name' => trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? '')) ?: '-',
                    'email' => $admin->getEmail(),
                    'phone' => $admin->getPhoneNumber() ?? '',
                    'role' => $roleLabel,
                    'type' => 'Admin',
                    'company' => '-',
                    'status' => $admin->getStatus(),
                ];
            }

        } else {
            $customers = $this->customerUsersWithCompanies($entityManager);
            foreach ($customers as $customer) {
                $roleLabel = $this->roleLabelForUser($customer);
                $rows[] = [
                    'id' => (string) $customer->getId(),
                    'firstName' => $customer->getFirstName() ?? '',
                    'lastName' => $customer->getLastName() ?? '',
                    'name' => trim(($customer->getFirstName() ?? '') . ' ' . ($customer->getLastName() ?? '')) ?: '-',
                    'email' => $customer->getEmail(),
                    'phone' => $customer->getPhoneNumber() ?? '',
                    'role' => $roleLabel,
                    'type' => 'Customer',
                    'company' => $customer->getCompany()?->getName() ?? 'Missing customer',
                    'companyId' => (string) ($customer->getCompany()?->getId() ?? ''),
                    'status' => $customer->getStatus(),
                ];
            }
        }

        if ($search) {
            $search = strtolower($search);
            $rows = array_filter($rows, function ($row) use ($search, $group) {
                return str_contains(strtolower($row['name']), $search)
                    || str_contains(strtolower($row['email']), $search)
                    || ($group === 'customer' && str_contains(strtolower((string) ($row['company'] ?? '')), $search));
            });
        }

        /** @var list<string> $roleOptions */
        $roleOptions = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['role'] ?? '')),
            array_values($rows)
        ))));
        usort($roleOptions, static fn (string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)));

        $rows = array_filter($rows, function (array $row) use ($filters, $group): bool {
            $contains = static function (string $haystack, string $needle): bool {
                return $needle === '' || str_contains(strtolower($haystack), strtolower($needle));
            };

            if ($group === 'customer') {
                $company = trim((string) ($filters['company'] ?? ''));
                if (!$contains((string) ($row['company'] ?? ''), $company)) {
                    return false;
                }
            }

            $firstName = trim((string) ($filters['firstName'] ?? ''));
            if (!$contains((string) ($row['firstName'] ?? ''), $firstName)) {
                return false;
            }

            $lastName = trim((string) ($filters['lastName'] ?? ''));
            if (!$contains((string) ($row['lastName'] ?? ''), $lastName)) {
                return false;
            }

            $email = trim((string) ($filters['email'] ?? ''));
            if (!$contains((string) ($row['email'] ?? ''), $email)) {
                return false;
            }

            $phone = trim((string) ($filters['phone'] ?? ''));
            if (!$contains((string) ($row['phone'] ?? ''), $phone)) {
                return false;
            }

            $id = trim((string) ($filters['id'] ?? ''));
            if (!$contains((string) ($row['id'] ?? ''), $id)) {
                return false;
            }

            $role = trim((string) ($filters['role'] ?? ''));
            if ($role !== '' && trim((string) ($row['role'] ?? '')) !== $role) {
                return false;
            }

            $status = trim((string) ($filters['status'] ?? ''));
            if ($status !== '' && trim((string) ($row['status'] ?? '')) !== $status) {
                return false;
            }

            return true;
        });

        $sort = trim((string) $request->query->get('sort', $group === 'customer' ? 'company' : 'firstName'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? -1 : 1;
        $allowed = $group === 'customer'
            ? ['company', 'firstName', 'lastName', 'email', 'phone', 'role', 'status', 'id']
            : ['firstName', 'lastName', 'email', 'phone', 'role', 'status', 'id'];
        if (!in_array($sort, $allowed, true)) {
            $sort = $group === 'customer' ? 'company' : 'firstName';
        }

        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
            $av = (string) ($a[$sort] ?? '');
            $bv = (string) ($b[$sort] ?? '');

            if ($sort === 'id') {
                return $dir * ((int) $av <=> (int) $bv);
            }

            return $dir * strcmp(mb_strtolower($av), mb_strtolower($bv));
        });

        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'html' => $this->renderView('admin/user/_list_rows.html.twig', ['users' => $rows, 'userGroup' => $group]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        $meta = $group === 'staff'
            ? ['title' => 'Staff Users', 'lead' => 'Manage admin staff users and account status.']
            : ['title' => 'Customer Users', 'lead' => 'Manage customer users, customer access, roles, and account status.'];

        return $this->render('admin/user/index.html.twig', [
            'users' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'userGroup' => $group,
            'title' => $meta['title'],
            'lead' => $meta['lead'],
            'roleOptions' => $roleOptions,
            'ajaxUrl' => $this->generateUrl($group === 'staff' ? 'admin_user_staff_index' : 'admin_user_customer_index'),
            'createUrl' => $this->generateUrl($group === 'staff' ? 'admin_user_staff_create' : 'admin_user_customer_create'),
            'createLabel' => 'Add User',
            'companyCreateUrl' => $group === 'customer' ? $this->generateUrl('admin_company_create', ['return_to' => 'customer_users']) : null,
            'companyCreateLabel' => 'Add Customer',
        ]);
    }

    #[Route('/create', name: 'admin_user_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Combined create is deprecated. Keep route for backwards compatibility.
        return $this->redirectToRoute('admin_user_staff_create');
    }

    #[Route('/staff/create', name: 'admin_user_staff_create', methods: ['GET', 'POST'])]
    public function staffCreate(Request $request, EntityManagerInterface $entityManager, CustomerInviteMailer $inviteMailer): Response
    {
        $actor = $this->requireStaffManager();
        if (!$actor instanceof AdminUser) {
            return $this->redirectToRoute('admin_user_staff_index');
        }

        $roleOptions = $this->staffRoleOptionsForActor($actor);

        if ($request->isMethod('POST')) {
            $role = $this->normalizeRoleLabel((string) $request->request->get('role', self::ROLE_ADMIN));

            $errors = $this->validateUserRequest($request, $entityManager, null, $roleOptions, 'admin');
            if ($errors === []) {
                $user = $this->createUserEntityForRole($role);
                $this->applyUserRequest($user, $request, $entityManager);
                $this->applySelectedRole($user, $role);
                $user->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));

                $entityManager->persist($user);
                $entityManager->flush();
                $this->sendInviteIfRequestedAndReport($request, $user, $entityManager, $inviteMailer);
            } else {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/user/form.html.twig', [
                    'mode' => 'Create',
                    'userGroup' => 'staff',
                    'roleOptions' => $roleOptions,
                    'roleEditable' => true,
                    'user' => $this->userFormRowFromRequest($request),
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirectToRoute('admin_user_staff_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'mode' => 'Create',
            'user' => ['id' => '', 'email' => '', 'firstName' => '', 'lastName' => '', 'phoneNumber' => '', 'status' => 'Active', 'companyId' => '', 'role' => self::ROLE_ADMIN, 'type' => 'Admin', 'salesRepEligible' => false],
            'userGroup' => 'staff',
            'roleOptions' => $roleOptions,
            'roleEditable' => true,
            'companies' => $this->companyRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/customer/create', name: 'admin_user_customer_create', methods: ['GET', 'POST'])]
    public function customerCreate(Request $request, EntityManagerInterface $entityManager, CustomerInviteMailer $inviteMailer): Response
    {
        $roleOptions = [self::ROLE_COMPANY_OWNER, self::ROLE_COMPANY_STAFF];

        if ($request->isMethod('POST')) {
            $role = $this->normalizeRoleLabel((string) $request->request->get('role', self::ROLE_COMPANY_STAFF));

            $errors = $this->validateUserRequest($request, $entityManager, null, $roleOptions, 'customer');
            if ($errors === []) {
                $user = $this->createUserEntityForRole($role);
                $this->applyUserRequest($user, $request, $entityManager);
                $this->applySelectedRole($user, $role);
                $user->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));

                $entityManager->persist($user);
                $entityManager->flush();
                $this->sendInviteIfRequestedAndReport($request, $user, $entityManager, $inviteMailer);
            } else {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/user/form.html.twig', [
                    'mode' => 'Create',
                    'userGroup' => 'customer',
                    'roleOptions' => $roleOptions,
                    'user' => $this->userFormRowFromRequest($request),
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirectToRoute('admin_user_customer_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'mode' => 'Create',
            'user' => ['id' => '', 'email' => '', 'firstName' => '', 'lastName' => '', 'phoneNumber' => '', 'status' => 'Active', 'companyId' => '', 'role' => self::ROLE_COMPANY_OWNER, 'type' => 'Customer'],
            'userGroup' => 'customer',
            'roleOptions' => $roleOptions,
            'companies' => $this->companyRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/staff/update/{id}', name: 'admin_user_staff_update', methods: ['GET', 'POST'])]
    public function staffUpdate(int $id, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator): Response
    {
        return $this->update('admin', $id, $request, $entityManager, $mailer, $emailTemplates, $customerUrlGenerator);
    }

    #[Route('/customer/update/{id}', name: 'admin_user_customer_update', methods: ['GET', 'POST'])]
    public function customerUpdate(int $id, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator): Response
    {
        return $this->update('customer', $id, $request, $entityManager, $mailer, $emailTemplates, $customerUrlGenerator);
    }
    #[Route('/update/{type}/{id}', name: 'admin_user_update', methods: ['GET', 'POST'], requirements: ['type' => 'admin|customer', 'id' => '\\d+'])]
    public function update(string $type, int $id, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator): Response
    {
        $class = $this->userClassForType($type);
        $user = $entityManager->find($class, $id);
        $group = $this->userGroupForType($type);
        $this->denyIfHiddenTechSupport($user);

        if (!$user) {
            $this->addFlash('error', 'User could not be found.');
            return $this->redirectToRoute($group === 'staff' ? 'admin_user_staff_index' : 'admin_user_customer_index');
        }

        if ($group === 'staff' && $user instanceof AdminUser) {
            $blockedResponse = $this->denyIfCannotManageStaffTarget($user);
            if ($blockedResponse instanceof Response) {
                return $blockedResponse;
            }
        }

        $roleOptions = $group === 'staff'
            ? $this->staffRoleOptionsForTarget($user)
            : [self::ROLE_COMPANY_OWNER, self::ROLE_COMPANY_STAFF];
        // Customer roles are editable: Owner vs Company Staff is a real distinction an admin needs to
        // change. It was hard-coded false, so the select rendered permanently disabled and a hidden
        // field posted the current value back — a control that looked editable and did nothing. Staff
        // keep their own rule, which depends on who is editing whom. See issue #92.
        $roleEditable = $group === 'staff' ? $this->canEditStaffRole($user) : true;

        if ($request->isMethod('POST')) {
            // A staff email is the login identifier for the admin firewall, so this screen neither
            // offers the field nor looks at it: any address in the request is ignored outright, not
            // merely overwritten. Customers may legitimately change theirs, and that path notifies the
            // previous address.
            $emailEditable = $group !== 'staff';
            $email = $emailEditable ? trim((string) $request->request->get('email', '')) : (string) $user->getEmail();
            $previousStatus = method_exists($user, 'getStatus') ? trim((string) $user->getStatus()) : '';
            $previousEmail = $user->getEmail();

            // Pass the offered options so a role outside them is rejected rather than silently
            // normalised — the customer branch previously validated no role at all.
            $errors = $this->validateUserRequest($request, $entityManager, $user, $roleOptions, $type, $emailEditable);

            $requestedRole = $roleEditable ? $this->normalizeRoleLabel((string) $request->request->get('role', $this->roleLabelForUser($user))) : null;
            if ($requestedRole !== null && $requestedRole !== self::ROLE_SUPERADMIN && $user instanceof AdminUser && $this->isTheOnlyActiveSuperAdmin($entityManager, $user)) {
                $errors[] = 'This account is the only active Super Admin. Promote another Super Admin before changing this role.';
            }

            if ($errors === []) {
                if ($roleEditable) {
                    $this->applySelectedRole($user, $requestedRole);
                }
                $this->applyUserRequest($user, $request, $entityManager, $emailEditable);
                $activationEmail = false;
                if ($user instanceof CustomerUser && trim((string) $user->getStatus()) === 'Active' && strcasecmp($previousStatus, 'Active') !== 0) {
                    $companyMessage = $this->approveCustomerCompanyOnActivation($user, $entityManager);
                    $activationEmail = true;
                }
                $emailChanged = $group === 'customer' && $user instanceof CustomerUser && strcasecmp($previousEmail, $user->getEmail()) !== 0;
                $notifyEmailChange = $request->request->get('notify_email_change', 'yes') === 'yes';
                $entityManager->flush();
                $this->addFlash('success', sprintf('User "%s" was updated successfully.', $email));
                if (isset($companyMessage) && $companyMessage) {
                    $this->addFlash('success', $companyMessage);
                }
                if ($activationEmail) {
                    try {
                        $this->sendCustomerApprovalEmail($user, $entityManager, $mailer, $emailTemplates, $customerUrlGenerator);
                    } catch (\Throwable) {
                        $this->addFlash('warning', sprintf('User "%s" was activated, but the approval email could not be sent.', $email));
                    }
                }
                if ($emailChanged && $notifyEmailChange && $user instanceof CustomerUser) {
                    try {
                        $this->sendEmailChangedNotification($previousEmail, $user, $entityManager, $mailer, $emailTemplates);
                    } catch (\Throwable) {
                        $this->addFlash('warning', 'The email address was updated, but the change notification could not be sent to the previous address.');
                    }
                }
            } else {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/user/form.html.twig', [
                    'mode' => 'Update',
                    'user' => $this->userFormRowFromRequest($request, $user, $type) + ['type' => ucfirst(strtolower($type))],
                    'userGroup' => $group,
                    'roleOptions' => $roleOptions,
                    'roleEditable' => $roleEditable,
                    'companies' => $this->companyRowsFromDatabase($entityManager),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirectToRoute($group === 'staff' ? 'admin_user_staff_index' : 'admin_user_customer_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'mode' => 'Update',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => method_exists($user, 'getFirstName') ? ($user->getFirstName() ?? '') : '',
                'lastName' => method_exists($user, 'getLastName') ? ($user->getLastName() ?? '') : '',
                'phoneNumber' => method_exists($user, 'getPhoneNumber') ? ($user->getPhoneNumber() ?? '') : '',
                'status' => method_exists($user, 'getStatus') ? $user->getStatus() : 'Active',
                'companyId' => $user instanceof CustomerUser ? (string) ($user->getCompany()?->getId() ?? '') : '',
                'role' => $this->roleLabelForUser($user),
                'type' => ucfirst(strtolower($type)),
                // Must be carried, not defaulted. applyUserRequest() writes apiEnabled from the
                // checkbox, and an unticked checkbox submits nothing — so a row that omits this
                // renders the box unchecked regardless of the stored value, and saving an unrelated
                // field silently revokes the user's API access.
                'apiEnabled' => $user instanceof CustomerUser && $user->isApiEnabled(),
                'companyApiEnabled' => $user instanceof CustomerUser && ($user->getCompany()?->isApiEnabled() ?? false),
                // Same "carried, not defaulted" reasoning as apiEnabled above (#718).
                'salesRepEligible' => $user instanceof AdminUser && $user->isSalesRepEligible(),
            ],
            'userGroup' => $group,
            'roleOptions' => $roleOptions,
            'roleEditable' => $roleEditable,
            'companies' => $this->companyRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/status/{type}/{id}', name: 'admin_user_status', methods: ['POST'], requirements: ['type' => 'admin|customer', 'id' => '\\d+'])]
    public function updateStatus(string $type, int $id, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator): JsonResponse
    {
        $class = $this->userClassForType($type);
        $user = $entityManager->find($class, $id);
        $this->denyIfHiddenTechSupport($user);

        if (!$user) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'User could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user instanceof AdminUser) {
            $blockedResponse = $this->denyIfCannotManageStaffTarget($user, true);
            if ($blockedResponse instanceof JsonResponse) {
                return $blockedResponse;
            }
        }

        $status = trim((string) $request->request->get('status', ''));
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Please choose a valid status.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($status === 'Inactive' && $user instanceof AdminUser && $this->isTheOnlyActiveSuperAdmin($entityManager, $user)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'This is the only active Super Admin. Promote another Super Admin before deactivating this account.',
            ], Response::HTTP_CONFLICT);
        }

        $previousStatus = method_exists($user, 'getStatus') ? trim((string) $user->getStatus()) : '';
        $actor = $this->getUser();
        $user->setStatus($status, $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system());

        $message = sprintf('User "%s" status updated to %s.', $user->getEmail(), $status);
        if ($user instanceof CustomerUser && $status === 'Active') {
            $companyMessage = $this->approveCustomerCompanyOnActivation($user, $entityManager);
            if ($companyMessage !== null) {
                $message .= ' ' . $companyMessage;
            }
        }

        $entityManager->flush();

        if ($user instanceof CustomerUser && $status === 'Active' && strcasecmp($previousStatus, 'Active') !== 0) {
            try {
                $this->sendCustomerApprovalEmail($user, $entityManager, $mailer, $emailTemplates, $customerUrlGenerator);
            } catch (\Throwable) {
                $message .= ' Activation email could not be sent.';
            }
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $message,
            'status' => $status,
        ]);
    }

    #[Route('/delete/{type}/{id}', name: 'admin_user_delete', methods: ['POST'], requirements: ['type' => 'admin|customer', 'id' => '\\d+'])]
    public function delete(string $type, int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $class = $this->userClassForType($type);
        $user = $entityManager->find($class, $id);
        
        if ($user) {
            if ($user instanceof AdminUser) {
                $blockedResponse = $this->denyIfCannotManageStaffTarget($user, true);
                if ($blockedResponse instanceof JsonResponse) {
                    return $blockedResponse;
                }

                if ($this->isTheOnlyActiveSuperAdmin($entityManager, $user)) {
                    return new JsonResponse([
                        'ok' => false,
                        'message' => 'This is the only active Super Admin. Promote another Super Admin before deleting this account.',
                    ], Response::HTTP_CONFLICT);
                }
            }

            $currentUser = $this->getUser();
            if (
                $user instanceof AdminUser
                && $currentUser instanceof AdminUser
                && $user->getId() !== null
                && $user->getId() === $currentUser->getId()
            ) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => 'You cannot delete your own user account while you are logged in.',
                ], Response::HTTP_CONFLICT);
            }

            $email = $user->getEmail();
            $entityManager->remove($user);

            try {
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => sprintf('User "%s" is still referenced elsewhere (for example, a payment or a refund) and cannot be deleted.', $email),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse(['ok' => true, 'message' => sprintf('User "%s" was deleted successfully.', $email)]);
        }

        return new JsonResponse(['ok' => false, 'message' => 'User could not be found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/reset-password/{type}/{id}', name: 'admin_user_reset_password', methods: ['POST'], requirements: ['type' => 'admin|customer', 'id' => '\\d+'])]
    public function resetPassword(string $type, int $id, Request $request, EntityManagerInterface $entityManager, \Symfony\Component\Mailer\MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator, ResetTokenService $resetTokenService): JsonResponse
    {
        $class = $this->userClassForType($type);
        $user = $entityManager->find($class, $id);

        if ($user) {
            if ($user instanceof AdminUser) {
                $blockedResponse = $this->denyIfCannotManageStaffTarget($user, true);
                if ($blockedResponse instanceof JsonResponse) {
                    return $blockedResponse;
                }
            }

            $token = $resetTokenService->generate();
            $user->setResetToken($resetTokenService->hash($token));
            // Admin-ISSUED reset, not the self-service one: the recipient did not ask for this and
            // is not waiting on it, so it gets the invite lifetime. See AppSettings::inviteTokenExpiresAt().
            $user->setResetTokenExpiresAt($this->appSettings->inviteTokenExpiresAt());
            $entityManager->flush();

            $ctx = [
                'user' => $user,
                'user_email' => $user->getEmail(),
                'reset_url' => $user instanceof CustomerUser
                    ? $customerUrlGenerator->generate('customer_password_reset', ['token' => $token])
                    : $this->generateUrl('admin_password_reset', ['token' => $token], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                // Admin-issued, not self-service — the token above uses the invite lifetime rather
                // than the short self-service window, so the copy has to say so too. The
                // forgot_password template (file and DB row alike) prints whatever arrives here and
                // has no idea which of the two flows rendered it. See #450, #475.
                'expiry_description' => $this->appSettings->inviteTokenExpiryDescription(),
            ];

            $rendered = $emailTemplates->render('forgot_password', $ctx);
            $subject = $rendered?->subject ?? 'Password Reset Request';
            $body = $rendered?->body ?? $this->renderView('emails/forgot_password.html.twig', $ctx);

            $email = $this->appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SUPPORT)
                ->to($user->getEmail())
                ->subject($subject)
                ->html($body);

            try {
                $mailer->send($email);
                return new JsonResponse(['ok' => true, 'message' => sprintf('Password reset link sent to "%s".', $user->getEmail())]);
            } catch (\Exception $e) {
                return new JsonResponse(['ok' => false, 'message' => 'Failed to send email.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse(['ok' => false, 'message' => 'User could not be found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/resend-invite/{type}/{id}', name: 'admin_user_resend_invite', methods: ['POST'], requirements: ['type' => 'admin|customer', 'id' => '\\d+'])]
    public function resendInvite(string $type, int $id, Request $request, EntityManagerInterface $entityManager, \Symfony\Component\Mailer\MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator, ResetTokenService $resetTokenService): JsonResponse
    {
        $class = $this->userClassForType($type);
        $user = $entityManager->find($class, $id);

        if ($user) {
            if ($user instanceof AdminUser) {
                $blockedResponse = $this->denyIfCannotManageStaffTarget($user, true);
                if ($blockedResponse instanceof JsonResponse) {
                    return $blockedResponse;
                }
            }

            $token = $resetTokenService->generate();
            $user->setResetToken($resetTokenService->hash($token));
            $user->setResetTokenExpiresAt($this->appSettings->inviteTokenExpiresAt());
            $entityManager->flush();

            $ctx = [
                'user' => $user,
                'user_email' => $user->getEmail(),
                'reset_url' => $user instanceof CustomerUser
                    ? $customerUrlGenerator->generate('customer_account_setup', ['token' => $token])
                    : $this->generateUrl('admin_account_setup', ['token' => $token], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                'expiry_description' => $this->appSettings->inviteTokenExpiryDescription(),
            ];

            $rendered = $emailTemplates->render('new_user_invited', $ctx)
                ?? $emailTemplates->render('invite', $ctx);
            $subject = $rendered?->subject ?? 'Invitation to ' . $this->appSettings->siteName();
            $body = $rendered?->body ?? $this->renderView('emails/invite.html.twig', $ctx);

            $email = $this->appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SUPPORT)
                ->to($user->getEmail())
                ->subject($subject)
                ->html($body);

            try {
                $mailer->send($email);
                return new JsonResponse(['ok' => true, 'message' => sprintf('User invite sent to "%s".', $user->getEmail())]);
            } catch (\Exception $e) {
                return new JsonResponse(['ok' => false, 'message' => 'Failed to send invite email.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse(['ok' => false, 'message' => 'User could not be found.'], Response::HTTP_NOT_FOUND);
    }



    protected function nullableRequestValue(Request $request, string $key): ?string
    {
        return $this->cleanNullableRequestValue($request, $key);
    }

    /**
     * Announces the outcome of creating $user, including the invite mail if one was asked for.
     *
     * This used to be a void sendInviteIfRequested() that added its own `warning` when the send
     * failed, leaving the caller to add an unconditional `success` immediately after. A failed
     * invite therefore produced BOTH: the admin was told in the same breath that the mail had not
     * gone out and that everything had worked, and had no way to tell which to believe (#448).
     *
     * Reporting is folded into the send for that reason — so there is exactly one exit per
     * outcome and no second flash can be appended after the fact. The failure wording still
     * confirms the user was created, because it was: only the mail failed, and an admin who reads
     * "could not be sent" as "nothing happened" will create the account a second time.
     */
    private function sendInviteIfRequestedAndReport(Request $request, AdminUser|CustomerUser $user, EntityManagerInterface $entityManager, CustomerInviteMailer $inviteMailer): void
    {
        $email = (string) $user->getEmail();

        if ($request->request->get('send_account_email') !== 'yes') {
            $this->addFlash('success', sprintf('User "%s" was created successfully.', $email));

            return;
        }

        if (!$inviteMailer->send($user, $entityManager)) {
            $this->addFlash('warning', sprintf('User "%s" was created, but the invite email could not be sent.', $email));

            return;
        }

        $this->addFlash('success', sprintf('User "%s" was created successfully.', $email));
    }

    private function sendCustomerApprovalEmail(CustomerUser $user, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CustomerUrlGenerator $customerUrlGenerator): void
    {
        $ctx = [
            'company' => $user->getCompany(),
            'user' => $user,
            'user_email' => $user->getEmail(),
            'login_url' => $customerUrlGenerator->generate('customer_login'),
        ];

        $rendered = $emailTemplates->render('register', $ctx);
        $subject = $rendered?->subject ?? 'Your account is now active - ' . $this->appSettings->siteName();
        $body = $rendered?->body ?? $this->renderView('emails/register.html.twig', $ctx);

        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to($user->getEmail())
            ->subject($subject)
            ->html($body);

        $mailer->send($email);
    }

    private function sendEmailChangedNotification(string $previousEmail, CustomerUser $user, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates): void
    {
        $ctx = [
            'company' => $user->getCompany(),
            'user' => $user,
            'user_email' => $previousEmail,
            'old_email' => $previousEmail,
            'new_email' => $user->getEmail(),
            // The template prints this as prose — "contact us immediately at ..." — so it wants the
            // store's contact inbox, not whatever the mail happens to be sent from. Those were the
            // same string until sender_from_address existed; the sender is now always the sending
            // domain's own no-reply@ (#474), which is the last address to tell someone to write to
            // when they think their account was just taken over. support_email is the whole answer
            // here: the template already drops the sentence when it is blank, which is better than
            // printing an address nobody reads.
            'support_email' => trim((string) $this->appSettings->get('support_email', '')),
        ];

        $rendered = $emailTemplates->render('email_changed', $ctx);
        $subject = $rendered?->subject ?? "Your account's email has changed - " . $this->appSettings->siteName();
        $body = $rendered?->body ?? $this->renderView('emails/email_changed.html.twig', $ctx);

        // No explicit Reply-To. It used to be support_email, on the reasoning that a "your email
        // was changed" warning is the one message you most want a human on the end of — but that
        // decision is now the operator's to make once, for all mail, via sender_replyto_address,
        // which SenderReplyToSubscriber applies here like everywhere else. Blank there means this
        // message goes out with no Reply-To at all (#474).
        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to($previousEmail)
            ->subject($subject)
            ->html($body);

        $mailer->send($email);
    }

    /** @return list<string> */
    /**
     * @param bool $validateEmail false where the screen does not offer the field — a staff edit — so
     *                            an address that cannot be submitted is not required, format-checked
     *                            or uniqueness-checked either
     */
    /**
     * The entity class a {type} route segment names.
     *
     * Throws on anything else rather than falling through to CustomerUser, which is what the previous
     * ternary did — an unrecognised type silently selected the customer branch, the more permissive of
     * the two, where the email is editable. The route requirements make that unreachable; this states
     * the assumption where the decision is made, so the code does not depend on an attribute several
     * hundred lines away staying correct.
     *
     * @return class-string<AdminUser|CustomerUser>
     */
    private function userClassForType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'admin' => AdminUser::class,
            'customer' => CustomerUser::class,
            default => throw $this->createNotFoundException(sprintf('Unknown user type "%s".', $type)),
        };
    }

    /** 'staff' or 'customer', from the same single source as userClassForType(). */
    private function userGroupForType(string $type): string
    {
        return $this->userClassForType($type) === AdminUser::class ? 'staff' : 'customer';
    }

    /**
     * Ported to a symfony/validator constraint for #309, following ValidRedirect's #222/#305
     * pattern: ValidUserRequestValidator runs the same four checks this method used to run by
     * hand — email, role, staff permissions, company choice — so a future fix to any of them is
     * inherited by staffCreate(), customerCreate() and update() without any of them changing
     * anything.
     */
    private function validateUserRequest(Request $request, EntityManagerInterface $entityManager, AdminUser|CustomerUser|null $existingUser, ?array $allowedRoles = null, ?string $type = null, bool $validateEmail = true): array
    {
        $normalizedType = strtolower((string) $type);
        if ($existingUser instanceof AdminUser) {
            $normalizedType = 'admin';
        } elseif ($existingUser instanceof CustomerUser) {
            $normalizedType = 'customer';
        }

        $email = trim((string) $request->request->get('email', ''));

        // Unconditional: this used to skip customer updates, so a submitted customer role was never
        // checked against anything. Now that the role is editable for them it has to be validated.
        $role = $this->normalizeRoleLabel((string) $request->request->get('role', $existingUser !== null ? $this->roleLabelForUser($existingUser) : self::ROLE_COMPANY_STAFF));
        $valid = $allowedRoles ?? [
            self::ROLE_COMPANY_OWNER,
            self::ROLE_COMPANY_STAFF,
            self::ROLE_ADMIN,
            self::ROLE_SUPERADMIN,
            self::ROLE_PLANT_STAFF,
            self::ROLE_SALES_REP,
            self::ROLE_TECH_SUPPORT,
        ];

        $actor = $this->getUser();
        $companyId = trim((string) $request->request->get('company', ''));

        $violations = Validation::createValidator()->validate($email, new ValidUserRequest(
            entityManager: $entityManager,
            normalizedType: $normalizedType,
            existingUser: $existingUser,
            role: $role,
            allowedRoles: $valid,
            actor: $actor instanceof AdminUser || $actor instanceof CustomerUser ? $actor : null,
            // Tech Support was missing from requireStaffManager()'s list here while it was included
            // there, so a Tech Support user could open the staff form and then be told they may not
            // manage staff. It sits above Super Admin by role_hierarchy, so it belongs in this list —
            // resolved once here rather than inside the validator, which has no roleLabelForUser().
            actorRoleLabel: $actor instanceof AdminUser ? $this->roleLabelForUser($actor) : null,
            existingUserRoleLabel: $existingUser instanceof AdminUser ? $this->roleLabelForUser($existingUser) : null,
            companyId: $companyId,
            validateEmail: $validateEmail,
        ));

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    /** @return array<string, mixed> */
    private function userFormRowFromRequest(Request $request, AdminUser|CustomerUser|null $existingUser = null, ?string $type = null): array
    {
        $fallbackRole = $existingUser instanceof AdminUser || ($type && strtolower($type) === 'admin')
            ? self::ROLE_ADMIN
            : self::ROLE_COMPANY_OWNER;
        if ($existingUser instanceof AdminUser || $existingUser instanceof CustomerUser) {
            $fallbackRole = $this->roleLabelForUser($existingUser);
        }

        $role = $this->normalizeRoleLabel((string) $request->request->get('role', $fallbackRole));
        $companyId = trim((string) $request->request->get('company', ''));

        return [
            'id' => $existingUser?->getId() ?? '',
            'email' => trim((string) $request->request->get('email', '')),
            'firstName' => trim((string) $request->request->get('first_name', '')),
            'lastName' => trim((string) $request->request->get('last_name', '')),
            'phoneNumber' => trim((string) $request->request->get('phone_number', '')),
            'status' => (string) $request->request->get('status', 'Active'),
            'companyId' => $companyId,
            'role' => $role,
            // Re-rendered after a validation error, so it has to reflect what was just submitted
            // rather than what is stored — otherwise a tick is silently lost every time some other
            // field fails validation.
            'apiEnabled' => $request->request->getBoolean('api_enabled'),
            'companyApiEnabled' => $existingUser instanceof CustomerUser
                && ($existingUser->getCompany()?->isApiEnabled() ?? false),
            'salesRepEligible' => $request->request->getBoolean('sales_rep_eligible'),
        ];
    }

    private function createUserEntityForRole(string $role): AdminUser|CustomerUser
    {
        $role = $this->normalizeRoleLabel($role);
        // Tech Support was missing from this list, so creating one produced a CustomerUser — an
        // account with the label but none of the access, silently filed under the wrong table.
        if (in_array($role, self::STAFF_ROLE_OPTIONS, true)) {
            return new AdminUser();
        }

        return new CustomerUser();
    }

    private function applySelectedRole(AdminUser|CustomerUser $user, string $role): void
    {
        $role = $this->normalizeRoleLabel($role);

        if ($user instanceof AdminUser) {
            if ($role === self::ROLE_TECH_SUPPORT) {
                $user->setRoles(['ROLE_TECH_SUPPORT']);
            } elseif ($role === self::ROLE_SUPERADMIN) {
                $user->setRoles(['ROLE_SUPER_ADMIN']);
            } elseif ($role === self::ROLE_PLANT_STAFF) {
                $user->setRoles(['ROLE_PLANT_STAFF']);
            } elseif ($role === self::ROLE_SALES_REP) {
                $user->setRoles(['ROLE_SALES_REP']);
            } else {
                $user->setRoles(['ROLE_ADMIN']);
            }

            return;
        }

        if ($role === self::ROLE_COMPANY_OWNER) {
            $user->setRoles(['ROLE_COMPANY_OWNER']);
            return;
        }

        $user->setRoles(['ROLE_COMPANY_STAFF']);
    }

    private function roleLabelForUser(AdminUser|CustomerUser $user): string
    {
        $roles = $user->getRoles();

        if ($user instanceof AdminUser) {
            if (in_array('ROLE_TECH_SUPPORT', $roles, true)) {
                return self::ROLE_TECH_SUPPORT;
            }

            if (in_array('ROLE_SUPER_ADMIN', $roles, true) || in_array('ROLE_SUPERADMIN', $roles, true)) {
                return self::ROLE_SUPERADMIN;
            }

            if (in_array('ROLE_PLANT_STAFF', $roles, true)) {
                return self::ROLE_PLANT_STAFF;
            }

            if (in_array('ROLE_SALES_REP', $roles, true)) {
                return self::ROLE_SALES_REP;
            }

            return self::ROLE_ADMIN;
        }

        if (in_array('ROLE_COMPANY_OWNER', $roles, true)) {
            return self::ROLE_COMPANY_OWNER;
        }

        return self::ROLE_COMPANY_STAFF;
    }

    private function normalizeRoleLabel(string $role): string
    {
        $role = trim($role);

        if ($role === self::ROLE_SUPERADMIN_LEGACY) {
            return self::ROLE_SUPERADMIN;
        }

        return $role;
    }

    private function approveCustomerCompanyOnActivation(CustomerUser $user, EntityManagerInterface $entityManager): ?string
    {
        $company = $user->getCompany();
        $notes = [];

        if ($company instanceof Company && $company->getStatus() !== 'Active') {
            $actor = $this->getUser();
            $company->setStatus('Active', $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system());
            $notes[] = sprintf('Customer "%s" is now Active.', $company->getName());
        }

        if (!$this->hasCompanyOwnerRole($user) && $this->companyHasNoOwner($entityManager, $company, $user)) {
            $user->setRoles(['ROLE_COMPANY_OWNER']);
            $notes[] = 'The account was kept as the customer owner.';
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    private function hasCompanyOwnerRole(CustomerUser $user): bool
    {
        return in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true);
    }

    private function companyHasNoOwner(EntityManagerInterface $entityManager, ?Company $company, CustomerUser $exceptUser): bool
    {
        if (!$company instanceof Company || $company->getId() === null) {
            return false;
        }

        $users = $entityManager->getRepository(CustomerUser::class)->findBy(['company' => $company]);
        foreach ($users as $companyUser) {
            if (
                $companyUser instanceof CustomerUser
                && $companyUser->getId() !== $exceptUser->getId()
                && in_array('ROLE_COMPANY_OWNER', $companyUser->getRoles(), true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<CustomerUser>
     */
    private function customerUsersWithCompanies(EntityManagerInterface $entityManager): array
    {
        /** @var list<CustomerUser> $users */
        $users = $entityManager->getRepository(CustomerUser::class)
            ->createQueryBuilder('customerUser')
            ->leftJoin('customerUser.company', 'company')
            ->addSelect('company')
            ->orderBy('company.name', 'ASC')
            ->addOrderBy('customerUser.createdAt', 'ASC')
            ->addOrderBy('customerUser.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    private function requireStaffManager(): ?AdminUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof AdminUser) {
            $this->addFlash('error', 'Only admin staff can manage staff users.');
            return null;
        }

        if (!in_array($this->roleLabelForUser($actor), [self::ROLE_SUPERADMIN, self::ROLE_ADMIN, self::ROLE_TECH_SUPPORT], true)) {
            $this->addFlash('error', 'You are not allowed to manage staff users.');
            return null;
        }

        return $actor;
    }

    /** @return list<string> */
    private function staffRoleOptionsForActor(AdminUser $actor): array
    {
        if ($this->roleLabelForUser($actor) === self::ROLE_ADMIN) {
            return [self::ROLE_ADMIN, self::ROLE_PLANT_STAFF, self::ROLE_SALES_REP];
        }

        // Tech Support is a closed loop: only an existing holder can offer it. A Super Admin has the
        // same permissions by hierarchy but must not be able to mint the role, or the hidden tier
        // could bootstrap itself from a lesser one. The submitted role is validated against exactly
        // this list, so dropping the option here also rejects a forged POST.
        if (!$this->actorIsTechSupport()) {
            return array_values(array_diff(self::STAFF_ROLE_OPTIONS, [self::ROLE_TECH_SUPPORT]));
        }

        return self::STAFF_ROLE_OPTIONS;
    }

    /** @return list<string> */
    private function staffRoleOptionsForTarget(AdminUser|CustomerUser $user): array
    {
        if (!$user instanceof AdminUser) {
            return [self::ROLE_COMPANY_OWNER, self::ROLE_COMPANY_STAFF];
        }

        $actor = $this->getUser();
        if ($actor instanceof AdminUser && $this->roleLabelForUser($actor) === self::ROLE_ADMIN) {
            if ($this->roleLabelForUser($user) === self::ROLE_SUPERADMIN) {
                return [self::ROLE_SUPERADMIN];
            }

            if ($this->roleLabelForUser($user) === self::ROLE_TECH_SUPPORT) {
                return [self::ROLE_TECH_SUPPORT];
            }

            return [self::ROLE_ADMIN, self::ROLE_PLANT_STAFF, self::ROLE_SALES_REP];
        }

        // Tech Support is a closed loop: only an existing holder can offer it. A Super Admin has the
        // same permissions by hierarchy but must not be able to mint the role, or the hidden tier
        // could bootstrap itself from a lesser one. The submitted role is validated against exactly
        // this list, so dropping the option here also rejects a forged POST.
        if (!$this->actorIsTechSupport()) {
            return array_values(array_diff(self::STAFF_ROLE_OPTIONS, [self::ROLE_TECH_SUPPORT]));
        }

        return self::STAFF_ROLE_OPTIONS;
    }

    private function canEditStaffRole(AdminUser|CustomerUser $user): bool
    {
        if (!$user instanceof AdminUser) {
            return false;
        }

        $actor = $this->requireStaffManager();
        if (!$actor instanceof AdminUser) {
            return false;
        }

        if (
            $this->roleLabelForUser($actor) === self::ROLE_ADMIN
            && in_array($this->roleLabelForUser($user), [self::ROLE_SUPERADMIN, self::ROLE_TECH_SUPPORT], true)
        ) {
            return false;
        }

        return true;
    }

    /** True when the signed-in admin is Tech Support. */
    private function actorIsTechSupport(): bool
    {
        $actor = $this->getUser();

        return $actor instanceof AdminUser && $this->roleLabelForUser($actor) === self::ROLE_TECH_SUPPORT;
    }

    /**
     * Tech Support accounts do not exist as far as anyone else is concerned.
     *
     * The staff list already filters them out; this closes the direct-URL route, which would otherwise
     * let a Super Admin confirm an account by its id. It throws 404 rather than 403 deliberately — a
     * 403 says "there is a record here you may not see", which is precisely the fact being hidden, and
     * would let someone walk the id space to enumerate the hidden tier.
     */
    private function denyIfHiddenTechSupport(AdminUser|CustomerUser|null $target): void
    {
        if (!$target instanceof AdminUser) {
            return;
        }

        if ($this->roleLabelForUser($target) === self::ROLE_TECH_SUPPORT && !$this->actorIsTechSupport()) {
            throw $this->createNotFoundException();
        }
    }

    /** @return int Active Super Admins other than $excludeId. */
    private function activeSuperAdminCountExcluding(EntityManagerInterface $entityManager, ?int $excludeId): int
    {
        $count = 0;
        foreach ($entityManager->getRepository(AdminUser::class)->findBy(['status' => 'Active']) as $admin) {
            if ($admin->getId() === $excludeId) {
                continue;
            }
            if ($this->roleLabelForUser($admin) === self::ROLE_SUPERADMIN) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * True when $target is the system's only active Super Admin — used to block a role change,
     * deactivation, or deletion that would leave zero active Super Admins with no one left able
     * to promote a replacement. The opposite failure mode from the Tech Support closed-loop
     * guard above: that one stops a hidden tier from being exposed, this one stops the top tier
     * from being lost entirely.
     */
    private function isTheOnlyActiveSuperAdmin(EntityManagerInterface $entityManager, AdminUser $target): bool
    {
        if ($this->roleLabelForUser($target) !== self::ROLE_SUPERADMIN) {
            return false;
        }

        if (trim((string) $target->getStatus()) !== 'Active') {
            return false;
        }

        return $this->activeSuperAdminCountExcluding($entityManager, $target->getId()) === 0;
    }

    private function denyIfCannotManageStaffTarget(AdminUser $target, bool $json = false): Response|JsonResponse|null
    {
        $actor = $this->requireStaffManager();
        if (!$actor instanceof AdminUser) {
            return $json
                ? new JsonResponse(['ok' => false, 'message' => 'You are not allowed to manage staff users.'], Response::HTTP_FORBIDDEN)
                : $this->redirectToRoute('admin_user_staff_index');
        }

        if (
            $this->roleLabelForUser($actor) === self::ROLE_ADMIN
            && $this->roleLabelForUser($target) === self::ROLE_SUPERADMIN
        ) {
            $message = 'Admins cannot edit super admin users.';

            if ($json) {
                return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', $message);

            return $this->redirectToRoute('admin_user_staff_index');
        }

        if (
            $this->roleLabelForUser($target) === self::ROLE_TECH_SUPPORT
            && $this->roleLabelForUser($actor) !== self::ROLE_TECH_SUPPORT
        ) {
            $message = 'Only tech support users can manage tech support users.';

            if ($json) {
                return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', $message);

            return $this->redirectToRoute('admin_user_staff_index');
        }

        return null;
    }

    /**
     * Set one user's password directly, and show the admin what it is.
     *
     * Distinct from admin_user_reset_password, which is untouched: that one emails a one-hour reset
     * link and the admin never learns the password. This exists for the user who will not act on that
     * email — support sets a password and reads it out.
     *
     * Reached from the row action on the staff and customer lists, so the user is already chosen and
     * the page has no finder of its own: one account, one field, one button.
     *
     * The password is shown once, on the redirect after the POST, and stored nowhere in plaintext:
     * not emailed, not written to email_log, audit_log or error_log. The audit entry records who reset
     * whom, never the value. That matters because email_log was already the subject of a fix to redact
     * reset tokens, and mailing a plaintext password would reintroduce the same class of leak in a
     * worse form.
     *
     * Authorisation deliberately reuses denyIfCannotManageStaffTarget(), the same guard the staff edit
     * screens use, and it runs on the GET as well as the POST — an Admin should not even be able to
     * open this page for a Super Admin. Handing out a password is at least as powerful as editing the
     * account, so writing a fresh rule here would be a second copy to drift out of step with the first.
     */
    #[Route('/set-password/{type}/{id}', name: 'admin_user_set_password', methods: ['GET'], requirements: ['type' => 'admin|customer', 'id' => '\d+'])]
    public function setPasswordForm(string $type, int $id, EntityManagerInterface $entityManager): Response
    {
        $actor = $this->requireStaffManager();
        if (!$actor instanceof AdminUser) {
            return $this->redirectToRoute('admin_user_staff_index');
        }

        $user = $this->findPasswordTarget($type, $id, $entityManager);
        if ($user === null) {
            $this->addFlash('error', 'That user no longer exists.');

            return $this->redirectToRoute($type === 'admin' ? 'admin_user_staff_index' : 'admin_user_customer_index');
        }

        $blocked = $this->denyPasswordTarget($actor, $user);
        if ($blocked instanceof Response) {
            return $blocked;
        }

        return $this->render('admin/user/set_password.html.twig', [
            'targetType' => $type,
            'target' => $user,
            'targetRole' => $this->roleLabelForUser($user),
            'targetContext' => $user instanceof CustomerUser
                ? ($user->getCompany()?->getName() ?? 'Missing customer')
                : 'Admin console',
            // Survives exactly one render: read here and dropped from the session, so a refresh does
            // not keep redisplaying a password.
            'issued' => $this->container->get('request_stack')->getSession()->remove('admin_issued_password'),
        ]);
    }

    #[Route('/set-password', name: 'admin_user_set_password_apply', methods: ['POST'])]
    public function setPasswordApply(
        EntityManagerInterface $entityManager,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        AuditLogger $auditLogger,
    ): Response {
        $actor = $this->requireStaffManager();
        if (!$actor instanceof AdminUser) {
            return $this->redirectToRoute('admin_user_staff_index');
        }

        $type = strtolower((string) $request->request->get('type', ''));
        $id = $request->request->getInt('id');
        $listRoute = $type === 'customer' ? 'admin_user_customer_index' : 'admin_user_staff_index';

        $user = $this->findPasswordTarget($type, $id, $entityManager);
        if ($user === null) {
            $this->addFlash('error', 'That user no longer exists.');

            return $this->redirectToRoute($listRoute);
        }

        $blocked = $this->denyPasswordTarget($actor, $user);
        if ($blocked instanceof Response) {
            return $blocked;
        }

        $back = ['type' => $type, 'id' => $id];
        $generate = $request->request->get('mode') === 'generate';
        $password = $generate ? $this->generatePassword() : (string) $request->request->get('password', '');

        if (!$generate) {
            if (strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');

                return $this->redirectToRoute('admin_user_set_password', $back);
            }

            if (trim($password) !== $password) {
                // A leading or trailing space is invisible when read out over the phone, which is the
                // whole point of this screen.
                $this->addFlash('error', 'Password cannot start or end with a space.');

                return $this->redirectToRoute('admin_user_set_password', $back);
            }
        }

        $user->setPassword($passwordHasher->hashPassword($user, $password));

        // Any reset link already emailed to this user is now void. Leaving it live would mean a stale
        // inbox link could override the password the admin just read out.
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $entityManager->flush();

        $auditLogger->log(
            'users',
            $user instanceof AdminUser ? 'AdminUser' : 'CustomerUser',
            $user->getId(),
            'password_set_by_admin',
            sprintf('%s set the password for %s', $actor->getEmail(), $user->getEmail()),
        );

        $request->getSession()->set('admin_issued_password', [
            'email' => $user->getEmail(),
            'password' => $password,
            'generated' => $generate,
        ]);

        return $this->redirectToRoute('admin_user_set_password', $back);
    }

    private function findPasswordTarget(string $type, int $id, EntityManagerInterface $entityManager): AdminUser|CustomerUser|null
    {
        $class = match ($type) {
            'admin' => AdminUser::class,
            'customer' => CustomerUser::class,
            default => null,
        };

        return $class === null ? null : $entityManager->find($class, $id);
    }

    /**
     * The one place this screen decides who may be reset, shared by the GET and the POST so they can
     * never disagree.
     */
    private function denyPasswordTarget(AdminUser $actor, AdminUser|CustomerUser $user): ?Response
    {
        if (!$user instanceof AdminUser) {
            return null;
        }

        if ($user->getId() === $actor->getId()) {
            // Your own password belongs on the profile page, where changing it re-authenticates the
            // session properly instead of leaving you logged in against a stale credential.
            $this->addFlash('error', 'Change your own password from your profile page.');

            return $this->redirectToRoute('admin_profile');
        }

        // Tech-support accounts are invisible to everyone else on the staff list; this screen must not
        // be a way around that, nor around "Admins cannot edit super admin users".
        return $this->denyIfCannotManageStaffTarget($user);
    }

    /**
     * A password a human can read down a phone line without ambiguity.
     *
     * The alphabet omits O/0, I/l/1 and similar look-alikes on purpose — this password gets dictated
     * aloud or copied from a screen, and a character the recipient mistypes turns into a second
     * support call. Grouped into blocks for the same reason. random_int() is the CSPRNG, so the
     * readability constraint costs nothing in strength: 4 blocks of 4 from a 25-character alphabet is
     * about 74 bits.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $blocks = [];

        for ($block = 0; $block < 4; ++$block) {
            $chunk = '';
            for ($i = 0; $i < 4; ++$i) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $blocks[] = $chunk;
        }

        return implode('-', $blocks);
    }
}
