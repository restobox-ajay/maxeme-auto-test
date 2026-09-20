<?php

namespace App\Controller\Customer; 

use App\Twig\SandboxedTemplateRenderer;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Repository\CustomerUserRepository;
use App\Security\Csrf\Attribute\CsrfExempt;
use App\Service\AppSettings;
use App\Service\CustomerUrlGenerator;
use App\Service\DocumentActor;
use App\Service\ResetTokenService;
use App\Service\TemplateOverrideResolver;
use App\Service\TextInput;
use App\Validation\Constraint\ValidRegistrationRequest;
use App\Validation\Dto\NewPasswordRequest;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Service\Email\EmailTemplateRenderer;
 
#[Route('/auth')] 
final class AuthController extends AbstractCustomerController
{
    /**
     * Every request-body key register() reads as a single scalar value (issue #331).
     *
     * Kept as one list so the non-scalar guard and the field extraction cannot drift apart: a field
     * added to the extraction but forgotten here is a fresh HTTP 500 on `that_field[]=x`. Nested
     * keys this method never string-casts are deliberately absent — `custom_field` is an array by
     * design and CustomFieldRenderer reads it from the Request itself.
     *
     * @var list<string>
     */
    private const REGISTRATION_SCALAR_FIELDS = [
        'company_name', 'company_email', 'company_phone', 'trade_name', 'business_license',
        'sales_rep', 'delivery_instructions', 'email', 'phone',
        'first_name', 'last_name', 'user_email', 'user_phone', 'password', 'confirm_password',
        'agree_terms',
        'ship_address_name', 'ship_first_name', 'ship_last_name', 'ship_address1', 'ship_address2',
        'ship_city', 'ship_province', 'ship_country', 'ship_postal',
        'bill_same', 'bill_address_name', 'bill_first_name', 'bill_last_name', 'bill_address1', 'bill_address2',
        'bill_city', 'bill_province', 'bill_country', 'bill_postal',
    ];

