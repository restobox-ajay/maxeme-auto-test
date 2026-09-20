<?php

namespace App\Controller\Admin;

use App\Service\Region;
use App\Twig\SandboxedTemplateRenderer;
use App\Entity\SalesOrder;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\AdminUser;
use App\Entity\CompanyNote;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Repository\AuditLogRepository;
use App\Service\AppSettings;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\CustomFieldRenderer;
use App\Service\DocumentActor;
use App\Service\ResetTokenService;
use App\Service\TextInput;
use App\Validation\Constraint\ValidCompany;
use App\Validation\Constraint\ValidCompanyAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use App\Service\Email\EmailTemplateRenderer;

#[Route('/admin/company')]
final class CompanyController extends AbstractAdminController
{
    public function __construct(private readonly \App\Service\AppSettings $appSettings)
    {
    }

    #[Route('', name: 'admin_company_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $search = $request->query->get('q', '');
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        $repo = $entityManager->getRepository(Company::class);
        $qb = $repo->createQueryBuilder('c');

        if ($search) {
            $qb->andWhere('c.name LIKE :q OR c.code LIKE :q OR c.primaryEmail LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        $filterName = trim((string) ($filters['name'] ?? ''));
        if ($filterName !== '') {
            $qb->andWhere('c.name LIKE :filterName')->setParameter('filterName', '%' . $filterName . '%');
        }

        $filterCode = trim((string) ($filters['code'] ?? ''));
        if ($filterCode !== '') {
            $qb->andWhere('c.code LIKE :filterCode')->setParameter('filterCode', '%' . $filterCode . '%');
        }

        $filterEmail = trim((string) ($filters['primaryEmail'] ?? ''));
        if ($filterEmail !== '') {
            $qb->andWhere('c.primaryEmail LIKE :filterEmail')->setParameter('filterEmail', '%' . $filterEmail . '%');
        }

        $filterContact = trim((string) ($filters['contact'] ?? ''));
        if ($filterContact !== '') {
            $qb->andWhere('c.firstName LIKE :filterContact OR c.lastName LIKE :filterContact')
               ->setParameter('filterContact', '%' . $filterContact . '%');
        }

        $filterPhone = trim((string) ($filters['phoneNumber'] ?? ''));
        if ($filterPhone !== '') {
            $qb->andWhere('c.phoneNumber LIKE :filterPhone')->setParameter('filterPhone', '%' . $filterPhone . '%');
        }

        // One field, two shapes of value: the literal "mine" resolves to the signed-in admin (#718's
        // "my accounts" ask) rather than requiring them to find their own name in the same list of
        // eligible reps; anything else is a plain AdminUser id.
        $filterSalesRepUserId = trim((string) ($filters['salesRepUserId'] ?? ''));
        if ($filterSalesRepUserId === 'mine') {
            $actor = $this->getUser();
            if ($actor instanceof AdminUser && $actor->getId() !== null) {
                $qb->andWhere('c.salesRepUser = :filterSalesRepUserId')->setParameter('filterSalesRepUserId', $actor->getId());
            } else {
                // No signed-in admin to scope to — show nothing rather than silently falling back
                // to "every company", which is what dropping the filter would do.
                $qb->andWhere('1 = 0');
            }
        } elseif (ctype_digit($filterSalesRepUserId)) {
            $qb->andWhere('c.salesRepUser = :filterSalesRepUserId')->setParameter('filterSalesRepUserId', (int) $filterSalesRepUserId);
        }

        $filterAccountType = trim((string) ($filters['accountType'] ?? ''));
        if ($filterAccountType !== '') {
            $normalizedAccountType = $this->normalizeAccountType($filterAccountType);
            if ($normalizedAccountType === 'Non-business') {
                $qb->andWhere('c.accountType IN (:filterAccountTypes)')
                    ->setParameter('filterAccountTypes', ['Non-business', 'Personal']);
            } else {
                $qb->andWhere('c.accountType = :filterAccountType')
                    ->setParameter('filterAccountType', $normalizedAccountType);
            }
        }

        $filterStatus = trim((string) ($filters['status'] ?? ''));
        if ($filterStatus !== '') {
            $qb->andWhere('c.status = :filterStatus')->setParameter('filterStatus', $filterStatus);
        }

        $filterId = trim((string) ($filters['id'] ?? ''));
        if ($filterId !== '' && ctype_digit($filterId)) {
            $qb->andWhere('c.id = :filterId')->setParameter('filterId', (int) $filterId);
        }

        $filterBillingAddress = trim((string) ($filters['billingAddress'] ?? ''));
        if ($filterBillingAddress !== '') {
            $qb->leftJoin('c.addresses', 'ba', 'WITH', 'ba.isDefaultBilling = true')
               ->andWhere('(ba.addressLine1 LIKE :filterBilling OR ba.city LIKE :filterBilling OR ba.province LIKE :filterBilling OR ba.country LIKE :filterBilling)')
               ->setParameter('filterBilling', '%' . $filterBillingAddress . '%');
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $limit));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $sort = trim((string) $request->query->get('sort', 'name'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderExpr = match ($sort) {
            'id' => 'c.id',
            'name' => 'c.name',
            'code' => 'c.code',
            'primaryEmail' => 'c.primaryEmail',
            'phoneNumber' => 'c.phoneNumber',
            'accountType' => 'c.accountType',
            'status' => 'c.status',
            'contact' => 'c.firstName',
            'salesRepUserName' => 'sr.firstName',
            'billingAddress' => 'ba.addressLine1',
            default => 'c.name',
        };
        if ($orderExpr === 'ba.addressLine1') {
            $qb->leftJoin('c.addresses', 'ba', 'WITH', 'ba.isDefaultBilling = true');
        }
        if ($orderExpr === 'sr.firstName') {
            $qb->leftJoin('c.salesRepUser', 'sr');
        }

        $companies = $qb->select('c')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = array_map(fn (Company $company): array => $this->companyToRow($company), $companies);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'html' => $this->renderView('admin/company/_list_rows.html.twig', ['companies' => $rows]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pageCount,
            ]);
        }

        return $this->render('admin/company/index.html.twig', [
            'companies' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'salesRepOptions' => $this->salesRepRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/create', name: 'admin_company_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CompanyFulfillmentRegionService $companyFulfillmentRegionService, ResetTokenService $resetTokenService, CustomFieldRenderer $customFieldRenderer): Response
    {
        $returnTo = $this->normalizeCompanyReturnTo((string) $request->query->get('return_to', $request->request->get('return_to', 'company_index')));

        if ($request->isMethod('POST')) {
            $company = new Company();
            $this->applyCompanyRequest($company, $request, $entityManager);

            $errors = $this->validateCompany($company);
            $errors = array_merge($errors, $this->validateFulfillmentRegionChecklist($request, $entityManager, $companyFulfillmentRegionService));
            if ($errors === []) {
                $entityManager->persist($company);
                $entityManager->flush();

                $companyFulfillmentRegionService->backfillForNewCompany($company);
                $entityManager->flush();

                $this->applyFulfillmentRegionChecklist($company, $request, $entityManager);
                $customFieldRenderer->saveFromRequest('company', $company, $request);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Customer "%s" was created successfully.', $company->getName()));

                $this->handleNewAccountEmail($company, $request, $entityManager, $mailer, $emailTemplates, $resetTokenService, alwaysCreateAccount: true);
            } else {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/company/form.html.twig', [
                    'mode' => 'Create',
                    'company' => $this->companyToRow($company),
                    'priceLists' => $this->priceListRowsFromDatabase($entityManager),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'salesRepOptions' => $this->salesRepRowsFromDatabase($entityManager),
                    'fulfillmentRegions' => $this->fulfillmentRegionChecklistRows($entityManager, null, $request),
                    'returnTo' => $returnTo,
                    'backUrl' => $this->companyReturnUrl($returnTo),
                    'customFieldFragment' => $customFieldRenderer->renderFields('company', null, 'add'),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirect($this->companyReturnUrl($returnTo));
        }

        return $this->render('admin/company/form.html.twig', [
            'mode' => 'Create',
            'company' => ['id' => '', 'name' => '', 'code' => '', 'paymentTermId' => '', 'status' => 'Active', 'notes' => ''],
            'priceLists' => $this->priceListRowsFromDatabase($entityManager),
            'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
            'salesRepOptions' => $this->salesRepRowsFromDatabase($entityManager),
            'fulfillmentRegions' => $this->fulfillmentRegionChecklistRows($entityManager, null, $request),
            'returnTo' => $returnTo,
            'backUrl' => $this->companyReturnUrl($returnTo),
            'customFieldFragment' => $customFieldRenderer->renderFields('company', null, 'add'),
        ]);
    }

    #[Route('/update/{id}', name: 'admin_company_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, CompanyFulfillmentRegionService $companyFulfillmentRegionService, ResetTokenService $resetTokenService, CustomFieldRenderer $customFieldRenderer, AuditLogRepository $auditLogRepository, \App\Service\CompanyCreditExposureCalculator $creditExposureCalculator): Response
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');
            return $this->redirectToRoute('admin_company_index');
        }

        if ($request->isMethod('POST')) {
            $this->applyCompanyRequest($company, $request, $entityManager);
            $errors = $this->validateCompany($company);
            $errors = array_merge($errors, $this->validateFulfillmentRegionChecklist($request, $entityManager, $companyFulfillmentRegionService));
            if ($errors === []) {
                $entityManager->flush();

                $this->applyFulfillmentRegionChecklist($company, $request, $entityManager);
                $customFieldRenderer->saveFromRequest('company', $company, $request);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Customer "%s" was updated successfully.', $company->getName()));

                $this->handleNewAccountEmail($company, $request, $entityManager, $mailer, $emailTemplates, $resetTokenService);
            } else {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/company/form.html.twig', [
                    'mode' => 'Update',
                    'company' => $this->companyToRow($company),
                    'priceLists' => $this->priceListRowsFromDatabase($entityManager),
                    'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
                    'salesRepOptions' => $this->salesRepRowsFromDatabase($entityManager),
                    'fulfillmentRegions' => $this->fulfillmentRegionChecklistRows($entityManager, $company, $request),
                    'customFieldFragment' => $customFieldRenderer->renderFields('company', $company, 'edit'),
                    'auditHistory' => $auditLogRepository->findForEntity('Company', $company->getId()),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirectToRoute('admin_company_index');
        }

        return $this->render('admin/company/form.html.twig', [
            'mode' => 'Update',
            'company' => $this->companyToRow($company),
            'priceLists' => $this->priceListRowsFromDatabase($entityManager),
            'paymentTerms' => $this->paymentTermRowsFromDatabase($entityManager),
            'salesRepOptions' => $this->salesRepRowsFromDatabase($entityManager),
            'fulfillmentRegions' => $this->fulfillmentRegionChecklistRows($entityManager, $company, $request),
            'customFieldFragment' => $customFieldRenderer->renderFields('company', $company, 'edit'),
            'auditHistory' => $auditLogRepository->findForEntity('Company', $company->getId()),
            'creditExposure' => $creditExposureCalculator->exposureFor($company, $entityManager),
        ]);
    }

    #[Route('/note/{id}', name: 'admin_company_note_add', methods: ['POST'])]
    public function addNote(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $company = $entityManager->find(Company::class, $id);
        $payload = null;
        if ($company instanceof Company) {
            // No newline guard any more, and none needed: a note is a row, so a line break inside
            // one is just a line break. Both write paths used to scrub them because a newline in the
            // text of one entry silently became a second entry (#358).
            $text = TextInput::nullableStringMax($request->request->get('note'), CompanyNote::MAX_LENGTH) ?? '';
            if ($text !== '') {
                $note = (new CompanyNote())
                    ->setCompany($company)
                    ->setUserName($this->adminDisplayName())
                    ->setText($text);
                $entityManager->persist($note);
                $entityManager->flush();
                $payload = [
                    'ok' => true,
                    // The row's own id. The old payload returned an array position, which is what
                    // the edit and delete buttons then addressed the note by.
                    'id' => $note->getId(),
                    'date' => $this->companyNoteDate($note),
                    'author' => $note->getUserName(),
                    'note' => $note->getText(),
                    'message' => 'Note was added successfully.',
                ];
            } else {
                $this->addFlash('error', 'Note text is required.');
            }
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($payload ?? ['ok' => false, 'message' => 'Note text is required.'], $payload ? 200 : 400);
        }

        if ($payload) {
            $this->addFlash('success', 'Note was added successfully.');
        }

        return $this->redirectToRoute('admin_company_detail_by_id', ['id' => $id]);
    }

    #[Route('/note/{id}/update', name: 'admin_company_note_update', methods: ['POST'])]
    public function updateNote(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $note = $this->companyNoteFromRequest($id, $request, $entityManager);
        $text = TextInput::nullableStringMax($request->request->get('note'), CompanyNote::MAX_LENGTH) ?? '';

        if (!$note instanceof CompanyNote || $text === '') {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => 'Note text is required.'], 400);
            }

            $this->addFlash('error', 'Note text is required.');

            return $this->redirectToRoute('admin_company_detail_by_id', ['id' => $id]);
        }