    #[Route('/login', name: 'customer_login', methods: ['GET', 'POST'])]
    #[CsrfExempt(reason: "Login CSRF is enforced by the firewall itself (security.yaml firewalls.main.form_login: enable_csrf, csrf_token_id 'authenticate'). This controller only re-renders the form after a failed attempt; demanding a second token here would turn a wrong password into a CSRF error.")]
    public function login(AuthenticationUtils $authenticationUtils, TemplateOverrideResolver $templateOverrideResolver, SandboxedTemplateRenderer $templateRenderer): Response
    {
        $user = $this->getUser();
        if ($user instanceof CustomerUser) {
            return $this->redirectToRoute('customer_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $context = [
            'error' => $error,
            'login_error_message' => $this->buildLoginErrorMessage($error),
            'last_username' => $authenticationUtils->getLastUsername(),
        ];

        // An admin-edited override (see TemplateOverrideResolver::resolveSource()) takes
        // priority over the winning provider's file template, same as EmailTemplate rows
        // override their code-defined defaults elsewhere in this controller.
        $source = $templateOverrideResolver->resolveSource('customer_login');
        if ($source !== null) {
            return new Response($templateRenderer->render($source, $context));
        }

        $template = $templateOverrideResolver->resolve('customer_login', 'customer/auth/login.html.twig');

        return $this->render($template, $context);
    }

    #[Route('/register', name: 'customer_register', methods: ['GET', 'POST'])]
    public function register(
        \Symfony\Component\HttpFoundation\Request $request,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        EmailTemplateRenderer $emailTemplates,
        CustomerUrlGenerator $customerUrlGenerator,
        AppSettings $appSettings,
        \App\Service\CompanyCodeGenerator $companyCodeGenerator,
        \App\Service\Region $region,
        \App\Service\CustomFieldRenderer $customFieldRenderer,
        RateLimiterFactoryInterface $customerRegistrationLimiter,
        RateLimiterFactoryInterface $customerRegistrationRecipientLimiter,
        \App\Service\RateLimiterGate $rateLimiterGate,
        ValidatorInterface $validator
    ): Response {
        $user = $this->getUser();
        if ($user instanceof CustomerUser) {
            return $this->redirectToRoute('customer_home');
        }

        /** @var list<string> $errors */
        $errors = [];
        $formError = '';
        /** @var array<string, string> $fieldErrors */
        $fieldErrors = $this->createRegistrationFieldErrors();
        $data = $request->request->all();

        if ($request->isMethod('POST')) {
            // #331: a type gate on the raw body, run before anything is read, trimmed, cast,
            // defaulted or validated.
            //
            // Every field this method handles lands in a string context — the casts below, and
            // `{{ data.company_name }}` in the template when the form is re-rendered after a failed
            // submission. Posting `user_email[]=a&user_email[]=b` (or a nested `user_email[x][y]`)
            // put an array into that cast, PHP raised "Array to string conversion", and the error
            // handler promoted the warning to an uncaught exception: an anonymous guest holding
            // nothing but a valid CSRF token got an HTTP 500 out of the endpoint before a single
            // validation rule had run. Checking any further in — at the field, or at the entity —
            // would already be too late, because the point is that `(string) $array` is reached at
            // all.
            //
            // So an array where a scalar is expected is not a wrong answer to be coerced, flattened,
            // first-elemented or otherwise repaired; it is malformed input and the whole submission
            // is refused here, with the offending values dropped rather than carried any further.
            // The response is still the ordinary one every bad submission gets: the form, re-rendered
            // with an error, HTTP 200. Only the keys this method reads as scalars are gated —
            // `custom_field` is an array by design and CustomFieldRenderer reads it from the Request.
            $malformedFields = $this->nonScalarRegistrationFields($data);
            if ($malformedFields !== []) {
                foreach ($malformedFields as $malformedField) {
                    unset($data[$malformedField]);
                    if (array_key_exists($malformedField, $fieldErrors)) {
                        $fieldErrors[$malformedField] = 'Please submit a single value for this field.';
                    }
                }

                $errors[] = 'These fields were submitted more than once and could not be read: '
                    . implode(', ', $malformedFields)
                    . '. Please re-enter them and submit the form again.';

                return $this->render('customer/auth/register.html.twig', [
                    'errors' => $errors,
                    'formError' => 'Please correct the highlighted fields below.',
                    'fieldErrors' => $fieldErrors,
                    'data' => $data,
                    'customFieldFragment' => $customFieldRenderer->renderFields('company', null, 'add'),
                ]);
            }

            $token = (string) $request->request->get('_token', '');
            // Backward/forward compatible field mapping: some templates use generic `email`/`phone`
            // while others split company/user fields.
            //
            // #334/#335: each of these used to be a bare `trim((string) …)` written straight through
            // to the entity. SQLite does not enforce VARCHAR(n) and nothing else did either, so a
            // 10,000-character company_name landed whole in a `length: 255` column, and a raw NUL in
            // first_name round-tripped byte-for-byte out of the database into admin screens, PDFs
            // (Dompdf treats an embedded NUL as end-of-string in places), audit snapshots and
            // exports. registrationText() routes every value through TextInput::nullableStringMax(),
            // the same conversion #302/#303 applied to CheckoutController and the admin controllers;
            // the cap on each line is that column's own declared length, and the control-character
            // strip comes free with it because nullableStringMax() delegates to nullableString().
            $companyName = $this->registrationText($data['company_name'] ?? null, 255); // Company::$name
            $companyEmail = strtolower($this->registrationText($data['company_email'] ?? ($data['email'] ?? null), 255)); // Company::$primaryEmail
            $companyPhone = $this->registrationText($data['company_phone'] ?? ($data['phone'] ?? null), 40); // Company::$phoneNumber
            $tradeName = $this->registrationText($data['trade_name'] ?? null, 255); // Company::$tradeName
            $businessLicense = $this->registrationText($data['business_license'] ?? null, 80); // Company::$businessLicense
            $salesRep = $this->registrationText($data['sales_rep'] ?? null, 120); // Company::$salesRepNote
            // 'company_notes' is deliberately not read here at all — not capped, not sanitized, not
            // read.
            //
            // Three reasons, and the third is the one that matters:
            //
            // 1. It is not a field on this form. templates/customer/auth/register.html.twig renders
            //    no such input, so nothing a real browser submits can carry it. Only a hand-crafted
            //    POST reaches this key.
            // 2. Company::$notes is not free text. It is a notes JOURNAL, stored one entry per line
            //    as "timestamp|text", with add/update/delete routes (Admin\CompanyController's
            //    admin_company_note_add / _update / _delete) and an admin UI that splits the column
            //    on newlines, indexes the resulting lines and hangs a delete button off each index.
            // 3. So writing a guest-supplied string into it was never "a note with a long value" —
            //    it was unstructured input landing in a structured column. TextInput::nullableString()
            //    strips control characters but deliberately keeps LF and CR (they are legitimate
            //    textarea content), which means a multi-line value did not arrive as one malformed
            //    entry; it forged SEVERAL bogus journal entries in a single request, each rendered
            //    to admins as a real note with a blank timestamp, all of them shifting the indexes
            //    the delete route addresses entries by.
            //
            // Capping the length would have fixed none of that, so the read and the
            // $company->setNotes() that consumed it are both gone. A company's notes are now written
            // only by the admin note routes that own the format. 'company_notes' is likewise out of
            // REGISTRATION_SCALAR_FIELDS above: that list documents what register() reads, and it no
            // longer reads this.
            //
            // Delivery instructions are a real field on the form and do belong to the shipping
            // address, which is where they are set below. Unlike every field above, the column they
            // land in is `type: 'text'` and has no declared width to quote as a cap, so they take
            // the shared product-chosen limit instead — the same one the customer address book, the
            // admin company-address form and the order/quote address cards enforce.
            $deliveryInstructions = TextInput::nullableStringMax(
                $data['delivery_instructions'] ?? null,
                TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
            ) ?? '';

            $firstName = $this->registrationText($data['first_name'] ?? null, 120); // CustomerUser::$firstName
            $lastName = $this->registrationText($data['last_name'] ?? null, 120); // CustomerUser::$lastName
            $email = strtolower($this->registrationText($data['user_email'] ?? ($data['email'] ?? null), 180)); // CustomerUser::$email
            $userPhone = $this->registrationText($data['user_phone'] ?? ($data['phone'] ?? null), 40); // CustomerUser::$phoneNumber
            // Passwords are deliberately not routed through TextInput: it trims and strips
            // characters, which would silently register an account under a secret different from the
            // one the customer typed and confirmed. The non-scalar guard above is all these need.
            $password = (string) ($data['password'] ?? '');
            $confirm = (string) ($data['confirm_password'] ?? '');
            $agree = (string) ($data['agree_terms'] ?? '');

            $shipAddressName = $this->registrationText($data['ship_address_name'] ?? null, 80); // CompanyAddress::$label
            $shipFirstName = $this->registrationText($data['ship_first_name'] ?? null, 120); // CompanyAddress::$firstName
            $shipLastName = $this->registrationText($data['ship_last_name'] ?? null, 120); // CompanyAddress::$lastName
            $shipAddress1 = $this->registrationText($data['ship_address1'] ?? null, 255); // CompanyAddress::$addressLine1
            $shipAddress2 = $this->registrationText($data['ship_address2'] ?? null, 255); // CompanyAddress::$addressLine2
            $shipCity = $this->registrationText($data['ship_city'] ?? null, 120); // CompanyAddress::$city
            $shipProvince = $this->registrationText($data['ship_province'] ?? null, 120); // CompanyAddress::$province
            $shipCountry = $this->registrationText($data['ship_country'] ?? null, 100); // CompanyAddress::$country
            $shipPostal = $this->registrationText($data['ship_postal'] ?? null, 20); // CompanyAddress::$postalCode
            $billSame = !empty($data['bill_same']);
            $billAddressName = $this->registrationText($data['bill_address_name'] ?? null, 80); // CompanyAddress::$label
            $billFirstName = $this->registrationText($data['bill_first_name'] ?? null, 120);
            $billLastName = $this->registrationText($data['bill_last_name'] ?? null, 120);
            $billingAddress1 = $this->registrationText($data['bill_address1'] ?? null, 255);
            $billingAddress2 = $this->registrationText($data['bill_address2'] ?? null, 255);
            $billingCity = $this->registrationText($data['bill_city'] ?? null, 120);
            $billingProvince = $this->registrationText($data['bill_province'] ?? null, 120);
            $billingCountry = $this->registrationText($data['bill_country'] ?? null, 100);
            $billingPostal = $this->registrationText($data['bill_postal'] ?? null, 20);

            // #311: the required/format checks above this comment used to run by hand field-by-
            // field, including the country/province normalisation write-back (#333's "blank falls
            // through to isValidCountry('')" fix, preserved verbatim in ValidRegistrationRequest).
            // The values bag is an \ArrayObject, not a plain array, because that write-back needs a
            // handle the validator can mutate — the same reasoning as #309's ValidCompanyAddressRequest.
            $registrationValues = new \ArrayObject([
                'company_name' => $companyName,
                'company_email' => $companyEmail,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'user_email' => $email,
                'user_phone' => $userPhone,
                'company_phone' => $companyPhone,
                'password' => $password,
                'confirm_password' => $confirm,
                'agree_terms' => $agree,
                'ship_address1' => $shipAddress1,
                'ship_city' => $shipCity,
                'ship_country' => $shipCountry,
                'ship_province' => $shipProvince,
                'ship_postal' => $shipPostal,
                'bill_same' => $billSame,
                'bill_address1' => $billingAddress1,
                'bill_city' => $billingCity,
                'bill_country' => $billingCountry,
                'bill_province' => $billingProvince,
                'bill_postal' => $billingPostal,
            ]);
            foreach ($validator->validate($registrationValues, new ValidRegistrationRequest($region)) as $violation) {
                $fieldErrors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }
            $shipCountry = $registrationValues['ship_country'];
            $shipProvince = $registrationValues['ship_province'];
            $billingCountry = $registrationValues['bill_country'];
            $billingProvince = $registrationValues['bill_province'];

            if (!$this->hasRegistrationErrors($fieldErrors, $errors)) {
                /** @var CustomerUserRepository $customerUserRepository */
                $customerUserRepository = $entityManager->getRepository(\App\Entity\CustomerUser::class);
                $existing = $customerUserRepository->findOneByEmailInsensitive($email);
                if ($existing) {
                    $fieldErrors['user_email'] = 'An account with this email already exists.';
                }
            }

            // #336: the rate-limit decision happens HERE — after validation, before the block below
            // that actually does anything. Everything an abusive POST is worth lives in that block:
            // the deliberately-slow password hash, the Company/CustomerUser/CompanyAddress INSERTs,
            // the registration email sent to the attacker-supplied `company_email`, and the alert
            // mailed to every Active AdminUser. Consuming afterwards, or inside the try, would mean
            // the request had already cost all of that before being told no, which is the whole
            // point of the issue rather than an incidental detail of where the call sits.
            //
            // It is deliberately NOT at the top of the POST branch. The tightest tier is 2 per 5
            // minutes and this form has twenty-odd fields; charging a quota for a mistyped postal
            // code would lock a real customer out of registering for five minutes over a typo,
            // while costing an attacker — who sends well-formed payloads because they want the
            // mail — nothing at all. Submissions that fail validation, or that the duplicate-email
            // pre-check above already rejected, never reach anything expensive, so they are not
            // worth metering.
            //
            // The IP tiers are consumed first and the recipient tier only if they accepted: a POST
            // we have already refused must not spend the quota belonging to the address in
            // `company_email`, or refusing an attacker would hand them the denial-of-registration
            // they wanted against that address for free.
            $rateLimited = false;
            if (!$this->hasRegistrationErrors($fieldErrors, $errors) && $rateLimiterGate->shouldEnforce($request)) {
                if (!$customerRegistrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                    $rateLimited = true;
                } elseif ($companyEmail !== '' && !$customerRegistrationRecipientLimiter->create($companyEmail)->consume()->isAccepted()) {
                    $rateLimited = true;
                }

                if ($rateLimited) {
                    // Deliberately one message for both tiers. Saying which one tripped would tell a
                    // prober whether the address they targeted is already being hit from elsewhere.
                    $formError = 'Too many registration attempts. Please wait a while before trying again.';
                }
            }

            if (!$rateLimited && !$this->hasRegistrationErrors($fieldErrors, $errors)) {
                try {
                    // Resolved outside the mode check below because the flagged-region block further
                    // down needs it on the review path too: it is the only store-wide default price
                    // list there is, and a CompanyFulfillmentRegion cannot be Active without one.
                    $defaultPriceListId = (int) $appSettings->get('company_registration_default_price_list_id', '');
                    $defaultPriceList = $defaultPriceListId > 0 ? $entityManager->find(PriceList::class, $defaultPriceListId) : null;

                    // Auto-approve setting: requires both a default price list and default
                    // fulfillment region to actually resolve, otherwise fall back to manual review
                    // rather than activating a company with no way to price/purchase anything.
                    $autoApprove = false;
                    $defaultRegion = null;
                    if ($appSettings->get('company_registration_mode', 'review') === 'auto') {
                        $defaultRegionId = (int) $appSettings->get('company_registration_default_fulfillment_region_id', '');
                        $defaultRegion = $defaultRegionId > 0 ? $entityManager->find(FulfillmentRegion::class, $defaultRegionId) : null;
                        $autoApprove = $defaultPriceList instanceof PriceList && $defaultRegion instanceof FulfillmentRegion;
                    }

                    // 2. Create Company
                    $company = new \App\Entity\Company();
                    $company->setName($companyName);
                    $company->setTradeName($tradeName !== '' ? $tradeName : null);
                    $company->setBusinessLicense($businessLicense !== '' ? $businessLicense : null);
                    $company->setPhoneNumber($companyPhone !== '' ? $companyPhone : null);
                    $company->setPrimaryEmail($companyEmail !== '' ? $companyEmail : null);
                    $company->setSalesRepNote($salesRep !== '' ? $salesRep : null);
                    // No setNotes() here on purpose: Company::$notes is the admin notes journal, and
                    // registration has nothing to put in it. See the 'company_notes' comment above.
                    $company->setStatus($autoApprove ? 'Active' : 'Review', DocumentActor::system());

                    $company->setCode($companyCodeGenerator->generate($entityManager, $company->getName()));

                    $entityManager->persist($company);

                    // Regions flagged "Default On For New Company" are attached on BOTH paths, so a
                    // company held for review already carries the regions the admin said every new
                    // company gets and approving it is one click rather than a trip through the
                    // region checklist. With no default price list configured we attach nothing
                    // instead of building a row CompanyFulfillmentRegionService::validateActivation()
                    // would reject; the admin completes it at approval time.
                    $attachedRegionIds = [];
                    if ($defaultPriceList instanceof PriceList) {
                        $flaggedRegions = $entityManager->getRepository(FulfillmentRegion::class)
                            ->findBy(['defaultForNewCompany' => true], ['name' => 'ASC']);
                        foreach ($flaggedRegions as $flaggedRegion) {
                            $entityManager->persist((new CompanyFulfillmentRegion())
                                ->setCompany($company)
                                ->setFulfillmentRegion($flaggedRegion)
                                ->setPriceList($defaultPriceList)
                                ->setStatus('Active'));
                            $attachedRegionIds[] = $flaggedRegion->getId();
                        }
                    }

                    // The auto-approve default region, as before — skipped only when the block above
                    // already attached it, since the pair is uniquely indexed.
                    if ($autoApprove && $defaultRegion instanceof FulfillmentRegion && $defaultPriceList instanceof PriceList
                        && !in_array($defaultRegion->getId(), $attachedRegionIds, true)) {
                        $companyRegion = (new CompanyFulfillmentRegion())
                            ->setCompany($company)
                            ->setFulfillmentRegion($defaultRegion)
                            ->setPriceList($defaultPriceList)
                            ->setStatus('Active');
                        $entityManager->persist($companyRegion);
                    }

                    // 3. Create User
                    $user = new \App\Entity\CustomerUser();
                    $user->setEmail($email);
                    $user->setFirstName($firstName !== '' ? $firstName : null);
                    $user->setLastName($lastName !== '' ? $lastName : null);
                    $user->setPhoneNumber($userPhone !== '' ? $userPhone : null);
                    $user->setCompany($company);
                    $user->setRoles(['ROLE_COMPANY_OWNER']);
                    $user->setStatus($autoApprove ? 'Active' : 'Inactive', DocumentActor::system());
                    $user->setPassword($passwordHasher->hashPassword($user, $password));

                    $entityManager->persist($user);

                    // 4. Create Shipping + Billing Addresses
                    $shipping = new \App\Entity\CompanyAddress();
                    $shipping->setCompany($company);
                    $shipping->setLabel($shipAddressName !== '' ? $shipAddressName : 'Shipping');
                    $shipping->setFirstName($shipFirstName !== '' ? $shipFirstName : $user->getFirstName());
                    $shipping->setLastName($shipLastName !== '' ? $shipLastName : $user->getLastName());
                    $shipping->setAddressLine1($shipAddress1);
                    $shipping->setAddressLine2($shipAddress2 !== '' ? $shipAddress2 : null);
                    $shipping->setCity($shipCity !== '' ? $shipCity : null);
                    $shipping->setProvince($shipProvince !== '' ? $shipProvince : null);
                    $shipping->setDeliveryInstructions($deliveryInstructions !== '' ? $deliveryInstructions : null);
                    $shipping->setCountry($shipCountry !== '' ? $shipCountry : 'CA');
                    $shipping->setPostalCode($shipPostal !== '' ? $shipPostal : null);
                    $shipping->setIsDefaultShipping(true);
                    $shipping->setIsDefaultBilling($billSame);
                    $entityManager->persist($shipping);

                    if (!$billSame) {
                        $billing = new \App\Entity\CompanyAddress();
                        $billing->setCompany($company);
                        $billing->setLabel($billAddressName !== '' ? $billAddressName : 'Billing');
                        $billing->setFirstName($billFirstName !== '' ? $billFirstName : $user->getFirstName());
                        $billing->setLastName($billLastName !== '' ? $billLastName : $user->getLastName());
                        $billing->setAddressLine1($billingAddress1);
                        $billing->setAddressLine2($billingAddress2 !== '' ? $billingAddress2 : null);
                        $billing->setCity($billingCity !== '' ? $billingCity : null);
                        $billing->setProvince($billingProvince !== '' ? $billingProvince : null);
                        $billing->setCountry($billingCountry !== '' ? $billingCountry : 'CA');
                        $billing->setPostalCode($billingPostal !== '' ? $billingPostal : null);
                        $billing->setIsDefaultBilling(true);
                        $entityManager->persist($billing);
                    }

                    $entityManager->flush();

                    $customFieldRenderer->saveFromRequest('company', $company, $request);
                    $entityManager->flush();

                    // 5. Send registration emails (non-blocking)
                    try {
                        // Company registration email (to company primary email)
                        $companyEmailTo = trim((string) $company->getPrimaryEmail());
                        if ($companyEmailTo !== '') {
                            $ctx = [
                                'company' => $company,
                                'company_name' => $company->getName(),
                                'company_code' => $company->getCode(),
                                'company_email' => $companyEmailTo,
                                'contact_name' => trim((string) ($user->getFirstName() . ' ' . $user->getLastName())),
                                'user' => $user,
                                'user_email' => $user->getEmail(),
                                'login_url' => $customerUrlGenerator->generate('customer_login'),
                                // The store's own support address, not a shipped placeholder (#351).
                                // Blank is fine: the template drops the "or contact support at ..."
                                // clause entirely rather than printing an address nobody reads.
                                'support_email' => trim((string) $appSettings->get('support_email', '')),
                            ];

                            $rendered = $emailTemplates->render('company_registration', $ctx);
                            $subject = $rendered?->subject ?? 'Company registration received - ' . $appSettings->siteName();
                            $body = $rendered?->body ?? $this->renderView('emails/company_registration.html.twig', $ctx);

                            $mailer->send(
                                $appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SUPPORT)
                                    ->to($companyEmailTo)
                                    ->subject($subject)
                                    ->html($body)
                            );
                        }

                        $activeAdmins = $entityManager->getRepository(\App\Entity\AdminUser::class)->findBy(['status' => 'Active']);
                        $adminRecipients = [];
                        foreach ($activeAdmins as $adminUser) {
                            if (!$adminUser instanceof \App\Entity\AdminUser) {
                                continue;
                            }
                            $adminEmail = strtolower(trim((string) $adminUser->getEmail()));
                            if ($adminEmail !== '') {
                                $adminRecipients[$adminEmail] = $adminEmail;
                            }
                        }

                        if ($adminRecipients !== []) {
                            $adminCtx = [
                                'company' => $company,
                                'company_name' => $company->getName(),
                                'company_code' => $company->getCode(),
                                'company_email' => $company->getPrimaryEmail(),
                                'user' => $user,
                                'user_email' => $user->getEmail(),
                                'admin_url' => $this->generateUrl('admin_user_customer_update', ['id' => $user->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                            ];

                            $adminSubject = 'New company registration pending approval';
                            $adminBody = $this->renderView('emails/company_registration_admin_alert.html.twig', $adminCtx);

                            $mailer->send(
                                $appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SUPPORT)
                                    ->to(...array_values($adminRecipients))
                                    ->subject($adminSubject)
                                    ->html($adminBody)
                            );
                        }
                    } catch (\Throwable) {
                        // Never block registration if email fails.
                        $this->addFlash('warning', 'Registration succeeded, but one or more notification emails could not be sent.');
                    }

                    $this->addFlash('success', $autoApprove
                        ? 'Registration submitted successfully. Your account is active — you can log in below.'
                        : 'Registration submitted successfully. Your company is pending approval, and you can log in once it is approved.');
                    return $this->redirectToRoute('customer_login');

                } catch (\Throwable $e) {
                    $msg = strtolower($e->getMessage());
                    // #332: the duplicate-email check above is a read-then-write. Two registrations
                    // for the same address arriving together both pass that SELECT, and the second
                    // INSERT is stopped by uniq_customer_user_email (CustomerUser.php:12) instead.
                    // The database is doing exactly the right thing — no duplicate account is ever
                    // created, verified by row count — but without this branch the violation fell
                    // straight through to the generic message below, and the loser of the race was
                    // told "Registration failed. Please verify your details and try again.", which
                    // is both wrong (their details are fine, the address is simply taken) and
                    // unactionable. In non-prod it also appended the raw driver text, so the guest
                    // read "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint
                    // failed: customer_user.email" in their error box. Racing requests now get the
                    // same answer the sequential path has always produced.
                    //
                    // Handled here rather than in a `catch (UniqueConstraintViolationException)`
                    // clause of its own precisely because it needs to be able to decline: the email
                    // index is not provably the only one this flush can trip. A database built from
                    // migrations still carries uniq_company_code, which the mapping and the admin UI
                    // gave up on (see CompanyCodeGenerator's docblock for how that drift happened),
                    // and telling a customer their email is taken because a generated company code
                    // collided would be a worse lie than the generic message. A separate catch
                    // could only re-throw, which would escape the method entirely and turn a bad
                    // message into a 500. Sniffing driver text is not elegant, but it is exactly
                    // what the 'no such column' branch below already does, and this application
                    // only ever runs on SQLite.
                    //
                    // Re-rendering rather than retrying is deliberate: by the time we are here the
                    // address really is taken, so a retry would only fail again — and a failed
                    // flush has closed the EntityManager, so there is nothing left to retry with.
                    if ($e instanceof UniqueConstraintViolationException && str_contains($msg, 'customer_user.email')) {
                        $fieldErrors['user_email'] = 'An account with this email already exists.';
                    } elseif (str_contains($msg, 'no such column') || str_contains($msg, 'unknown column')) {
                        $formError = 'Registration failed due to a server configuration issue. Please run database migrations and try again.';
                    } else {
                        $formError = 'Registration failed. Please verify your details and try again.';
                    }

                    // #332: only for a failure we could not explain. Once the branch above has
                    // turned the violation into a field error the outcome is fully accounted for,
                    // and appending the driver text would put the SQLSTATE line back in the guest's
                    // error box next to a message that already says everything true about it.
                    if ($formError !== '') {
                        try {
                            $env = (string) $this->getParameter('kernel.environment');
                            if ($env !== 'prod') {
                                $errors[] = 'Debug: ' . $e->getMessage();
                            }
                        } catch (\Throwable) {
                            // ignore
                        }
                    }
                }
            }

            if ($formError === '' && $this->hasRegistrationErrors($fieldErrors, $errors)) {
                $formError = 'Please correct the highlighted fields below.';
            }
        }

        return $this->render('customer/auth/register.html.twig', [
            'errors' => $errors,
            'formError' => $formError,
            'fieldErrors' => $fieldErrors,
            'data' => $data,
            'customFieldFragment' => $customFieldRenderer->renderFields('company', null, 'add'),
        ]);
    }

    #[Route('/password-reset', name: 'customer_password_reset', methods: ['GET', 'POST'])]
    #[Route('/forgot-password', name: 'customer_forgot_password', methods: ['GET', 'POST'])]
    public function passwordReset(
        \Symfony\Component\HttpFoundation\Request $request,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher,
        EmailTemplateRenderer $emailTemplates,
        CustomerUrlGenerator $customerUrlGenerator,
        ResetTokenService $resetTokenService,
        RateLimiterFactoryInterface $passwordResetRequestLimiter,
        AppSettings $appSettings,
        \App\Service\RateLimiterGate $rateLimiterGate,
        ValidatorInterface $validator
    ): Response {
        // Already signed in: this form is a dead end that implies you are logged out, and a live
        // session interacting with a token-based reset is a state nobody designed. Matches what
        // customer_login and customer_register already do. See issue #91.
        if ($this->getUser() instanceof CustomerUser) {
            return $this->redirectToRoute('customer_home');
        }

        $token = trim((string) $request->query->get('token', ''));
        if ($token !== '') {
            $user = $entityManager->getRepository(\App\Entity\CustomerUser::class)->findOneBy(['resetToken' => $resetTokenService->hash($token)]);
            $now = new \DateTimeImmutable();

            $tokenValid = $user instanceof \App\Entity\CustomerUser
                && $user->getResetTokenExpiresAt() instanceof \DateTimeImmutable
                && $user->getResetTokenExpiresAt() >= $now;

            if ($request->isMethod('POST')) {
                $password = (string) $request->request->get('password', '');
                $confirm = (string) $request->request->get('confirm_password', '');

                if (!$tokenValid) {
                    $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');
                    return $this->redirectToRoute('customer_password_reset');
                }

                $newPasswordViolations = $validator->validate(new NewPasswordRequest($password, $confirm));
                if (count($newPasswordViolations) > 0) {
                    $this->addFlash('error', (string) $newPasswordViolations[0]->getMessage());
                } else {
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                    $user->setResetToken(null);
                    $user->setResetTokenExpiresAt(null);
                    $entityManager->flush();

                    $this->addFlash('success', 'Password updated successfully. You can now log in.');
                    return $this->redirectToRoute('customer_login');
                }
            }

            if (!$tokenValid && !$request->isMethod('POST')) {
                $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');
            }

            return $this->render('customer/auth/password_reset.html.twig', [
                'token' => $token,
                'tokenValid' => $tokenValid,
                'mode' => 'reset',
                'loginRoute' => 'customer_login',
                'minimalCustomerHeader' => true,
            ]);
        }

        if ($request->isMethod('POST')) {
            if ($rateLimiterGate->shouldEnforce($request) && !$passwordResetRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many reset requests. Please wait a while before trying again.');
                return $this->redirectToRoute('customer_password_reset');
            }

            $emailAddress = trim($request->request->get('email', ''));
            // filter_var(), not Assert\Email: the same FILTER_VALIDATE_EMAIL check every other
            // migrated email field in this codebase uses (ValidContactRequest, ValidRegistrationRequest),
            // so a value accepted or rejected here matches every other form's answer for it.
            $emailViolations = $validator->validate($emailAddress, new Assert\Callback(
                function (string $value, \Symfony\Component\Validator\Context\ExecutionContextInterface $context): void {
                    if ($value === '') {
                        $context->buildViolation('Email is required.')->addViolation();
                    } elseif (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                        $context->buildViolation('Please enter a valid email address.')->addViolation();
                    }
                }
            ));
            if (count($emailViolations) > 0) {
                $this->addFlash('error', (string) $emailViolations[0]->getMessage());
                return $this->redirectToRoute('customer_password_reset');
            }

            if ($emailAddress !== '') {
                /** @var CustomerUserRepository $customerUserRepository */
                $customerUserRepository = $entityManager->getRepository(\App\Entity\CustomerUser::class);
                $user = $customerUserRepository->findOneByEmailInsensitive($emailAddress);
                
                if ($user) {
                    $resetToken = $resetTokenService->generate();
                    $user->setResetToken($resetTokenService->hash($resetToken));
                    // Self-service: the short, configurable window (password_reset_expiry_hours),
                    // not the invite lifetime an admin-issued reset gets. See AppSettings.
                    $user->setResetTokenExpiresAt($appSettings->passwordResetExpiresAt());
                    $entityManager->flush();

                    $resetUrl = $customerUrlGenerator->generate('customer_password_reset', ['token' => $resetToken]);

                    $ctx = [
                        'user' => $user,
                        'user_email' => $user->getEmail(),
                        'reset_url' => $resetUrl,
                        // forgot_password is shared with the admin-issued reset, which has a
                        // different lifetime, so the duration has to travel with the context rather
                        // than live in the template. Not optional: the file template and the
                        // email_template row both print it bare, and omitting it is a Twig error
                        // under strict_variables — this endpoint would 500 rather than mail (#475).
                        'expiry_description' => $appSettings->passwordResetExpiryDescription(),
                    ];

                    $rendered = $emailTemplates->render('forgot_password', $ctx);
                    $subject = $rendered?->subject ?? 'Password Reset Request';
                    $body = $rendered?->body ?? $this->renderView('emails/forgot_password.html.twig', $ctx);

                    $email = $appSettings->applyFromAddress(new \Symfony\Component\Mime\Email(), AppSettings::FROM_SUPPORT)
                        ->to($user->getEmail())
                        ->subject($subject)
                        ->html($body);
                    
                    try {
                        $mailer->send($email);
                    } catch (\Exception $e) {
                        // Log failure
                    }
                }
                
                $this->addFlash('success', 'If an account exists for that email, a password reset link has been sent.');
                return $this->redirectToRoute('customer_password_reset');
            }
        }
        
        return $this->render('customer/auth/password_reset.html.twig', [
            'token' => null,
            'tokenValid' => false,
            'mode' => 'request',
            'loginRoute' => 'customer_login',
            'minimalCustomerHeader' => true,
        ]);
    }

    #[Route('/setup-account', name: 'customer_account_setup', methods: ['GET', 'POST'])]
    public function accountSetup(
        \Symfony\Component\HttpFoundation\Request $request,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher,
        ResetTokenService $resetTokenService,
        ValidatorInterface $validator
    ): Response {
        $token = trim((string) $request->query->get('token', ''));
        if ($token === '') {
            $this->addFlash('error', 'This setup link is invalid.');
            return $this->redirectToRoute('customer_password_reset');
        }

        $user = $entityManager->getRepository(\App\Entity\CustomerUser::class)->findOneBy(['resetToken' => $resetTokenService->hash($token)]);

        $now = new \DateTimeImmutable();
        $tokenValid = $user instanceof \App\Entity\CustomerUser
            && $user->getResetTokenExpiresAt() instanceof \DateTimeImmutable
            && $user->getResetTokenExpiresAt() >= $now;

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('confirm_password', '');

            if (!$tokenValid) {
                $this->addFlash('error', 'This setup link is invalid or has expired. Please request a new one.');
                return $this->redirectToRoute('customer_password_reset');
            }

            $newPasswordViolations = $validator->validate(new NewPasswordRequest($password, $confirm));
            if (count($newPasswordViolations) > 0) {
                $this->addFlash('error', (string) $newPasswordViolations[0]->getMessage());
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                $entityManager->flush();

                $this->addFlash('success', 'Account setup complete. You can now log in.');
                return $this->redirectToRoute('customer_login');
            }
        }

        if (!$tokenValid && !$request->isMethod('POST')) {
            $this->addFlash('error', 'This setup link is invalid or has expired. Please request a new one.');
        }

        return $this->render('customer/auth/password_reset.html.twig', [
            'token' => $token,
            'tokenValid' => $tokenValid,
            'mode' => 'invite',
            'loginRoute' => 'customer_login',
            'minimalCustomerHeader' => true,
        ]);
    }

    #[Route('/password-update/{token}', name: 'customer_password_update', methods: ['GET', 'POST'])]
    public function passwordUpdate(string $token): Response
    {
        return $this->redirectToRoute('customer_password_reset', ['token' => $token]);
    }

    /**
     * The scalar registration fields (see REGISTRATION_SCALAR_FIELDS) whose submitted value is not a
     * scalar — issue #331's type gate. A plain is_scalar() check on the raw body, on purpose: this
     * is "is this even the right kind of thing", which has to answer before any value is read, not
     * a constraint about what a well-formed value contains. The Validator migration in #311 sits
     * downstream of this and still needs it.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function nonScalarRegistrationFields(array $data): array
    {
        $malformed = [];
        foreach (self::REGISTRATION_SCALAR_FIELDS as $field) {
            if (array_key_exists($field, $data) && !is_scalar($data[$field])) {
                $malformed[] = $field;
            }
        }

        return $malformed;
    }

    /**
     * One registration text field, normalised and capped at its target column's declared length
     * (issues #334/#335). Wraps TextInput the way CheckoutController::nullableString() does, and
     * returns '' rather than null because every caller in register() tests against '' and converts
     * to null itself at persist time.
     */
    private function registrationText(mixed $value, int $maxLength): string
    {
        return TextInput::nullableStringMax($value, $maxLength) ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function createRegistrationFieldErrors(): array
    {
        return [
            'company_name' => '',
            'company_email' => '',
            'first_name' => '',
            'last_name' => '',
            'user_email' => '',
            'user_phone' => '',
            'password' => '',
            'confirm_password' => '',
            'ship_address1' => '',
            'ship_city' => '',
            'ship_province' => '',
            'ship_country' => '',
            'ship_postal' => '',
            'bill_address1' => '',
            'bill_city' => '',
            'bill_province' => '',
            'bill_country' => '',
            'bill_postal' => '',
            'agree_terms' => '',
        ];
    }

    /**
     * @param array<string, string> $fieldErrors
     * @param list<string> $errors
     */
    private function hasRegistrationErrors(array $fieldErrors, array $errors): bool
    {
        foreach ($fieldErrors as $message) {
            if ($message !== '') {
                return true;
            }
        }

        return $errors !== [];
    }

    private function buildLoginErrorMessage(?AuthenticationException $error): ?string
    {
        if (!$error) {
            return null;
        }

        $messageKey = strtolower(trim((string) $error->getMessageKey()));
        $message = strtolower(trim($error->getMessage()));

        if ($messageKey === '' && $message === '') {
            return 'We could not sign you in. Please try again.';
        }

        if (str_contains($messageKey, 'csrf') || str_contains($message, 'csrf')) {
            return 'Your login session expired. Please try again.';
        }

        if (str_contains($messageKey, 'pending approval') || str_contains($message, 'pending approval')) {
            return 'Your company registration is pending approval. Please wait for an administrator to review it.';
        }

        if (str_contains($messageKey, 'disabled') || str_contains($message, 'disabled')) {
            if (str_contains($message, 'company')) {
                return 'Your company account is disabled. Please contact support.';
            }

            return 'Your account is disabled. Please contact support.';
        }

        if (str_contains($messageKey, 'bad credentials') || str_contains($messageKey, 'invalid credentials') || str_contains($message, 'bad credentials') || str_contains($message, 'invalid credentials')) {
            return 'Email or password is incorrect.';
        }

        // login_throttling (5 attempts / 15 minutes) throws TooManyLoginAttemptsAuthenticationException,
        // whose message mentions neither credentials nor CSRF — so it used to fall through to the
        // generic line below. The result was that a correct password, entered after a few typos, was
        // reported as "we could not sign you in", which reads as a broken login rather than a lockout
        // that clears on its own. Support cannot tell the two apart either, so this says so plainly.
        if (str_contains($messageKey, 'too many') || str_contains($message, 'too many')) {
            return 'Too many failed sign-in attempts. Please wait a few minutes and try again.';
        }

        return 'We could not sign you in. Please try again.';
    }
}