        // createdAt is deliberately untouched: an edit corrects the wording of a note, it does not
        // make it a new one. The packed format preserved the timestamp string for the same reason.
        $note->setText($text);
        $entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => true,
                'id' => $note->getId(),
                'date' => $this->companyNoteDate($note),
                'author' => $note->getUserName(),
                'note' => $note->getText(),
                'message' => 'Note was updated successfully.',
            ]);
        }

        $this->addFlash('success', 'Note was updated successfully.');

        return $this->redirectToRoute('admin_company_detail_by_id', ['id' => $id]);
    }

    #[Route('/note/{id}/delete', name: 'admin_company_note_delete', methods: ['POST'])]
    public function deleteNote(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $note = $this->companyNoteFromRequest($id, $request, $entityManager);
        if (!$note instanceof CompanyNote) {
            return new JsonResponse(['ok' => false, 'message' => 'Note could not be found.'], Response::HTTP_NOT_FOUND);
        }

        $entityManager->remove($note);
        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'message' => 'Note was deleted successfully.']);
    }

    #[Route('/delete/{id}', name: 'admin_company_delete', methods: ['POST'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            return new JsonResponse(['ok' => false, 'message' => 'Customer could not be found.'], Response::HTTP_NOT_FOUND);
        }

        if ($company->getStatus() === 'Inactive') {
            return new JsonResponse(['ok' => true, 'message' => sprintf('Customer "%s" is already inactive.', $company->getName())]);
        }

        $name = $company->getName();
        $actor = $this->getUser();
        $documentActor = $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system();
        $company->setStatus('Inactive', $documentActor);

        // Disable all customer users under this company so they can no longer login.
        $users = $entityManager->getRepository(CustomerUser::class)->findBy(['company' => $company]);
        foreach ($users as $user) {
            if ($user instanceof CustomerUser) {
                $user->setStatus('Inactive', $documentActor);
            }
        }

        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('Customer "%s" was deactivated successfully.', $name)]);
    }

    // Friendly alias for "deactivate" (keeps backward compatibility with old /delete path).
    #[Route('/deactivate/{id}', name: 'admin_company_deactivate', methods: ['POST'])]
    public function deactivate(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->delete($id, $entityManager);
    }

    #[Route('/reactivate/{id}', name: 'admin_company_reactivate', methods: ['POST'])]
    public function reactivate(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            return new JsonResponse(['ok' => false, 'message' => 'Customer could not be found.'], Response::HTTP_NOT_FOUND);
        }

        if ($company->getStatus() === 'Active') {
            return new JsonResponse(['ok' => true, 'message' => sprintf('Customer "%s" is already active.', $company->getName())]);
        }

        $name = $company->getName();
        $actor = $this->getUser();
        $documentActor = $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system();
        $company->setStatus('Active', $documentActor);

        // Re-enable customer users under this company so they can login again.
        $users = $entityManager->getRepository(CustomerUser::class)->findBy(['company' => $company]);
        $hasOwner = false;

        foreach ($users as $user) {
            if ($user instanceof CustomerUser && in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true)) {
                $hasOwner = true;
                break;
            }
        }

        foreach ($users as $user) {
            if (!$user instanceof CustomerUser) {
                continue;
            }

            if ($user->getStatus() === 'Inactive') {
                $user->setStatus('Active', $documentActor);
            }

            if (!$hasOwner) {
                $user->setRoles(['ROLE_COMPANY_OWNER']);
                $hasOwner = true;
            }
        }

        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('Customer "%s" was reactivated successfully.', $name)]);
    }

    // Friendly alias for "activate" (keeps backward compatibility with old /reactivate path).
    #[Route('/activate/{id}', name: 'admin_company_activate', methods: ['POST'])]
    public function activate(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->reactivate($id, $entityManager);
    }

    #[Route('/{id}/user/create', name: 'admin_company_user_create', methods: ['GET', 'POST'])]
    public function createUser(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');
            return $this->redirectToRoute('admin_company_index');
        }

        if ($request->isMethod('POST')) {
            $user = new CustomerUser();
            $this->applyUserRequest($user, $request, $entityManager);
            $email = trim($user->getEmail());

            if ($email === '') {
                $this->addFlash('error', 'User email is required.');
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->addFlash('error', 'Email must be a valid email address.');
            } else {
                /** @var \App\Repository\CustomerUserRepository $customerUserRepository */
                $customerUserRepository = $entityManager->getRepository(CustomerUser::class);
                $existing = $customerUserRepository->findOneByEmailInsensitive($email);

                if ($existing instanceof CustomerUser) {
                    $this->addFlash('error', sprintf('Email "%s" is already used by a customer user.', $email));
                } else {
                    // Set a dummy password for now as per project pattern
                    $user->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));

                    $entityManager->persist($user);
                    $entityManager->flush();
                    $this->addFlash('success', sprintf('User "%s" was added to "%s".', $user->getEmail(), $company->getName()));
                }
            }

            return $this->redirectToRoute('admin_company_detail_by_id', ['id' => $id]);
        }

        return $this->render('admin/user/form.html.twig', [
            'companies' => $this->companyRowsFromDatabase($entityManager),
            'selectedCompany' => $this->companyToRow($company),
        ]);
    }

    #[Route('/{id}/address', name: 'admin_company_address_book', methods: ['GET'])]
    public function addressBook(int $id, EntityManagerInterface $entityManager): Response
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');
            return $this->redirectToRoute('admin_company_index');
        }

        $addresses = $this->addressRowsForCompany($company);

        return $this->render('admin/company/address_book.html.twig', [
            'company' => $this->companyToRow($company),
            'addresses' => $addresses,
            'defaultBilling' => $this->firstDefaultAddress($addresses, 'billing'),
            'defaultShipping' => $this->firstDefaultAddress($addresses, 'shipping'),
        ]);
    }

    #[Route('/{id}/address/create', name: 'admin_company_address_create', methods: ['GET', 'POST'])]
    public function createAddress(int $id, Request $request, EntityManagerInterface $entityManager, Region $region): Response
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');
            return $this->redirectToRoute('admin_company_index');
        }

        if ($request->isMethod('POST')) {
            $address = (new CompanyAddress())
                ->setFirstName($this->cleanRequestValue($request, 'first_name'))
                ->setLastName($this->cleanRequestValue($request, 'last_name'))
                ->setCompanyName($this->cleanRequestValue($request, 'company_name') ?: $company->getName())
                ->setEmailPrimary($this->cleanRequestValue($request, 'email'))
                ->setEmailSecondary($this->cleanRequestValue($request, 'secondary_email'))
                ->setFax($this->cleanRequestValue($request, 'fax_number'))
                ->setPhone($this->cleanRequestValue($request, 'phone_number'))
                ->setAddressLine1($this->cleanRequestValue($request, 'address_line_1'))
                ->setAddressLine2($this->cleanRequestValue($request, 'address_line_2'))
                ->setCity($this->cleanRequestValue($request, 'city'))
                ->setProvince($this->cleanRequestValue($request, 'province'))
                ->setCountry($this->cleanRequestValue($request, 'country') ?: 'CA')
                ->setPostalCode($this->cleanRequestValue($request, 'postal_code'))
                ->setDeliveryInstructions($this->cleanDeliveryInstructions($request));

            $errors = $this->validateAddress($address, $region);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/company/address_form.html.twig', [
                    'company' => $this->companyToRow($company),
                    'address' => $this->addressToFormRow($address),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $company->addAddress($address);
            $entityManager->persist($address);
            $entityManager->flush();
            $this->addFlash('success', 'Address was created successfully.');

            return $this->redirectToRoute('admin_company_detail_by_id', ['id' => $id]);
        }

        return $this->render('admin/company/address_form.html.twig', [
            'company' => $this->companyToRow($company),
            'address' => $this->emptyAddressFormRow($company),
        ]);
    }

    #[Route('/{companyId}/address/{addressId}/update', name: 'admin_company_address_update', methods: ['GET', 'POST'])]
    public function updateAddress(int $companyId, int $addressId, Request $request, EntityManagerInterface $entityManager, Region $region): Response
    {
        $company = $entityManager->find(Company::class, $companyId);
        $address = $entityManager->find(CompanyAddress::class, $addressId);

        if (!$company instanceof Company || !$address instanceof CompanyAddress || $address->getCompany()->getId() !== $companyId) {
            $this->addFlash('error', 'Address could not be found.');
            return $this->redirectToRoute('admin_company_address_book', ['id' => $companyId]);
        }

        if ($request->isMethod('POST')) {
            $address
                ->setFirstName($this->cleanRequestValue($request, 'first_name'))
                ->setLastName($this->cleanRequestValue($request, 'last_name'))
                ->setCompanyName($this->cleanRequestValue($request, 'company_name') ?: $company->getName())
                ->setEmailPrimary($this->cleanRequestValue($request, 'email'))
                ->setEmailSecondary($this->cleanRequestValue($request, 'secondary_email'))
                ->setFax($this->cleanRequestValue($request, 'fax_number'))
                ->setPhone($this->cleanRequestValue($request, 'phone_number'))
                ->setAddressLine1($this->cleanRequestValue($request, 'address_line_1'))
                ->setAddressLine2($this->cleanRequestValue($request, 'address_line_2'))
                ->setCity($this->cleanRequestValue($request, 'city'))
                ->setProvince($this->cleanRequestValue($request, 'province'))
                ->setCountry($this->cleanRequestValue($request, 'country') ?: 'CA')
                ->setPostalCode($this->cleanRequestValue($request, 'postal_code'))
                ->setDeliveryInstructions($this->cleanDeliveryInstructions($request));

            $errors = $this->validateAddress($address, $region);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/company/address_form.html.twig', [
                    'company' => $this->companyToRow($company),
                    'address' => $this->addressToFormRow($address),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $entityManager->flush();
            $this->addFlash('success', 'Address was updated successfully.');

            return $this->redirectToRoute('admin_company_address_book', ['id' => $companyId]);
        }

        return $this->render('admin/company/address_form.html.twig', [
            'company' => $this->companyToRow($company),
            'address' => $this->addressToFormRow($address),
        ]);
    }

    /**
     * Ported to a symfony/validator constraint for #309, following ValidChargeRows' #317 pattern:
     * ValidCompanyValidator runs the same rule this method used to run by hand, so a future fix is
     * inherited by create() and update() without either changing anything.
     *
     * @return list<string>
     */
    private function validateCompany(Company $company): array
    {
        $violations = Validation::createValidator()->validate($company, new ValidCompany());

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    /** @return list<string> */
    private function validateFulfillmentRegionChecklist(Request $request, EntityManagerInterface $entityManager, CompanyFulfillmentRegionService $companyFulfillmentRegionService): array
    {
        $submitted = $request->request->all('regions');
        if (!is_array($submitted)) {
            return [];
        }

        $errors = [];
        foreach ($submitted as $regionId => $data) {
            $active = (($data['active'] ?? '0') === '1');
            $priceListId = trim((string) ($data['price_list_id'] ?? ''));

            $region = $entityManager->find(FulfillmentRegion::class, (int) $regionId);
            $regionName = $region instanceof FulfillmentRegion ? $region->getName() : sprintf('region #%s', $regionId);

            $errors = array_merge($errors, $companyFulfillmentRegionService->validateActivation($active, $priceListId !== '', $regionName));
        }

        return $errors;
    }

    private function applyFulfillmentRegionChecklist(Company $company, Request $request, EntityManagerInterface $entityManager): void
    {
        $submitted = $request->request->all('regions');
        if (!is_array($submitted)) {
            return;
        }

        foreach ($submitted as $regionId => $data) {
            $region = $entityManager->find(FulfillmentRegion::class, (int) $regionId);
            if (!$region instanceof FulfillmentRegion) {
                continue;
            }

            $active = (($data['active'] ?? '0') === '1');
            $priceListId = trim((string) ($data['price_list_id'] ?? ''));
            $priceList = $priceListId !== '' ? $entityManager->find(PriceList::class, (int) $priceListId) : null;

            $row = $entityManager->getRepository(CompanyFulfillmentRegion::class)->findOneBy([
                'company' => $company,
                'fulfillmentRegion' => $region,
            ]) ?? (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($region);

            $row
                ->setStatus($active ? 'Active' : 'Inactive')
                ->setPriceList($priceList instanceof PriceList ? $priceList : null)
                ->touch();

            $entityManager->persist($row);
        }
    }

    /** @return list<array{id: string, name: string, active: bool, priceListId: string}> */
    private function fulfillmentRegionChecklistRows(EntityManagerInterface $entityManager, ?Company $company, Request $request): array
    {
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);

        $submitted = $request->isMethod('POST') ? $request->request->all('regions') : null;

        // For a company that does not exist yet, a region flagged "Default On For New Customer"
        // starts ticked with the store-wide registration default price list picked for it. That
        // setting is the only default price list there is; when it resolves to nothing the box is
        // still ticked and the select left empty, so validateFulfillmentRegionChecklist() refuses
        // the save until the admin chooses one — the flag is a nudge not to forget a region, not a
        // way to save an incomplete row.
        $defaultPriceListId = (int) $this->appSettings->get('company_registration_default_price_list_id', '');
        $defaultPriceList = $defaultPriceListId > 0 ? $entityManager->find(PriceList::class, $defaultPriceListId) : null;

        $isNewCompany = !($company instanceof Company && $company->getId() !== null);

        $existingByRegionId = [];
        if (!$isNewCompany) {
            $rows = $entityManager->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]);
            foreach ($rows as $row) {
                $existingByRegionId[$row->getFulfillmentRegion()->getId()] = $row;
            }
        }

        $out = [];
        foreach ($regions as $region) {
            $regionId = (string) $region->getId();

            if (is_array($submitted)) {
                $data = $submitted[$regionId] ?? [];
                $active = (($data['active'] ?? '0') === '1');
                $priceListId = trim((string) ($data['price_list_id'] ?? ''));
            } elseif (isset($existingByRegionId[$region->getId()])) {
                $pivot = $existingByRegionId[$region->getId()];
                $active = $pivot->isActive();
                $priceListId = (string) ($pivot->getPriceList()?->getId() ?? '');
            } else {
                // $isNewCompany rather than "no pivot row found": an existing company that is
                // somehow missing one keeps the unticked box it has always had here.
                $active = $isNewCompany && $region->isDefaultForNewCompany();
                $priceListId = $active && $defaultPriceList instanceof PriceList ? (string) $defaultPriceList->getId() : '';
            }

            $out[] = [
                'id' => $regionId,
                'name' => $region->getName(),
                'active' => $active,
                'priceListId' => $priceListId,
            ];
        }

        return $out;
    }

    /**
     * Ported to a symfony/validator constraint for #309, following ValidChargeRows' #317 pattern:
     * ValidCompanyAddressValidator runs the same rule this method used to run by hand — including
     * the country/province normalisation it performs as a side effect — so a future fix is
     * inherited by createAddress() and updateAddress() without either changing anything.
     *
     * @return list<string>
     */
    private function validateAddress(CompanyAddress $address, ?Region $region = null): array
    {
        $violations = Validation::createValidator()->validate($address, new ValidCompanyAddress($region));

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    /** @return array<string, string> */
    private function addressToFormRow(CompanyAddress $address): array
    {
        return [
            'firstName' => $address->getFirstName() ?? '',
            'lastName' => $address->getLastName() ?? '',
            'companyName' => $address->getCompanyName() ?? '',
            'email' => $address->getEmailPrimary() ?? '',
            'secondaryEmail' => $address->getEmailSecondary() ?? '',
            'faxNumber' => $address->getFax() ?? '',
            'phoneNumber' => $address->getPhone() ?? '',
            'addressLine1' => $address->getAddressLine1() ?? '',
            'addressLine2' => $address->getAddressLine2() ?? '',
            'city' => $address->getCity() ?? '',
            'province' => $address->getProvince() ?? '',
            'country' => $address->getCountry() ?? '',
            'postalCode' => $address->getPostalCode() ?? '',
            'deliveryInstructions' => $address->getDeliveryInstructions() ?? '',
        ];
    }

    /** @return array<string, string> */
    private function emptyAddressFormRow(Company $company): array
    {
        return [
            'firstName' => '',
            'lastName' => '',
            'companyName' => $company->getName(),
            'email' => $company->getPrimaryEmail() ?? '',
            'secondaryEmail' => '',
            'faxNumber' => '',
            'phoneNumber' => $company->getPhoneNumber() ?? '',
            'addressLine1' => '',
            'addressLine2' => '',
            'city' => '',
            'province' => '',
            'country' => 'CA',
            'postalCode' => '',
            'deliveryInstructions' => '',
        ];
    }

    #[Route('/{companyId}/address/{addressId}/delete', name: 'admin_company_address_delete', methods: ['POST'])]
    public function deleteAddress(int $companyId, int $addressId, EntityManagerInterface $entityManager): JsonResponse
    {
        $address = $entityManager->find(CompanyAddress::class, $addressId);
        if ($address instanceof CompanyAddress && $address->getCompany()->getId() === $companyId) {
            $entityManager->remove($address);
            $entityManager->flush();

            return new JsonResponse(['ok' => true, 'message' => 'Address was deleted successfully.']);
        }

        return new JsonResponse(['ok' => false, 'message' => 'Address could not be found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/{companyId}/address/{addressId}/default/{type}', name: 'admin_company_address_default', methods: ['POST'])]
    public function setDefaultAddress(int $companyId, int $addressId, string $type, EntityManagerInterface $entityManager): Response
    {
        $company = $entityManager->find(Company::class, $companyId);
        $address = $entityManager->find(CompanyAddress::class, $addressId);
        if ($company instanceof Company && $address instanceof CompanyAddress && $address->getCompany()->getId() === $companyId) {
            foreach ($company->getAddresses() as $existingAddress) {
                if ($type === 'billing') {
                    $existingAddress->setIsDefaultBilling(false);
                }
                if ($type === 'shipping') {
                    $existingAddress->setIsDefaultShipping(false);
                }
            }

            if ($type === 'billing') {
                $address->setIsDefaultBilling(true);
            }
            if ($type === 'shipping') {
                $address->setIsDefaultShipping(true);
            }

            $entityManager->flush();
            $this->addFlash('success', sprintf('Default %s address was updated.', $type));
        } else {
            $this->addFlash('error', 'Address could not be found.');
        }

        return $this->redirectToRoute('admin_company_address_book', ['id' => $companyId]);
    }

    /**
     * The customer's documents, one tab per type, in the panel header on the customer record.
     *
     * Order is the lifecycle: Estimates -> Sales Orders -> Invoices. Sales Orders is the default,
     * because that is what the panel held before it had tabs at all.
     *
     * Three decisions are baked into this table and are the reason it exists:
     *
     *  - **Subtabs on the panel, not the page.** The row above (View | Edit Profile | Address Book)
     *    are separate routes and replace the whole screen. Document tabs switch only this card, so
     *    the Company Details, Users and Notes boxes stay on screen and you never lose sight of
     *    whose documents you are reading. NetSuite's shape; Dynamics answers the same question with
     *    FactBox counts that drill through, and the owner chose this one.
     *  - **Links, not JavaScript.** Each tab is a plain GET carrying ?docs=, so the screen works
     *    with scripting off (the house rule, enforced by tests/Functional/AdminNoJs*Cest.php), and
     *    one customer's invoices get a URL that can be bookmarked and mailed around.
     *  - **One list per tab, never one mixed list with a Type column.** Explicitly rejected by the
     *    owner: the three documents do not share a meaning of "number", "date" or "status", and a
     *    mixed list invites reading a row without noticing which of the three it is.
     *
     * **Credit memos and sales returns are deliberately absent, and must not be added one at a
     * time.** A sales return is the goods coming back; a credit memo is the money. They are one
     * event recorded twice, and a panel offering Sales Returns and no Credit Notes reads as "a
     * return has no financial counterpart" — which is exactly the misreading #586 and #596 were
     * built to avoid. Add the pair, or add neither.
     *
     * 'listRoute' is the footer drill-through and is null on two of the three ON PURPOSE. See
     * detail() for why.
     */
    private const DOCUMENT_TABS = [
        'estimates' => [
            'label'     => 'Estimates',
            'entity'    => Estimate::class,
            'route'     => 'admin_estimate_detail',
            'empty'     => 'No estimates yet.',
            'listRoute' => null,
            'listLabel' => null,
        ],
        'sales-orders' => [
            'label'     => 'Sales Orders',
            'entity'    => SalesOrder::class,
            'route'     => 'admin_order_detail',
            'empty'     => 'No sales orders yet.',
            'listRoute' => 'admin_order_index',
            'listLabel' => 'View all orders',
        ],
        'invoices' => [
            'label'     => 'Invoices',
            'entity'    => Invoice::class,
            'route'     => 'admin_invoice_detail',
            'empty'     => 'No invoices yet.',
            'listRoute' => null,
            'listLabel' => null,
        ],
    ];

    private const DEFAULT_DOCUMENT_TAB = 'sales-orders';

    #[Route('/detail/{id}', name: 'admin_company_detail_by_id', methods: ['GET'])]
    #[Route('/detail', name: 'admin_company_detail', methods: ['GET'])]
    public function detail(EntityManagerInterface $entityManager, Request $request, \App\Service\CompanyCreditExposureCalculator $creditExposureCalculator, ?int $id = null): Response
    {
        $company = $id !== null
            ? $entityManager->find(Company::class, $id)
            : $entityManager->getRepository(Company::class)->findOneBy([], ['id' => 'ASC']);

        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');
            return $this->redirectToRoute('admin_company_index');
        }

        // Read through all() rather than get(): InputBag::get() throws a BadRequestException when
        // the parameter arrived as an array (?docs[]=invoices), and a 400 is the same wrong answer
        // as a 404 here. Every shape of unusable value takes the one path below.
        $requestedTab = $request->query->all()['docs'] ?? null;
        $activeTab = is_string($requestedTab) ? $requestedTab : self::DEFAULT_DOCUMENT_TAB;
        if (!array_key_exists($activeTab, self::DOCUMENT_TABS)) {
            // An unknown ?docs= is a stale bookmark, not a bad request — a tab that was renamed, or
            // somebody's guess. A 404 would throw away the customer the admin actually asked for
            // and answer a question nobody asked; the default tab answers the one they came with.
            $activeTab = self::DEFAULT_DOCUMENT_TAB;
        }
        $tab = self::DOCUMENT_TABS[$activeTab];

        // Only the ACTIVE tab is queried. Loading all three sets to render one triples the page's
        // cost for rows nobody asked to see.
        //
        // Still unbounded, exactly as the single order list was before it: app.js pages this card
        // client-side, so every row has to be in the HTML for it to page them. Not made worse here
        // — it is the same one list, now selectable — and server-side paging is a change to make
        // for all three at once, not to smuggle in under a tab strip.
        $documents = $entityManager->createQueryBuilder()
            ->select('d')
            ->from($tab['entity'], 'd')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        $documentTabs = [];
        foreach (self::DOCUMENT_TABS as $key => $definition) {
            $documentTabs[] = [
                'key'     => $key,
                'label'   => $definition['label'],
                'url'     => $this->generateUrl('admin_company_detail_by_id', ['id' => $company->getId(), 'docs' => $key]),
                'current' => $key === $activeTab,
            ];
        }

        // The footer drill-through exists on Sales Orders and nowhere else, and that is a finding
        // rather than an oversight: /admin/order filters by company ID (OrderSearch[company_id]),
        // while /admin/invoice and /admin/estimate filter by company NAME only — filters[company],
        // a LIKE — with no id filter at all. A link built on the name filter would send an admin
        // looking at Harbourview Ltd to a list that also holds Harbourline Building Supply's
        // invoices, so the drill-through would quietly stop meaning "this customer". No link is
        // better than one that widens without saying so. Restore these two once the list screens
        // take a company id; do not point them at the name filter.
        $documentListUrl = $tab['listRoute'] === null
            ? null
            : $this->generateUrl($tab['listRoute'], ['OrderSearch' => ['company_id' => $company->getId()]]);

        return $this->render('admin/company/detail.html.twig', [
            'company'           => $this->companyToRow($company),
            'notes'             => $this->companyNotesForDisplay($company, $entityManager),
            'addresses'         => $this->addressRowsForCompany($company),
            'users'             => $this->userRowsFromDatabase($entityManager, $company),
            'priceLists'        => $this->priceListRowsFromDatabase($entityManager),
            'paymentTerms'      => $this->paymentTermRowsFromDatabase($entityManager),
            'documentTabs'      => $documentTabs,
            'documentRows'      => array_map($this->documentRow(...), $documents),
            'documentRoute'     => $tab['route'],
            'documentEmptyText' => $tab['empty'],
            'documentListUrl'   => $documentListUrl,
            'documentListLabel' => $tab['listLabel'],
            'creditExposure'    => $creditExposureCalculator->exposureFor($company, $entityManager),
        ]);
    }

    /**
     * One row mapper for all three document types, not three near-identical ones.
     *
     * That is only possible because #636 made every sell-side document implement
     * App\Contract\Document\CommercialDocument: getDocumentNumber(), getDocumentDate(),
     * getTotal() and getLines() are the contract's, and getUserName() is AbstractSalesDocument's.
     * Before #636 this was three blocks differing only in whether the number lived on $orderNumber,
     * $documentNumber or $number. Nothing in this body branches on which class it holds.
     *
     * The union type is not a per-class branch; it is here because getId() and getStatus() are the
     * two things a row needs that sit on neither the contract nor the shared base. Every document
     * declares its own $id, and the status types genuinely differ (see documentStatusText()).
     * Widening the parameter to `object` to hide that would buy nothing but a lost type check.
     *
     * @return array{id:?int,number:string,date:string,items:int,user:string,amount:string,status:string}
     */
    private function documentRow(Estimate|Invoice|SalesOrder $document): array
    {
        $total = $document->getTotal();

        return [
            'id'     => $document->getId(),
            'number' => $document->getDocumentNumber(),
            'date'   => $document->getDocumentDate(),
            'items'  => $document->getLines()->count(),
            'user'   => $document->getUserName() ?? '-',
            // 'TBD', not '$0.00', for a null total — the word the Quotes grid already uses. An
            // Estimate's subtotal/tax/total are nullable precisely so that "still being priced" and
            // "settled at nothing" stay different states, and number_format((float) null, 2) erases
            // that distinction. Sales orders and invoices always state a total, so the Sales Orders
            // tab reads exactly as it did before the tabs existed.
            'amount' => $total === null ? 'TBD' : '$' . number_format((float) $total, 2),
            'status' => $this->documentStatusText($document),
        ];
    }

    /**
     * SalesOrder's and Estimate's getStatus() return a plain string; Invoice's still returns a
     * backed enum until it joins the seam.
     *
     * Normalised here and not in the template, because the view should not have to know which of
     * the three it is holding. `row.status.value ?? row.status` in Twig is a type check written in
     * the one place that cannot do type checks, and it prints a blank cell rather than failing the
     * day a fourth document answers differently again. The instanceof stays for exactly that reason
     * — it goes when the last of the three stops answering with an enum, not before.
     */
    private function documentStatusText(Estimate|Invoice|SalesOrder $document): string
    {
        $status = $document->getStatus();

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function companyNoteFromRequest(int $companyId, Request $request, EntityManagerInterface $entityManager): ?CompanyNote
    {
        $noteId = (int) $request->request->get('id', 0);
        if ($noteId <= 0) {
            return null;
        }

        $note = $entityManager->find(CompanyNote::class, $noteId);

        return $note instanceof CompanyNote && $note->getCompany()->getId() === $companyId ? $note : null;
    }

    /** The one place a note's timestamp is turned into display text, so the list and the JSON agree. */
    private function companyNoteDate(CompanyNote $note): string
    {
        return $note->getCreatedAt()->format('M j, Y g:i A');
    }

    /**
     * @return list<array{id:int,date:string,author:string,note:string}>
     */
    private function companyNotesForDisplay(Company $company, EntityManagerInterface $entityManager): array
    {
        // Newest first, ordered by the stored timestamp and then by id so notes written inside the
        // same minute keep a stable order. The packed format sorted by running strtotime() back over
        // a pre-formatted string, so an entry whose date failed to parse sorted as the epoch.
        $notes = $entityManager->getRepository(CompanyNote::class)->findBy(
            ['company' => $company],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );

        return array_map(fn (CompanyNote $note): array => [
            'id' => (int) $note->getId(),
            'date' => $this->companyNoteDate($note),
            'author' => (string) $note->getUserName(),
            'note' => $note->getText(),
        ], $notes);
    }

    private function adminDisplayName(): string
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            return 'System';
        }

        $name = trim($user->getFirstName() . ' ' . $user->getLastName());

        return $name !== '' ? $name : $user->getEmail();
    }

    private function cleanRequestValue(Request $request, string $key): ?string
    {
        $value = trim((string) $request->request->get($key, ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Delivery instructions get their own reader because cleanRequestValue() only trims and
     * nulls-if-empty: it enforces no length, and every other address field on this form is bounded
     * by its column's own `length:` even though nothing checks it, while this one is `type: 'text'`
     * and is bounded by nothing at all.
     *
     * Routed through TextInput so this form agrees with every other writer of the field — same
     * shared cap, and the control-character strip that comes with it (#302/#303). An admin pasting
     * from a fulfilment email is the likeliest source of a stray C0 byte in this field, and it ends
     * up in packing slips and invoice PDFs.
     */
    private function cleanDeliveryInstructions(Request $request): ?string
    {
        return TextInput::nullableStringMax(
            $request->request->get('delivery_instructions', ''),
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
        );
    }

    private function normalizeCompanyReturnTo(string $returnTo): string
    {
        $returnTo = trim($returnTo);

        return in_array($returnTo, ['company_index', 'customer_users'], true) ? $returnTo : 'company_index';
    }

    private function companyReturnUrl(string $returnTo): string
    {
        return match ($returnTo) {
            'customer_users' => $this->generateUrl('admin_user_customer_index'),
            default => $this->generateUrl('admin_company_index'),
        };
    }

    /** @return list<array<string, string>> */
    private function addressRowsForCompany(Company $company): array
    {
        $rows = [];
        foreach ($company->getAddresses() as $address) {
            $name = trim((string) $address->getFirstName() . ' ' . (string) $address->getLastName());
            $lines = array_filter([
                $address->getAddressLine1(),
                $address->getAddressLine2(),
                trim(implode(', ', array_filter([$address->getCity(), $address->getProvince()]))),
                trim(implode(' ', array_filter([$address->getCountry(), $address->getPostalCode()]))),
            ]);

            $rows[] = [
                'id' => (string) $address->getId(),
                'name' => $name,
                'company' => $address->getCompanyName() ?? '',
                'email' => $address->getEmailPrimary() ?? '',
                'secondary' => $address->getEmailSecondary() ?? '',
                'fax' => $address->getFax() ?? '',
                'phone' => $address->getPhone() ?? '',
                'address' => implode("\n", $lines),
                'shipping' => $address->isDefaultShipping() ? 'Yes' : '',
                'billing' => $address->isDefaultBilling() ? 'Yes' : '',
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $addresses
     * @return array<string, string>|null
     */
    private function firstDefaultAddress(array $addresses, string $type): ?array
    {
        foreach ($addresses as $address) {
            if (($address[$type] ?? '') === 'Yes') {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * On create(), provisions the company's primary CustomerUser account regardless of the "Send
     * user a new account email with password link?" choice — that radio controls only whether an
     * invitation is emailed, not whether the account itself gets created. Choosing "No" here used
     * to skip this whole method, so the company would sit with no customer login at all until an
     * admin separately created one; the account is the useful side effect and the email is the
     * optional extra.
     *
     * update() also calls this, but with $alwaysCreateAccount left false: its form has no visible
     * radio (a hidden field always posts "no"), so this stays the no-op it always was there — an
     * edit to an existing company must not silently spin up a customer account as a side effect.
     */
    private function handleNewAccountEmail(Company $company, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer, EmailTemplateRenderer $emailTemplates, ResetTokenService $resetTokenService, bool $alwaysCreateAccount = false): void
    {
        $sendEmail = $request->request->get('send_account_email') === 'yes';
        if (!$sendEmail && !$alwaysCreateAccount) {
            return;
        }

        $emailAddress = trim((string) $company->getPrimaryEmail());
        if ($emailAddress === '') {
            if ($sendEmail) {
                $this->addFlash('error', 'Could not send new account email: Customer primary email is missing.');
            }
            return;
        }

        $existingAdmin = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $emailAddress]);
        if ($existingAdmin instanceof AdminUser) {
            $this->addFlash('error', sprintf('An admin user already exists with email "%s". Customer account was not created.', $emailAddress));
            return;
        }

        try {
            $user = $entityManager->getRepository(CustomerUser::class)->findOneBy(['email' => $emailAddress]);
            $isNewUser = false;

            if (!$user instanceof CustomerUser) {
                $isNewUser = true;
                $user = new CustomerUser();
                $user->setEmail($emailAddress);
                $user->setFirstName($company->getFirstName() ?? '');
                $user->setLastName($company->getLastName() ?? '');
                $user->setPhoneNumber($company->getPhoneNumber() ?? '');
                $user->setCompany($company);
                // No setStatus() call: 'Active' is the entity's own constructor default, and this is
                // a fresh, not-yet-persisted row.
                $user->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));

                $entityManager->persist($user);
            }

            if (!$user->getCompany() instanceof Company) {
                $user->setCompany($company);
            }

            $entityManager->flush();

            if (!$sendEmail) {
                if ($isNewUser) {
                    $this->addFlash('success', sprintf('Customer account created for "%s". No invitation email was sent.', $user->getEmail()));
                }
                return;
            }

            $token = $resetTokenService->generate();
            $user->setResetToken($resetTokenService->hash($token));
            $user->setResetTokenExpiresAt($this->appSettings->inviteTokenExpiresAt());

            $entityManager->flush();

            // Build the setup URL pointing to the customer host to avoid cross-host routing issues
            $customerHost = $this->getParameter('app.customer_host');
            $scheme = $request->getScheme();
            $port = $request->getPort();
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $portSuffix = ($port !== null && $port !== $defaultPort) ? ':' . $port : '';
            $resetUrl = $scheme . '://' . $customerHost . $portSuffix . '/auth/setup-account?token=' . urlencode($token);

            $ctx = [
                'user'       => $user,
                'user_email' => $user->getEmail(),
                'reset_url'  => $resetUrl,
                'expiry_description' => $this->appSettings->inviteTokenExpiryDescription(),
            ];

            $rendered = $emailTemplates->render('new_user_invited', $ctx)
                ?? $emailTemplates->render('invite', $ctx)
                ?? $emailTemplates->render('company_user_invitation', $ctx);
            $subject = $rendered?->subject ?? 'Invitation to ' . $this->appSettings->siteName();
            $body    = $rendered?->body ?? $this->renderView('emails/invite.html.twig', $ctx);

            $message = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
                ->to($user->getEmail())
                ->subject($subject)
                ->html($body);

            $mailer->send($message);

            $this->addFlash('success', sprintf(
                '%s sent to "%s".',
                $isNewUser ? 'New user account created and invitation email' : 'Password reset link',
                $user->getEmail()
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send invitation email: ' . $e->getMessage());
        }
    }
}
