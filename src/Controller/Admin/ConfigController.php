<?php

namespace App\Controller\Admin;

use App\Twig\SandboxedTemplateRenderer;
use App\Entity\AppSetting;
use App\Entity\EmailTemplate;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\PriceList;
use App\Entity\ProductInventory;
use App\Entity\SalesTax;
use App\Repository\SalesTaxRepository;
use App\Service\AdminUrlGenerator;
use App\Service\AppSettings;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Service\CustomerUrlGenerator;
use App\Service\Document\DocumentPrefixCatalogue;
use App\Service\ReferenceData\Seeders\FulfillmentRegionSeeder;
use App\Service\Region;
use App\Validation\Constraint\ValidAppSettingRequest;
use App\Validation\Constraint\ValidCompanyInfoRegion;
use App\Validation\Constraint\ValidCurrencyCode;
use App\Validation\Constraint\ValidDocumentPrefixes;
use App\Validation\Constraint\ValidEmailTemplateRequest;
use App\Validation\Constraint\ValidFulfillmentRegionRequest;
use App\Validation\Constraint\ValidRegistrationSettingsRequest;
use App\Validation\Constraint\ValidSimpleConfigRow;
use App\Validation\Constraint\ValidUploadedImage;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;

#[Route('/admin')]
final class ConfigController extends AbstractAdminController
{
    private const ORDERABLE_CONFIGS = ['shipping_zone'];

    public function __construct(
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly ShippedEmailTemplateCatalogue $emailCatalogue,
        private readonly Region $region,
    ) {
    }

    /**
     * The country/province rule both address-bearing config screens run, in one place.
     *
     * ## Why this reads `Region` and not `RegionSeedData`
     *
     * The dropdowns on these screens are built from `region_countries()` / `region_provinces()`,
     * which are `Region`, which is the `geo_country` / `geo_province` rows — ACTIVE ones only. The
     * checks here used to read `RegionSeedData`, the static seed. The two agree today because the
     * seed is what populated the tables, and they stop agreeing the moment somebody deactivates a
     * row: the list would stop offering it while this check went on accepting it. A validator with
     * its own copy of the list is the same defect one layer down, so there is one list and this is
     * the end of it that refuses.
     *
     * ## Why the province is read INSIDE the country
     *
     * This is the whole point. The two countries share the code `CA` — it is not a Canadian
     * province and it IS California — so a country-blind check (`knownProvinceAnyCountry()`, which
     * loops the country tables and returns the first match) resolves the country code typed into
     * the province box to a US state, passes every "the province is known" guard, and then no
     * Canadian calculator claims it and the tax comes out $0.00. Scoped to the submitted country,
     * the same value is refused by name.
     *
     * Returns errors keyed by the FIELD NAME each screen uses, so the message prints at the box the
     * person has to go and fix. Empty when both values are good; the caller persists nothing until
     * it is.
     *
     * @return array<string, string>
     */
    private function regionFieldErrors(
        string $country,
        string $province,
        string $countryField,
        string $provinceField,
        string $countryRequiredMessage,
        string $provinceRequiredMessage,
    ): array {
        $errors = [];
        $resolvedCountry = $this->region->normalizeCountry($country);

        if ($country === '') {
            $errors[$countryField] = $countryRequiredMessage;
        } elseif ($resolvedCountry === null || !$this->region->isValidCountry($resolvedCountry)) {
            $errors[$countryField] = sprintf(
                '"%s" is not a country this application knows. Choose one from the list.',
                $country,
            );
        }

        if ($province === '') {
            $errors[$provinceField] = $provinceRequiredMessage;
        } elseif (!isset($errors[$countryField]) && !$this->region->isValidProvince((string) $resolvedCountry, $province)) {
            $errors[$provinceField] = sprintf(
                '"%s" is not a province or state of %s. Choose one from the list.',
                $province,
                $resolvedCountry,
            );
        }

        return $errors;
    }

    /**
     * `fulfillment_regions` is a first-run seed, not live configuration, and the description has to
     * say so because the screen cannot: it looks exactly like every other editable setting.
     *
     * Every reader is an empty-fallback. FulfillmentRegionSeeder skips the whole seed once any
     * region row exists; AbstractCustomerController and ProductController only parse it when their
     * own lookup came back empty. The `fulfillment_region` table is created once, by that seeder, on
     * the first admin login — so from that moment editing this value creates nothing, renames
     * nothing and deletes nothing.
     *
     * The previous text ("The first region is the default and can't be deleted") was wrong twice
     * over: "default" is now a real per-region flag (defaultForNewCompany), and the undeletable row
     * is the lowest id in the table, which reordering this list does not change.
     */
    private const FULFILLMENT_REGIONS_DESCRIPTION = 'First-run seed only: these names create the '
        . 'initial fulfillment regions on a new installation. Once any region exists this value is '
        . 'ignored — add, rename, delete and configure regions under Fulfillment Regions instead.';
    // Every key `ensureCoreSettingsExist()` self-heals, plus the payment/registration/document
    // keys the app reads directly elsewhere — i.e. every setting that drives real behavior
    // rather than being free-form admin-entered text with no code depending on it.
    private const PROTECTED_SETTING_KEYS = [
        'app_name', 'app_email', 'support_email', 'tech_support_email', 'billing_email', 'sales_email',
        'company_name', 'company_phone', 'company_address', 'company_city', 'company_state',
        'company_postal_code', 'company_country', 'company_gst_number', 'website_url', 'logo_url',
        'favicon_url', 'product_image_placeholder_url', 'timezone', 'base_currency', 'fulfillment_regions',
        'invoice_payment_note', 'invoice_footer_note', 'email_footer_copyright', 'email_footer_address',
        'terms_url', 'privacy_url', 'returns_url', 'guest_catalog_visible', 'guest_catalog_default_url',
        'company_registration_mode', 'company_registration_default_price_list_id', 'company_registration_default_fulfillment_region_id',
        'order_number_prefix', 'quote_number_prefix', 'header_logo_only', 'checkout_coupons_enabled',
        'payment_stripe_mode', 'payment_stripe_publishable_key_test', 'payment_stripe_publishable_key_live',
        'payment_stripe_secret_key_test', 'payment_stripe_secret_key_live',
    ];

    private const SIMPLE_CONFIG = [
        'payment_term' => [
            'table' => 'payment_term',
            'title' => 'Payment Term',
            'indexRoute' => 'admin_payment_terms',
            'createRoute' => 'admin_payment_term_create',
            'updateRoute' => 'admin_payment_term_update',
            'deleteRoute' => 'admin_payment_term_delete',
            'fields' => ['name', 'description', 'status', 'sort_order'],
            'required' => ['name'],
            'numeric' => ['sort_order'],
            'statuses' => ['Active', 'Inactive'],
        ],
        'credit_memo_type' => [
            'table' => 'credit_memo_type',
            'title' => 'Credit Memo Type',
            'indexRoute' => 'admin_credit_memo_types',
            'createRoute' => 'admin_credit_memo_type_create',
            'updateRoute' => 'admin_credit_memo_type_update',
            'deleteRoute' => 'admin_credit_memo_type_delete',
            'fields' => ['name', 'status'],
            'required' => ['name'],
            'numeric' => [],
            'statuses' => ['Active', 'Inactive'],
        ],
        'shipping_zone' => [
            'table' => 'shipping_zone',
            'title' => 'Shipping Zone',
            'indexRoute' => 'admin_shipping_zones',
            'createRoute' => 'admin_shipping_zone_create',
            'updateRoute' => 'admin_shipping_zone_update',
            'deleteRoute' => 'admin_shipping_zone_delete',
            'fields' => ['area_name', 'delivery_days', 'free_shipping_minimum', 'delivery_fee', 'status'],
            'required' => ['area_name'],
            // delivery_days is free text by design (e.g. "1-2 days"), not validated as numeric.
            'numeric' => ['free_shipping_minimum', 'delivery_fee'],
            'statuses' => ['Active', 'Inactive'],
        ],
    ];

    #[Route('/settings', name: 'admin_settings', methods: ['GET'])]
    public function settings(EntityManagerInterface $entityManager, Request $request, AppSettings $appSettings): Response
    {
        $this->ensureCoreSettingsExist($entityManager, $appSettings);

        $page  = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $rawFilters = $request->query->all('filters');
        $filters = is_array($rawFilters) ? $rawFilters : [];

        $qb = $entityManager->getRepository(AppSetting::class)->createQueryBuilder('s');
        $this->hideTechSupportSettings($qb);

        $filterId = trim((string) ($filters['id'] ?? ''));
        if ($filterId !== '' && ctype_digit($filterId)) {
            $qb->andWhere('s.id = :filterId')->setParameter('filterId', (int) $filterId);
        }

        $filterName = trim((string) ($filters['name'] ?? ''));
        if ($filterName !== '') {
            $qb->andWhere('s.name LIKE :filterName')->setParameter('filterName', '%'.$filterName.'%');
        }

        $filterKey = trim((string) ($filters['settingKey'] ?? ''));
        if ($filterKey !== '') {
            $qb->andWhere('s.settingKey LIKE :filterKey')->setParameter('filterKey', '%'.$filterKey.'%');
        }

        $filterValue = trim((string) ($filters['value'] ?? ''));
        if ($filterValue !== '') {
            $qb->andWhere('s.settingValue LIKE :filterValue')->setParameter('filterValue', '%'.$filterValue.'%');
        }

        $filterDescription = trim((string) ($filters['description'] ?? ''));
        if ($filterDescription !== '') {
            $qb->andWhere('s.description LIKE :filterDescription')->setParameter('filterDescription', '%'.$filterDescription.'%');
        }
        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        /** @var list<AppSetting> $settings */
        $settings = $qb->select('s')->getQuery()->getResult();
        $rows = array_map(
            fn (AppSetting $s): array => $this->settingRow($s),
            $settings
        );

        $categories = array_values(array_unique(array_map(static fn (array $row): string => $row['category'], $rows)));
        sort($categories);
        $tabs = array_merge(['All'], $categories);

        $activeTab = trim((string) $request->query->get('tab', 'All'));
        if (!in_array($activeTab, $tabs, true)) {
            $activeTab = 'All';
        }
        if ($activeTab !== 'All') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['category'] === $activeTab));
        }

        $total = count($rows);

        usort($rows, function (array $left, array $right) use ($sort, $dir): int {
            $leftValue = $this->normalizedSettingSortValue($left, $sort);
            $rightValue = $this->normalizedSettingSortValue($right, $sort);

            $leftBlank = $leftValue === '';
            $rightBlank = $rightValue === '';
            if ($leftBlank !== $rightBlank) {
                return $leftBlank ? 1 : -1;
            }

            if ($sort === 'id') {
                $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            } else {
                $comparison = strcasecmp($leftValue, $rightValue);
            }

            if ($comparison === 0) {
                $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }

            return $dir === 'desc' ? -$comparison : $comparison;
        });

        $pageCount = max(1, (int) ceil($total / max($limit, 1)));
        $page = max(1, min($page, $pageCount));
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('admin/config/_settings_rows.html.twig', ['settings' => $rows]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => $pageCount,
            ]);
        }

        return $this->render('admin/config/settings.html.twig', [
            'settings' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'tabs' => $tabs, 'activeTab' => $activeTab,
        ]);
    }

    private function normalizedSettingSortValue(array $row, string $sort): string
    {
        $raw = match ($sort) {
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'settingKey' => (string) ($row['key'] ?? ''),
            'value' => (string) ($row['value'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            default => (string) ($row['id'] ?? ''),
        };

        $normalized = preg_replace('/\s+/u', ' ', trim($raw));

        return $normalized === null ? '' : $normalized;
    }

    /**
     * The core settings the app self-heals into existence, and the one place that says which of them
     * belong to whoever runs the software rather than to the store — `visibility`, omitted for the
     * store's own keys. visibilityForSettingKey() reads the same array, so a Tech Support key whose
     * row is missing cannot be re-created as an ordinary store row.
     *
     * @return array<string, array{name: string, value: string|null, description: string, visibility?: string}>
     */
    private function coreSettingDefaults(AppSettings $appSettings): array
    {
        return [
            'app_name' => [
                'name' => 'App Name',
                'value' => null,
                'description' => 'Shown in the site header, invoices, and email templates.',
            ],
            'app_email' => [
                'name' => 'App Email',
                'value' => null,
                'description' => 'General contact address, used where a more specific one is not set: the seller '
                    . 'contact on invoices and quotes when Sales Email is blank, and one of the addresses '
                    . 'contact-form messages are delivered to when nothing more specific is configured. It '
                    . 'is not the From: address on any email — that is Sender From Address.',
            ],
            'support_email' => [
                'name' => 'Support Email',
                'value' => null,
                'description' => 'Shown to customers as the address to write to: the contact page, the "your '
                    . 'account email was changed" warning, and the registration email. It is also the '
                    . 'last fallback for where contact-form messages are delivered. It is not the From: '
                    . 'address on any email — that is Sender From Address — and not the Reply-To '
                    . 'either — that is Sender Reply-To Address.',
            ],
            'tech_support_email' => [
                'name' => 'Tech Support Email',
                'value' => null,
                'description' => 'Where system notifications about this installation are delivered — currently '
                    . 'the "database console was opened" security alert, and nothing else. A recipient '
                    . 'address rather than a displayed one: it is never shown to customers, and it is not '
                    . 'the From: address on any email (that is Sender From Address). This is the team that '
                    . 'runs the software, not the store: customer-facing support is Support Email.',
                // The alert names the admin who took raw SQL access, their IP and the time, so it is
                // the operator's own address and not the store's to read or redirect (#351).
                'visibility' => AppSetting::VISIBILITY_TECH_SUPPORT,
            ],
            'billing_email' => [
                'name' => 'Billing Email',
                'value' => null,
                'description' => 'Accounts receivable / billing contact email address.',
            ],
            'sales_email' => [
                'name' => 'Sales Email',
                'value' => null,
                'description' => self::SALES_EMAIL_DESCRIPTION,
            ],
            'company_name' => [
                'name' => 'Company Name',
                'value' => null,
                'description' => 'Legal or trading name shown on invoices and emails (if used).',
            ],
            'company_phone' => [
                'name' => 'Company Phone',
                'value' => null,
                'description' => 'Primary phone number for the business.',
            ],
            'company_address' => [
                'name' => 'Company Address',
                'value' => null,
                'description' => 'Street address for invoices and contact details.',
            ],
            'company_city' => [
                'name' => 'Company City',
                'value' => null,
                'description' => 'City for invoices and contact details.',
            ],
            'company_state' => [
                'name' => 'Company State/Province',
                'value' => null,
                'description' => 'State or province for invoices and contact details.',
            ],
            'company_postal_code' => [
                'name' => 'Company Postal Code',
                'value' => null,
                'description' => 'Postal/ZIP code for invoices and contact details.',
            ],
            'company_country' => [
                'name' => 'Company Country',
                'value' => null,
                'description' => 'Country for invoices and contact details.',
            ],
            'company_gst_number' => [
                'name' => 'Company GST/Tax Number',
                'value' => null,
                'description' => 'Your own GST/HST (or equivalent tax registration) number, printed on invoices and quotes.',
            ],
            'website_url' => [
                'name' => 'Website URL',
                'value' => null,
                'description' => 'Public website URL used in emails/footers (if used).',
            ],
            'logo_url' => [
                'name' => 'Logo URL',
                'value' => null,
                'description' => 'Absolute or relative URL to a logo image used in UI/emails (if used).',
            ],
            'product_image_placeholder_url' => [
                'name' => 'Product Image Placeholder URL',
                'value' => null,
                'description' => 'Path to the fallback image shown in the customer catalog when a product has no image.',
            ],
            'timezone' => [
                'name' => 'Timezone',
                'value' => null,
                'description' => 'IANA timezone identifier (e.g. America/New_York or Asia/Kolkata) used to display every date/time '
                    . 'across the site, PDFs, and emails. All storage stays UTC regardless of this value. Blank or an unrecognised '
                    . 'identifier falls back to UTC.',
            ],
            'base_currency' => [
                'name' => 'Base Currency',
                'value' => null,
                'description' => 'Used for product pricing defaults, price-list display, and order totals.',
            ],
            'fulfillment_regions' => [
                'name' => 'Fulfillment Regions',
                // The names FulfillmentRegionSeeder actually creates, not a second copy of them.
                'value' => implode("\n", FulfillmentRegionSeeder::SHIPPED_REGION_NAMES),
                'description' => self::FULFILLMENT_REGIONS_DESCRIPTION,
            ],
            'invoice_payment_note' => [
                'name' => 'Invoice Payment Note',
                'value' => null,
                'description' => 'Shown on invoices to explain how customers should pay.',
            ],
            'invoice_footer_note' => [
                'name' => 'Invoice Footer Note',
                'value' => null,
                'description' => 'Optional note printed in the invoice footer (if used).',
            ],
            'email_footer_copyright' => [
                'name' => 'Email Footer Copyright',
                'value' => '© ' . date('Y') . ' ' . $appSettings->siteName() . '. All rights reserved.',
                'description' => 'Shared footer copyright line used in email templates.',
            ],
            'email_footer_address' => [
                'name' => 'Email Footer Address',
                'value' => null,
                'description' => 'Shared footer address line used in email templates.',
            ],
            'terms_url' => [
                'name' => 'Terms URL',
                'value' => null,
                'description' => 'Link to Terms & Conditions page (if used).',
            ],
            'privacy_url' => [
                'name' => 'Privacy URL',
                'value' => null,
                'description' => 'Link to Privacy Policy page (if used).',
            ],
            'returns_url' => [
                'name' => 'Returns URL',
                'value' => null,
                'description' => 'Link to Returns/Refund policy page (if used).',
            ],
            'guest_catalog_visible' => [
                'name' => 'Guest Catalog Visible',
                'value' => 'Yes',
                'description' => 'Whether non-logged-in visitors can browse the catalog at all. Yes or No.',
            ],
            'guest_catalog_default_url' => [
                'name' => 'Guest Catalog Default URL',
                'value' => null,
                'description' => 'Where guests are redirected when Guest Catalog Visible is No. Leave blank to redirect to the login page.',
            ],
            'header_logo_only' => [
                'name' => 'Header Logo Only',
                'value' => 'No',
                'description' => 'If Yes, the top-left header shows only the logo, hiding the company name and Admin Console / Buyer Portal text. Yes or No.',
            ],
            'checkout_coupons_enabled' => [
                'name' => 'Checkout Coupons Enabled',
                'value' => 'Yes',
                'description' => 'Whether customers may use coupon codes. If No, the coupon field is hidden at cart/checkout and on order payment, and any submitted coupon code is rejected. Yes or No.',
            ],
        ];
    }

    private function ensureCoreSettingsExist(EntityManagerInterface $entityManager, AppSettings $appSettings): void
    {
        $defaults = $this->coreSettingDefaults($appSettings);

        $keys = array_keys($defaults);
        /** @var list<string> $existingKeys */
        $existingKeys = $entityManager->createQueryBuilder()
            ->select('s.settingKey')
            ->from(AppSetting::class, 's')
            ->where('s.settingKey IN (:keys)')
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getSingleColumnResult();

        $existingKeys = array_values(array_unique(array_map('strval', $existingKeys)));
        $existingMap = array_fill_keys($existingKeys, true);

        $created = 0;
        foreach ($defaults as $key => $meta) {
            if (isset($existingMap[$key])) {
                continue;
            }

            $setting = (new AppSetting())
                ->setSettingKey($key)
                ->setName((string) $meta['name'])
                ->setDescription((string) ($meta['description'] ?? ''))
                // Without this a self-healed row would come back as store-visible, quietly undoing
                // the boundary for any installation whose row went missing.
                ->setVisibility((string) ($meta['visibility'] ?? AppSetting::VISIBILITY_STORE));

            $value = $meta['value'];
            $setting->setSettingValue($value !== null && trim((string) $value) !== '' ? (string) $value : null);

            $entityManager->persist($setting);
            $created++;
        }

        if ($created > 0) {
            $entityManager->flush();
            $appSettings->clearCache();
        }
    }

    #[Route('/settings/create', name: 'admin_setting_create', methods: ['GET', 'POST'])]
    public function createSetting(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $setting = new AppSetting();

        return $this->handleSettingForm($request, $entityManager, $appSettings, $setting, 'created');
    }

    #[Route('/settings/{id}/update', name: 'admin_setting_update', methods: ['GET', 'POST'])]
    public function updateSetting(int $id, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $setting = $entityManager->find(AppSetting::class, $id);
        if (!$setting instanceof AppSetting) {
            $this->addFlash('error', 'Setting could not be found.');

            return $this->redirectToRoute('admin_settings');
        }

        $this->denyUnlessSettingIsVisible($setting);

        return $this->handleSettingForm($request, $entityManager, $appSettings, $setting, 'updated');
    }

    #[Route('/settings/{id}/delete', name: 'admin_setting_delete', methods: ['POST'])]
    public function deleteSetting(int $id, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): JsonResponse
    {
        $setting = $entityManager->find(AppSetting::class, $id);
        if (!$setting instanceof AppSetting) {
            return new JsonResponse(['ok' => false, 'message' => 'Setting could not be found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyUnlessSettingIsVisible($setting);

        if ($this->isProtectedSettingKey($setting->getSettingKey())) {
            return new JsonResponse(['ok' => false, 'message' => 'This setting cannot be deleted.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $setting->getName();
        $entityManager->remove($setting);
        $entityManager->flush();
        $appSettings->clearCache();

        return new JsonResponse(['ok' => true, 'message' => sprintf('Setting "%s" was deleted successfully.', $name)]);
    }

    #[Route('/settings/base-currency', name: 'admin_base_currency', methods: ['GET', 'POST'])]
    public function baseCurrency(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'base_currency']);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())
                ->setSettingKey('base_currency')
                ->setName('Base Currency')
                ->setDescription('Used for product pricing defaults, price-list display, and order totals.');
            $entityManager->persist($setting);
        }

        if ($request->isMethod('POST')) {
            $currency = strtoupper(trim((string) $request->request->get('setting_value', '')));
            $violations = Validation::createValidator()->validate($currency, new ValidCurrencyCode());
            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());
            } else {
                $setting->setSettingValue($currency)->touch();
                $entityManager->flush();
                $appSettings->clearCache();
                $this->addFlash('success', sprintf('Base Currency was updated to %s.', $currency));

                return $this->redirectToRoute('admin_base_currency');
            }
        }

        return $this->render('admin/config/base_currency.html.twig', [
            'setting' => $this->settingRow($setting),
        ]);
    }

    #[Route('/settings/registration', name: 'admin_registration_settings', methods: ['GET', 'POST'])]
    public function registrationSettings(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $mode = $appSettings->get('company_registration_mode', 'review') ?: 'review';
        $defaultPriceListId = $appSettings->get('company_registration_default_price_list_id', '') ?: '';
        $defaultRegionId = $appSettings->get('company_registration_default_fulfillment_region_id', '') ?: '';

        if ($request->isMethod('POST')) {
            $mode = $request->request->get('mode', 'review') === 'auto' ? 'auto' : 'review';
            $defaultPriceListId = trim((string) $request->request->get('default_price_list_id', ''));
            $defaultRegionId = trim((string) $request->request->get('default_fulfillment_region_id', ''));

            $violations = Validation::createValidator()->validate($mode, new ValidRegistrationSettingsRequest($defaultPriceListId, $defaultRegionId, $entityManager));

            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());
            } else {
                $this->upsertAppSetting($entityManager, 'company_registration_mode', 'Customer Registration Mode', $mode, 'Whether newly self-registered customers are auto-approved (Active immediately) or held for manual review.');
                $this->upsertAppSetting($entityManager, 'company_registration_default_price_list_id', 'Registration Default Price List', $defaultPriceListId, 'Price list assigned to auto-approved customers\' default fulfillment region.');
                $this->upsertAppSetting($entityManager, 'company_registration_default_fulfillment_region_id', 'Registration Default Fulfillment Region', $defaultRegionId, 'Fulfillment region activated for auto-approved customers.');
                $entityManager->flush();
                $appSettings->clearCache();
                $this->addFlash('success', 'Registration settings were updated successfully.');

                return $this->redirectToRoute('admin_registration_settings');
            }
        }

        return $this->render('admin/config/registration_settings.html.twig', [
            'mode' => $mode,
            'defaultPriceListId' => $defaultPriceListId,
            'defaultRegionId' => $defaultRegionId,
            'priceLists' => $entityManager->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
            'regions' => $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']),
        ]);
    }

    #[Route('/settings/branding', name: 'admin_branding', methods: ['GET', 'POST'])]
    public function brandingSettings(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $companyName = $appSettings->get('app_name', '') ?: '';
        $logoUrl = $appSettings->get('logo_url', '') ?: '';
        $faviconUrl = $appSettings->get('favicon_url', '') ?: '';
        $productImagePlaceholderUrl = $appSettings->get('product_image_placeholder_url', '') ?: '';

        if ($request->isMethod('POST')) {
            $companyName = trim((string) $request->request->get('company_name', ''));
            $nameViolations = Validation::createValidator()->validate($companyName, new Assert\NotBlank(message: 'Company Name is required.'));
            if (count($nameViolations) > 0) {
                $this->addFlash('error', (string) $nameViolations[0]->getMessage());

                return $this->render('admin/config/branding.html.twig', [
                    'companyName' => $companyName,
                    'logoUrl' => $logoUrl,
                    'faviconUrl' => $faviconUrl,
                    'productImagePlaceholderUrl' => $productImagePlaceholderUrl,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $allowedImageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

            $logoFile = $request->files->get('logo');
            if ($logoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $logoViolations = Validation::createValidator()->validate($logoFile, new ValidUploadedImage(
                    $allowedImageMimes,
                    5 * 1024 * 1024,
                    'Logo must be a JPG, PNG, WebP, GIF, or SVG file.',
                    'Logo must be smaller than 5 MB.',
                ));
                if (count($logoViolations) > 0) {
                    $this->addFlash('error', (string) $logoViolations[0]->getMessage());
                } else {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/branding';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $filename = bin2hex(random_bytes(10)) . '.' . ($logoFile->guessExtension() ?? 'png');
                    $logoFile->move($uploadDir, $filename);

                    $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $logoUrl;
                    if ($logoUrl !== '' && is_file($oldPath)) {
                        @unlink($oldPath);
                    }

                    $logoUrl = '/uploads/branding/' . $filename;
                }
            } elseif ($request->request->getBoolean('remove_logo') && $logoUrl !== '') {
                $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $logoUrl;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }

                $logoUrl = '';
            }

            // Favicon: accepts .ico as well as the standard image types. Stored like the logo.
            $allowedFaviconMimes = array_merge($allowedImageMimes, ['image/x-icon', 'image/vnd.microsoft.icon']);
            $faviconFile = $request->files->get('favicon');
            if ($faviconFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $faviconViolations = Validation::createValidator()->validate($faviconFile, new ValidUploadedImage(
                    $allowedFaviconMimes,
                    1024 * 1024,
                    'Favicon must be an ICO, PNG, SVG, WebP, GIF, or JPG file.',
                    'Favicon must be smaller than 1 MB.',
                ));
                if (count($faviconViolations) > 0) {
                    $this->addFlash('error', (string) $faviconViolations[0]->getMessage());
                } else {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/branding';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $filename = bin2hex(random_bytes(10)) . '.' . ($faviconFile->guessExtension() ?? 'ico');
                    $faviconFile->move($uploadDir, $filename);

                    $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $faviconUrl;
                    if ($faviconUrl !== '' && is_file($oldPath)) {
                        @unlink($oldPath);
                    }

                    $faviconUrl = '/uploads/branding/' . $filename;
                }
            } elseif ($request->request->getBoolean('remove_favicon') && $faviconUrl !== '') {
                $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $faviconUrl;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }

                $faviconUrl = '';
            }

            $placeholderFile = $request->files->get('product_image_placeholder');
            if ($placeholderFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $placeholderViolations = Validation::createValidator()->validate($placeholderFile, new ValidUploadedImage(
                    $allowedImageMimes,
                    5 * 1024 * 1024,
                    'Product image placeholder must be a JPG, PNG, WebP, GIF, or SVG file.',
                    'Product image placeholder must be smaller than 5 MB.',
                ));
                if (count($placeholderViolations) > 0) {
                    $this->addFlash('error', (string) $placeholderViolations[0]->getMessage());
                } else {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/branding';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $filename = bin2hex(random_bytes(10)) . '.' . ($placeholderFile->guessExtension() ?? 'png');
                    $placeholderFile->move($uploadDir, $filename);

                    $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $productImagePlaceholderUrl;
                    if ($productImagePlaceholderUrl !== '' && is_file($oldPath)) {
                        @unlink($oldPath);
                    }

                    $productImagePlaceholderUrl = '/uploads/branding/' . $filename;
                }
            } elseif ($request->request->getBoolean('remove_product_image_placeholder') && $productImagePlaceholderUrl !== '') {
                $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $productImagePlaceholderUrl;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }

                $productImagePlaceholderUrl = '';
            }

            $this->upsertAppSetting($entityManager, 'app_name', 'App Name', $companyName, 'Shown in the site header, invoices, and email templates.');
            $this->upsertAppSetting($entityManager, 'logo_url', 'Logo URL', $logoUrl, 'Path to the logo image shown in the site header.');
            $this->upsertAppSetting($entityManager, 'favicon_url', 'Favicon URL', $faviconUrl, 'Path to the favicon shown in the browser tab.');
            $this->upsertAppSetting($entityManager, 'product_image_placeholder_url', 'Product Image Placeholder URL', $productImagePlaceholderUrl, 'Path to the fallback image shown in the customer catalog when a product has no image.');
            $entityManager->flush();
            $appSettings->clearCache();
            $this->addFlash('success', 'Branding settings were updated successfully.');

            return $this->redirectToRoute('admin_branding');
        }

        return $this->render('admin/config/branding.html.twig', [
            'companyName' => $companyName,
            'logoUrl' => $logoUrl,
            'faviconUrl' => $faviconUrl,
            'productImagePlaceholderUrl' => $productImagePlaceholderUrl,
        ]);
    }

    /**
     * Shared by coreSettingDefaults() and COMPANY_INFO_FIELDS below, which both re-assert this row
     * — the first when it is missing, the second every time the Company Information form is saved.
     * They disagreed for as long as it was two literals; the copy has to match what
     * Version20260806030000 wrote or saving that form silently reverts it (#474).
     */
    private const SALES_EMAIL_DESCRIPTION = 'Shown as the seller\'s contact address on invoices and '
        . 'quotes (on screen and in the PDFs), in the storefront header, and on the contact page. '
        . 'Display only: no mail is delivered here, and it is not the From: address on any email — '
        . 'that is Sender From Address.';

    /**
     * The seller identity block printed at the top of every invoice/quote, plus the GST/tax
     * number and the payment note in the invoice footer. All of these were already AppSetting
     * keys read by the templates, but the only way to edit them was the raw key/value Config
     * grid — which meant nobody found them and the templates' (brand-specific) fallbacks were
     * what actually shipped. See issue #35, items 2, 5 and 7.
     */
    private const COMPANY_INFO_FIELDS = [
        'company_name' => ['name' => 'Company Name', 'description' => 'Legal or trading name shown on invoices and emails (if used).'],
        'company_address' => ['name' => 'Company Address', 'description' => 'Street address for invoices and contact details.'],
        'company_city' => ['name' => 'Company City', 'description' => 'City for invoices and contact details.'],
        'company_state' => ['name' => 'Company State/Province', 'description' => 'State or province for invoices and contact details.'],
        'company_postal_code' => ['name' => 'Company Postal Code', 'description' => 'Postal/ZIP code for invoices and contact details.'],
        'company_country' => ['name' => 'Company Country', 'description' => 'Country for invoices and contact details.'],
        'company_phone' => ['name' => 'Company Phone', 'description' => 'Primary phone number for the business.'],
        'sales_email' => ['name' => 'Sales Email', 'description' => self::SALES_EMAIL_DESCRIPTION],
        'website_url' => ['name' => 'Website URL', 'description' => 'Public website URL used in emails/footers (if used).'],
        'company_gst_number' => ['name' => 'Company GST/Tax Number', 'description' => 'Your own GST/HST (or equivalent tax registration) number, printed on invoices and quotes.'],
        'invoice_payment_note' => ['name' => 'Invoice Payment Note', 'description' => 'Shown on invoices to explain how customers should pay.'],
    ];

    #[Route('/settings/company-information', name: 'admin_company_info', methods: ['GET', 'POST'])]
    public function companyInformation(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings, Region $region): Response
    {
        $values = [];
        foreach (array_keys(self::COMPANY_INFO_FIELDS) as $key) {
            $values[$key] = (string) ($appSettings->get($key, '') ?? '');
        }

        // These two were free-text inputs, so whatever is stored may be a display name ("Canada",
        // "British Columbia"), a code, or a typo. Normalising on read means the existing value shows
        // up selected in the dropdown instead of silently resetting to the first option.
        $values['company_country'] = $region->normalizeCountry($values['company_country']) ?? '';
        $values['company_state'] = $values['company_country'] !== ''
            ? ($region->normalizeProvince($values['company_country'], $values['company_state']) ?? '')
            : '';

        if ($request->isMethod('POST')) {
            $submitted = [];
            foreach (array_keys(self::COMPANY_INFO_FIELDS) as $key) {
                $submitted[$key] = trim((string) $request->request->get($key, ''));
            }

            $submittedBag = new \ArrayObject($submitted);
            $violations = Validation::createValidator()->validate($submittedBag, new ValidCompanyInfoRegion($region));
            $submitted = $submittedBag->getArrayCopy();

            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());

                return $this->redirectToRoute('admin_company_info');
            }

            foreach (self::COMPANY_INFO_FIELDS as $key => $meta) {
                $values[$key] = $submitted[$key];
                $this->upsertAppSetting($entityManager, $key, $meta['name'], $values[$key], $meta['description']);
            }

            $entityManager->flush();
            $appSettings->clearCache();
            $this->addFlash('success', 'Company information was updated successfully.');

            return $this->redirectToRoute('admin_company_info');
        }

        return $this->render('admin/config/company_info.html.twig', ['values' => $values]);
    }

    /**
     * Document Prefixes — one field per registered prefix, not per prefix this method knows about.
     *
     * Rewritten for #615. It used to name three prefixes in three variables, which is why credit
     * memos (#586) and sales returns (#596) shipped with a `*_number_prefix` setting their
     * generator honoured but no screen could set, and why ProcurementBundle grew a prefix screen of
     * its own. The list now comes from DocumentPrefixCatalogue, so a document type that registers a
     * provider gets a field and one that does not gets caught by
     * DocumentPrefixCatalogueCest rather than by a customer.
     *
     * Two behaviours are preserved deliberately, because tests and callers depend on them:
     *
     * - **A submission is accepted or rejected as a SET.** One bad prefix writes none of them, with
     *   one shared message. AdminDocumentPrefixesCest asserts exactly that.
     * - **A field that was not submitted is left alone**, rather than read as an empty string. This
     *   is new, and it is what made a fourth field possible at all: while every field was
     *   `required` and absence meant `''`, adding one would have made every existing POST that
     *   omitted it fail validation and reject the whole set — the reason both #586 and #596 recorded
     *   for not exposing their prefix. Not-submitted and submitted-empty stay distinct.
     */
    #[Route('/settings/document-prefixes', name: 'admin_document_prefixes', methods: ['GET', 'POST'])]
    public function documentPrefixes(
        Request $request,
        EntityManagerInterface $entityManager,
        AppSettings $appSettings,
        DocumentPrefixCatalogue $documentPrefixes,
    ): Response {
        $definitions = $documentPrefixes->all();

        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $appSettings->get($definition->key, $definition->defaultPrefix) ?: $definition->defaultPrefix;
        }

        if ($request->isMethod('POST')) {
            $submitted = [];
            foreach ($definitions as $definition) {
                if ($request->request->has($definition->key)) {
                    $submitted[$definition->key] = strtoupper(trim((string) $request->request->get($definition->key, '')));
                }
            }

            $violations = Validation::createValidator()->validate(
                new \ArrayObject($submitted),
                new ValidDocumentPrefixes(),
            );
            if (count($violations) > 0) {
                // $values is left as read from storage, so a rejected submission re-renders the
                // prefixes still in force rather than the typed ones. That is what the
                // three-variable version did, and it is the honest thing to show: nothing was
                // written, and the fields should say what the next document will actually use.
                $this->addFlash('error', (string) $violations[0]->getMessage());
            } else {
                foreach ($definitions as $definition) {
                    if (!\array_key_exists($definition->key, $submitted)) {
                        continue;
                    }

                    $values[$definition->key] = $submitted[$definition->key];
                    $this->upsertAppSetting(
                        $entityManager,
                        $definition->key,
                        $definition->settingName,
                        $submitted[$definition->key],
                        $definition->settingDescription,
                    );
                }

                $entityManager->flush();
                $appSettings->clearCache();
                $this->addFlash('success', 'Document prefixes were updated successfully.');

                return $this->redirectToRoute('admin_document_prefixes');
            }
        }

        return $this->render('admin/config/document_prefixes.html.twig', [
            'prefixes' => $definitions,
            'values' => $values,
        ]);
    }

    #[Route('/email-template', name: 'admin_email_template', methods: ['GET'])]
    public function emailTemplates(EmailTemplateResolver $emailTemplates, Request $request): Response
    {
        $page  = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        $search = $request->query->get('q', '');
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }

        // The catalogue leads, not the table (#507). The 22 shipped templates resolve from code with
        // no row present, so a fresh install has an EMPTY email_template table — querying the table
        // here would show an empty screen for 22 templates that all exist and all send.
        //
        // Filtering and sorting move into PHP with it. That is not a compromise: the whole list is
        // the shipped catalogue plus however many templates an admin has authored, which is tens of
        // items, and half of what is filtered on is no longer a column to filter by.
        $templates = $emailTemplates->all();

        $matches = static function (string $haystack, string $needle): bool {
            return $needle === '' || stripos($haystack, $needle) !== false;
        };

        if ($search !== '') {
            // Body stays out of the free-text search, as it was before — see the note this replaces.
            $templates = array_values(array_filter(
                $templates,
                static fn ($t): bool => $matches($t->module, (string) $search) || $matches($t->subject, (string) $search),
            ));
        }

        foreach (['module' => 'module', 'subject' => 'subject', 'body' => 'body', 'description' => 'description'] as $key => $field) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $templates = array_values(array_filter(
                $templates,
                static fn ($t): bool => $matches((string) $t->{$field}, $value),
            ));
        }

        foreach (['sentTo', 'status'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $templates = array_values(array_filter($templates, static fn ($t): bool => $t->{$field} === $value));
        }

        $total = count($templates);

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? -1 : 1;
        $key = match ($sort) {
            'module' => 'module',
            'sentTo' => 'sentTo',
            'subject' => 'subject',
            'body' => 'body',
            'description' => 'description',
            'status' => 'status',
            default => null,
        };

        // Default order is the catalogue's own, which is what `id` used to approximate and no longer
        // can — most templates have no id at all now.
        if ($key !== null) {
            usort($templates, static fn ($a, $b): int => $dir * strcasecmp((string) $a->{$key}, (string) $b->{$key}));
        }

        // Bodies are full strings in memory rather than a SUBSTRING in SQL, so the preview is
        // trimmed here instead. Same 600-character budget the query used.
        $previewLimit = 600;
        $rows = array_map(static function ($t) use ($previewLimit): array {
            $body = $t->body;
            if (mb_strlen($body) > $previewLimit) {
                $body = rtrim(mb_substr($body, 0, $previewLimit)) . "\n…";
            }

            return [
                'id' => (string) ($t->id ?? ''),
                'code' => $t->code,
                'module' => $t->module,
                'sentTo' => $t->sentTo,
                'subject' => $t->subject,
                'body' => $body,
                'description' => $t->description ?? '',
                'status' => $t->status,
                // Drives the Default/Modified/Custom badge and whether "Revert to default" is offered.
                'origin' => !$t->isShipped ? 'Custom' : ($t->isCustomized ? 'Modified' : 'Default'),
            ];
        }, array_slice($templates, ($page - 1) * $limit, $limit));

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('admin/config/_email_template_rows.html.twig', ['templates' => $rows]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        return $this->render('admin/config/email_templates.html.twig', [
            'templates' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit,
        ]);
    }

    #[Route('/email-template/create', name: 'admin_email_template_create', methods: ['GET', 'POST'])]
    public function createEmailTemplate(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $template = new EmailTemplate();

        return $this->handleEmailTemplateForm($request, $entityManager, $appSettings, $template, 'created');
    }

    /**
     * Keyed by CODE rather than by row id (#507).
     *
     * Most templates no longer have a row to have an id: the 22 shipped ones resolve from code, and
     * a row appears only once an admin saves a change. An id-keyed edit route could not open any of
     * them. The code is stable, is already the join key between a row and its shipped counterpart,
     * and is the one field the form deliberately never rewrites.
     */
    #[Route('/email-template/{code}/update', name: 'admin_email_template_update', methods: ['GET', 'POST'])]
    public function updateEmailTemplate(string $code, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings, EmailTemplateResolver $emailTemplates): Response
    {
        $resolved = $emailTemplates->resolve($code);
        if ($resolved === null) {
            $this->addFlash('error', 'Email template could not be found.');

            return $this->redirectToRoute('admin_email_template');
        }

        // Find-or-new. A shipped template being edited for the first time has no row yet, and gets
        // one only if the admin actually saves something that differs from what ships.
        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy(['code' => $code])
            ?? (new EmailTemplate())->setCode($code);

        return $this->handleEmailTemplateForm($request, $entityManager, $appSettings, $template, 'updated', $emailTemplates);
    }

    /**
     * Discard an admin's changes to a shipped template by deleting the row entirely, so it goes
     * back to inheriting from ShippedEmailTemplates.
     *
     * This is the feature that replaces a data migration. Deciding which rows still match what ships
     * is a comparison against admin-editable text, and a migration making that call gets it wrong
     * silently and irreversibly — Version20260805020000 exists because exactly that comparison
     * matched nothing on some databases. Here the same judgement is a badge in the list and a button
     * beside it, so being wrong costs a glance instead of an admin's work.
     */
    #[Route('/email-template/{code}/revert', name: 'admin_email_template_revert', methods: ['POST'])]
    public function revertEmailTemplate(string $code, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        // The catalogue, not the static (#563): a bundle-supplied template ships just as much as a
        // core one, so it reverts on the same terms. Gating on ShippedEmailTemplates alone left an
        // admin able to edit a bundle template and then unable to undo it.
        if (!$this->emailCatalogue->has($code)) {
            $this->addFlash('error', 'Only templates that ship with the application can be reverted.');

            return $this->redirectToRoute('admin_email_template');
        }

        $row = $entityManager->getRepository(EmailTemplate::class)->findOneBy(['code' => $code]);
        if ($row instanceof EmailTemplate) {
            $entityManager->remove($row);
            $entityManager->flush();
            $appSettings->clearCache();
        }

        $this->addFlash('success', sprintf('Email template "%s" was reset to the version that ships with the application.', $code));

        return $this->redirectToRoute('admin_email_template');
    }

    #[Route('/email-template/preview/{code}', name: 'admin_email_template_preview', methods: ['GET', 'POST'], defaults: ['code' => null])]
    public function previewEmailTemplate(
        ?string $code,
        Request $request,
        EntityManagerInterface $entityManager,
        SandboxedTemplateRenderer $templateRenderer,
        CustomerUrlGenerator $customerUrlGenerator,
        AdminUrlGenerator $adminUrlGenerator,
        AppSettings $appSettings,
        EmailTemplateResolver $emailTemplates,
    ): Response {
        $resolved = $code !== null ? $emailTemplates->resolve($code) : null;
        if ($code !== null && $resolved === null) {
            $this->addFlash('error', 'Email template could not be found.');
            return $this->redirectToRoute('admin_email_template');
        }

        // Carries the resolved module through to the mock-data picker, which chooses its fixture by
        // module name. A preview of a shipped template with no row still has to get order mock data.
        $template = $entityManager->getRepository(EmailTemplate::class)->findOneBy(['code' => (string) $code])
            ?? (new EmailTemplate())->setCode((string) $code)->setModule($resolved?->module);

        try {
            $body = $resolved?->body ?? '';
            if ($request->isMethod('POST')) {
                $body = (string) $request->request->get('body', $body);
            }

            // Auto-wrap in layout if not present, or if block is missing
            $body = trim($body);
            if (!str_contains($body, '{% extends') || !str_contains($body, '{% block')) {
                $body = preg_replace('/^{%\s*extends\s+[^%]+%}\s*/i', '', $body);
                $body = "{% extends 'emails/layout.html.twig' %}\n{% block body %}\n" . trim($body) . "\n{% endblock %}";
            }

            $mockData = $this->getMockDataForTemplate($template, $customerUrlGenerator, $adminUrlGenerator, $appSettings);
            $rendered = $templateRenderer->render($body, $mockData);
            
            return new Response($rendered);
        } catch (\Exception $e) {
            return new Response(sprintf('<!DOCTYPE html><html><body style="margin:0; padding: 40px; background: #f8fafc; font-family: sans-serif; display: flex; justify-content: center;">
                <div style="width: 100%%; max-width: 600px; padding: 25px; color: #b42318; background: #fff1f2; border: 1.5px solid #fecdd3; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <h3 style="margin: 0 0 10px 0; font-size: 1.2rem;">Template Render Error</h3>
                    <p style="margin: 0 0 15px 0; line-height: 1.5; font-size: 0.95rem;">%s</p>
                    <p style="margin: 0; font-size: 0.85rem; color: #9f1239; font-weight: bold;">Check your Twig syntax in the template body.</p>
                </div>
            </body></html>', htmlspecialchars($e->getMessage())));
        }
    }

    /** @return array<string, mixed> */
    private function getMockDataForTemplate(
        EmailTemplate $template,
        CustomerUrlGenerator $customerUrlGenerator,
        AdminUrlGenerator $adminUrlGenerator,
        AppSettings $appSettings,
    ): array {
        // Fields below `items` mirror $mockEstimate's shape (#411) — _order_summary.html.twig
        // reads order.effectiveBillingAddress/companyIdentity/company.firstName/fulfillmentRegion/
        // poNumber/lines/subtotal/shippingLines/tax directly, several without |default(), and
        // strict_variables blows up the whole preview if any of them is absent from this array.
        $mockOrder = [
            'orderNumber' => 'PO-2026-001',
            'date'        => date('F j, Y'),
            // A 'Y-m-d' string, matching what the real column now holds — see
            // AbstractSalesDocument::$documentDate. A date object here would let the preview render
            // through a path the live template no longer has.
            'documentDate' => date('Y-m-d'),
            'total'       => 1250.00,
            'company'     => ['name' => 'Acme Wholesale Corp', 'firstName' => 'John', 'lastName' => 'Doe'],
            'billingName' => 'John Doe',
            'shippingName' => 'John Doe',
            'shippingAddress' => '123 Business Way, Suite 100, New York, NY 10001',
            'items' => [
                ['name' => 'Premium Widgets', 'sku' => 'WIDG-001', 'quantity' => 10, 'price' => '50.00', 'total' => '500.00'],
                ['name' => 'Deluxe Gadgets',  'sku' => 'GADG-999', 'quantity' => 5,  'price' => '150.00', 'total' => '750.00'],
            ],
            'companyIdentity' => ['name' => 'Acme Wholesale Corp', 'tradeName' => null, 'email' => 'accounts@acmewholesale.test', 'phone' => '555-0100'],
            'effectiveBillingAddress' => null,
            'effectiveShippingAddress' => null,
            'fulfillmentRegion' => null,
            'poNumber' => 'PO-2026-001',
            'lines' => [
                ['id' => 1, 'name' => 'Premium Widgets', 'sku' => 'WIDG-001', 'unit' => null, 'unitOfMeasure' => null, 'quantity' => 10, 'quantityEntered' => 10, 'subtotal' => 500.00],
                ['id' => 2, 'name' => 'Deluxe Gadgets', 'sku' => 'GADG-999', 'unit' => null, 'unitOfMeasure' => null, 'quantity' => 5, 'quantityEntered' => 5, 'subtotal' => 750.00],
            ],
            'subtotal' => 1250.00,
            'tax' => 0.0,
            'shippingLines' => [],
        ];

        // Mirrors SalesDocumentNotifier::estimateContext() (#381): _quote_summary.html.twig and
        // the quote_request_received/quote_request_admin/quote_provided templates all read
        // estimate.* directly, and strict_variables blows up the whole preview if it's absent
        // rather than just leaving a blank field like the |default() cases below.
        $mockEstimate = [
            'documentNumber' => 'QT-2026-001',
            'createdAt' => new \DateTimeImmutable(),
            'companyIdentity' => ['name' => 'Acme Wholesale Corp', 'tradeName' => null, 'email' => 'accounts@acmewholesale.test', 'phone' => '555-0100'],
            'company' => ['firstName' => 'John', 'lastName' => 'Doe'],
            'effectiveBillingAddress' => null,
            'effectiveShippingAddress' => null,
            'billingName' => 'John Doe',
            'shippingName' => 'John Doe',
            'fulfillmentRegion' => null,
            'poNumber' => 'PO-2026-001',
            'lines' => [
                ['id' => 1, 'name' => 'Premium Widgets', 'sku' => 'WIDG-001', 'unit' => null, 'unitOfMeasure' => null, 'quantity' => 10, 'quantityEntered' => 10, 'subtotal' => 500.00],
                ['id' => 2, 'name' => 'Deluxe Gadgets', 'sku' => 'GADG-999', 'unit' => null, 'unitOfMeasure' => null, 'quantity' => 5, 'quantityEntered' => 5, 'subtotal' => 750.00],
            ],
            'subtotal' => 1250.00,
            'tax' => 0.0,
            'total' => 1250.00,
            'shippingLines' => [],
        ];

        // invoice_customer/invoice_self are stored bodies reading invoice.* directly (#781) — a
        // separate variable from $mockOrder/$mockEstimate above, matching what InvoiceController's
        // real send actually passes ('invoice' => $invoice, the entity, alongside 'order' => $order).
        $mockInvoice = [
            'documentNumber' => 'INV-2026-001',
            'documentDate' => date('Y-m-d'),
            'total' => 1250.00,
            'company' => ['name' => 'Acme Wholesale Corp', 'firstName' => 'John', 'lastName' => 'Doe'],
        ];

        // Real host, real route — an admin previewing a link should see where it actually goes,
        // not a placeholder domain that never resolves (#381).
        $accountUrl = $customerUrlGenerator->generate('customer_profile');
        $orderUrl = $customerUrlGenerator->generate('customer_order_detail', ['id' => 100045]);
        $estimateUrl = $customerUrlGenerator->generate('customer_estimate_detail', ['id' => 100045]);

        return [
            'order' => $mockOrder,
            'estimate' => $mockEstimate,
            'invoice' => $mockInvoice,
            'fee_lines' => [],
            'expected_delivery' => null,
            'special_instructions' => null,
            'is_pickup' => false,
            'tax_lines' => [],
            'billing_province_name' => null,
            'shipping_province_name' => null,
            'user_email' => 'customer@example.test',
            'contact_name' => 'John Doe',
            'company_name' => 'Acme Wholesale Corp',
            'company_code' => 'ACME',
            'company_email' => 'accounts@acmewholesale.test',
            'old_email' => 'previous@example.test',
            'new_email' => 'customer@example.test',
            'login_url' => $customerUrlGenerator->generate('customer_login'),
            'account_url' => $accountUrl,
            'support_email' => 'support@example.test',
            'reset_url' => $customerUrlGenerator->generate('customer_account_setup', ['token' => 'preview-token']),
            'order_url' => $orderUrl,
            // Every link variable any shipped template reads needs a value here, or its button
            // previews as the literal href="#" the |default('#') fallback emits (#223).
            'security_url' => $accountUrl,
            'estimate_url' => $estimateUrl,
            'admin_url' => $adminUrlGenerator->generate('admin_estimate_detail', ['id' => 100045]),
            // The stock-shortfall alert links to the ORDER as its primary action while admin_url
            // still points at the quote, so it needs a second link of its own (#326/#327).
            'order_admin_url' => $adminUrlGenerator->generate('admin_order_detail', ['id' => 100045]),
            'status' => 'Processing',
            'message' => 'This is a sample message regarding your order items. We have updated the shipping schedule.',
            'subject' => $template->getSubject(),
            // The forgot_password/invite/new_user_invited rows print this bare since #475 — they
            // have to, because forgot_password serves two flows with different lifetimes and can
            // name neither. Under strict_variables a missing key is not a blank field but a render
            // error, so the preview would show "Template Render Error" for three of the shipped
            // rows if this were absent. The value is read from the settings rather than written as
            // a sample, so what the admin previews is what the recipient will actually be told;
            // forgot_password previews as the self-service window, since that is the flow an admin
            // opening "forgot password" is thinking of, and the admin-issued reset is the same row
            // rendered with the invite window instead.
            // Bundle-supplied templates preview through this same picker (#563), so anything they
            // read needs a value here too — under strict_variables a missing key is a render error,
            // not a blank field. ProcurementBundle's purchase_order_vendor reads `purchase_order`
            // and iterates its lines; every scalar it touches carries a |default() but the root and
            // the collection do not, and cannot.
            'purchase_order' => [
                'poNumber' => 'PO-2026-0042',
                'vendorName' => 'Northbridge Supply Co.',
                'documentDate' => date('Y-m-d'),
                'expectedDate' => date('Y-m-d', strtotime('+14 days')),
                'warehouse' => ['name' => 'Main Warehouse'],
                'paymentTerm' => 'Net 30',
                'currency' => 'CAD',
                'total' => '1,845.00',
                'lines' => [
                    ['name' => 'Steel Bracket 40mm', 'vendorSku' => 'NB-4001', 'quantityOrdered' => '120.00', 'unitCost' => '4.2500'],
                    ['name' => 'Hex Bolt M8', 'vendorSku' => 'NB-8802', 'quantityOrdered' => '500.00', 'unitCost' => '0.6900'],
                ],
            ],
            'expiry_description' => $template->getCode() === 'forgot_password'
                ? $appSettings->passwordResetExpiryDescription()
                : $appSettings->inviteTokenExpiryDescription(),
        ];
    }

    #[Route('/fulfillment-region', name: 'admin_fulfillment_region', methods: ['GET'])]
    public function fulfillmentRegions(EntityManagerInterface $entityManager, Request $request, AppSettings $appSettings): Response
    {
        // This used to open with syncFulfillmentRegionsFromSettings(), which CREATED every
        // fulfillment region on a GET — the costliest of the lazily-created reference lists, because
        // nothing can be quoted until one exists. App\Service\ReferenceData\Seeders\
        // FulfillmentRegionSeeder owns them now and creates them once, on the first admin login.
        // This action reads; the locked row is still the lowest id, exactly as before.
        $lockedId = $this->lockedFulfillmentRegionId($entityManager);

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }
        $filters = [
            'name' => trim((string) ($filters['name'] ?? '')),
            'defaultForNewCompany' => trim((string) ($filters['defaultForNewCompany'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
        ];
        $currentSort = trim((string) $request->query->get('sort', ''));
        $currentDir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findAll();
        // Queue item 61: a region CAN now exist with no warehouse. The settings seed stopped
        // creating one because it has no province to give it, so this is where an unanswered region
        // is named — one lookup for the whole page rather than a query per row.
        $warehouseIdsByRegionId = $this->warehouses->warehouseIdsByRegionId();
        $rows = array_map(
            function (FulfillmentRegion $region) use ($lockedId, $warehouseIdsByRegionId): array {
                return array_merge($this->fulfillmentRegionRow($region, $lockedId), [
                    'warehouseMissing' => isset($warehouseIdsByRegionId[$region->getId()]) ? '' : 'yes',
                ]);
            },
            $regions
        );

        $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $field => $value) {
                if ($value === '') {
                    continue;
                }

                if (!str_contains(
                    mb_strtolower((string) ($row[$field] ?? '')),
                    mb_strtolower($value)
                )) {
                    return false;
                }
            }

            return true;
        }));

        if (in_array($currentSort, ['name', 'defaultForNewCompany', 'status'], true)) {
            usort($rows, static function (array $left, array $right) use ($currentSort, $currentDir): int {
                $comparison = strcasecmp((string) ($left[$currentSort] ?? ''), (string) ($right[$currentSort] ?? ''));
                if ($comparison === 0) {
                    $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
                }

                return $currentDir === 'desc' ? -$comparison : $comparison;
            });
        } else {
            usort($rows, static function (array $left, array $right): int {
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            });
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
        }
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('admin/config/_fulfillment_region_rows.html.twig', ['regions' => $rows]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => $pages,
            ]);
        }

        return $this->render('admin/config/fulfillment_regions.html.twig', [
            'regions' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'filters' => $filters,
            'currentSort' => $currentSort,
            'currentDir' => $currentDir,
        ]);
    }

    #[Route('/fulfillment-region/create', name: 'admin_fulfillment_region_create', methods: ['GET', 'POST'])]
    public function createFulfillmentRegion(Request $request, EntityManagerInterface $entityManager, CompanyFulfillmentRegionService $companyFulfillmentRegionService): Response
    {
        return $this->handleFulfillmentRegionForm($request, $entityManager, new FulfillmentRegion(), 'created', $companyFulfillmentRegionService);
    }

    #[Route('/fulfillment-region/{id}/update', name: 'admin_fulfillment_region_update', methods: ['GET', 'POST'])]
    public function updateFulfillmentRegion(int $id, Request $request, EntityManagerInterface $entityManager, CompanyFulfillmentRegionService $companyFulfillmentRegionService): Response
    {
        $region = $entityManager->find(FulfillmentRegion::class, $id);
        if (!$region instanceof FulfillmentRegion) {
            $this->addFlash('error', 'Fulfillment region could not be found.');

            return $this->redirectToRoute('admin_fulfillment_region');
        }

        return $this->handleFulfillmentRegionForm($request, $entityManager, $region, 'updated', $companyFulfillmentRegionService);
    }

    #[Route('/fulfillment-region/{id}/delete', name: 'admin_fulfillment_region_delete', methods: ['POST'])]
    public function deleteFulfillmentRegion(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $lockedId = $this->lockedFulfillmentRegionId($entityManager);
        if ($lockedId !== null && $id === $lockedId) {
            return new JsonResponse(['ok' => false, 'message' => 'The default fulfillment region cannot be deleted.'], Response::HTTP_BAD_REQUEST);
        }

        $region = $entityManager->find(FulfillmentRegion::class, $id);
        if (!$region instanceof FulfillmentRegion) {
            return new JsonResponse(['ok' => false, 'message' => 'Fulfillment region could not be found.'], Response::HTTP_NOT_FOUND);
        }

        $name = $region->getName();

        // The warehouse serving this region goes with it, and so does its stock. One region, one
        // warehouse (#546): leaving the building behind would leave stock nobody can reach, and
        // the inventory rows have to go first regardless to avoid FK errors.
        $warehouse = $this->warehouses->warehouseForRegion($region);
        if ($warehouse instanceof Warehouse) {
            foreach ($entityManager->getRepository(ProductInventory::class)->findBy(['warehouse' => $warehouse]) as $row) {
                if ($row instanceof ProductInventory) {
                    $entityManager->remove($row);
                }
            }
        }

        $this->warehouses->removeLinksForRegion($region);
        $entityManager->flush();

        if ($warehouse instanceof Warehouse) {
            $entityManager->remove($warehouse);
        }

        $entityManager->remove($region);
        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('Fulfillment region "%s" was deleted successfully.', $name)]);
    }

    /**
     * The Warehouses screen (#546).
     *
     * Deliberately separate from Fulfillment Regions rather than a tab on it: they answer
     * different questions. This one is "where is the stock", which is why it carries no guest
     * visibility, no price list and no default-for-new-company — those decide who may buy and at
     * what price, and they live on the region.
     *
     * It shows the region each warehouse serves because that is the only thing tying the two
     * together, and an admin looking at a building needs to know which territory's orders it
     * answers for.
     */
    #[Route('/warehouse', name: 'admin_warehouse', methods: ['GET'])]
    public function warehouses(EntityManagerInterface $entityManager, Request $request, AppSettings $appSettings): Response
    {
        // This used to run the same first-run region seed the regions screen ran, so that opening
        // either screen created the rows. Neither does now — FulfillmentRegionSeeder does, on the
        // first admin login — and this action only reads.

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', 100));
        $filters = $request->query->all('filters');
        if (!is_array($filters)) {
            $filters = [];
        }
        $filters = [
            'name' => trim((string) ($filters['name'] ?? '')),
            'location' => trim((string) ($filters['location'] ?? '')),
            'fulfillmentRegion' => trim((string) ($filters['fulfillmentRegion'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
        ];
        $currentSort = trim((string) $request->query->get('sort', ''));
        $currentDir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $regionNamesByWarehouseId = $this->warehouses->regionNamesByWarehouseId();
        $rows = array_map(
            static fn (Warehouse $warehouse): array => [
                'id' => (string) ($warehouse->getId() ?? ''),
                'name' => $warehouse->getName(),
                // Where the building is (queue item 32). On the list because a warehouse with no
                // address is now a thing an admin has to be able to SEE, rather than discover from
                // a vendor bill that quietly computed no tax.
                'location' => $warehouse->getAddressSummary(),
                // Queue item 61: a row written before the province became required. It is not the
                // same thing as "no address recorded" — a warehouse can have a street and a city
                // and still price nothing — so it gets its own marker rather than being inferred
                // from an empty Location cell.
                'provinceMissing' => $warehouse->getProvince() === null ? 'yes' : '',
                'fulfillmentRegion' => $regionNamesByWarehouseId[$warehouse->getId()] ?? '',
                'status' => $warehouse->getStatus(),
            ],
            $entityManager->getRepository(Warehouse::class)->findAll(),
        );

        $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $field => $value) {
                if ($value === '') {
                    continue;
                }

                if (!str_contains(mb_strtolower((string) ($row[$field] ?? '')), mb_strtolower($value))) {
                    return false;
                }
            }

            return true;
        }));

        if (in_array($currentSort, ['name', 'location', 'fulfillmentRegion', 'status'], true)) {
            usort($rows, static function (array $left, array $right) use ($currentSort, $currentDir): int {
                $comparison = strcasecmp((string) ($left[$currentSort] ?? ''), (string) ($right[$currentSort] ?? ''));
                if ($comparison === 0) {
                    $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
                }

                return $currentDir === 'desc' ? -$comparison : $comparison;
            });
        } else {
            usort($rows, static fn (array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)));
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
        }
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html'  => $this->renderView('admin/config/_warehouse_rows.html.twig', ['warehouses' => $rows]),
                'total' => $total, 'page' => $page, 'limit' => $limit,
                'pages' => $pages,
            ]);
        }

        return $this->render('admin/config/warehouses.html.twig', [
            'warehouses' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'filters' => $filters,
            'currentSort' => $currentSort,
            'currentDir' => $currentDir,
        ]);
    }

    #[Route('/warehouse/create', name: 'admin_warehouse_create', methods: ['GET', 'POST'])]
    public function createWarehouse(Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleWarehouseForm($request, $entityManager, new Warehouse(), 'created');
    }

    #[Route('/warehouse/{id}/update', name: 'admin_warehouse_update', methods: ['GET', 'POST'])]
    public function updateWarehouse(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $warehouse = $entityManager->find(Warehouse::class, $id);
        if (!$warehouse instanceof Warehouse) {
            $this->addFlash('error', 'Warehouse could not be found.');

            return $this->redirectToRoute('admin_warehouse');
        }

        return $this->handleWarehouseForm($request, $entityManager, $warehouse, 'updated');
    }

    #[Route('/warehouse/{id}/delete', name: 'admin_warehouse_delete', methods: ['POST'])]
    public function deleteWarehouse(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $warehouse = $entityManager->find(Warehouse::class, $id);
        if (!$warehouse instanceof Warehouse) {
            return new JsonResponse(['ok' => false, 'message' => 'Warehouse could not be found.'], Response::HTTP_NOT_FOUND);
        }

        // Refused rather than cascaded: deleting the building a region is served from would leave
        // that region orderable with nowhere to draw stock from. Delete the region instead — that
        // path takes the warehouse with it, because the two are one thing.
        $region = $this->warehouses->regionForWarehouse($warehouse);
        if ($region instanceof FulfillmentRegion) {
            return new JsonResponse([
                'ok' => false,
                'message' => sprintf(
                    'This warehouse serves the "%s" fulfillment region and cannot be deleted on its own. Delete the region instead.',
                    $region->getName(),
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        $name = $warehouse->getName();

        foreach ($entityManager->getRepository(ProductInventory::class)->findBy(['warehouse' => $warehouse]) as $row) {
            if ($row instanceof ProductInventory) {
                $entityManager->remove($row);
            }
        }

        $entityManager->remove($warehouse);
        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('Warehouse "%s" was deleted successfully.', $name)]);
    }

    /**
     * Create or update a warehouse, address included (queue item 32), and refuse either without a
     * province (queue item 61).
     *
     * ## What is required here and why it is only two fields
     *
     * The street, the second line, the city and the postal code stay optional: nothing computes
     * from them, and a warehouse whose street nobody has typed still prices tax correctly. The
     * PROVINCE is the field a purchase order and a vendor bill derive tax from since item 37, so a
     * warehouse saved without one is a document quoting $0.00 tax that nothing on the page can tell
     * apart from a genuine zero. The owner's ruling on item 61 is that this is not a state the
     * application may reach: a building is somewhere, and it says so at creation and at edit.
     *
     * **The COUNTRY is required with it**, which the ruling did not ask for and the evidence does.
     * A province is resolved country-agnostically everywhere in this application — `TaxContext` and
     * `AbstractSalesDocument::getProvince()` are handed a province and no country — and the two
     * countries share no province code, so that is safe. What is NOT safe is the country codes and
     * the province codes sharing a namespace: `'CA'` typed into a province box resolves to
     * CALIFORNIA, because Canada has no province 'CA' and the United States has a state 'CA'. That
     * is a resolvable province, so it passes every "the province is known" guard item 37 built, and
     * then no calculator claims a US state and the tax is $0.00 — this item's defect, reached by
     * typing the country into the wrong box. Requiring the country lets the province be validated
     * INSIDE it, so 'CA' + 'CA' is refused by name instead of becoming California.
     *
     * ## Existing rows with no province
     *
     * They load, they read, they render on every screen, and they are refused only here, at the
     * moment somebody saves. That is deliberate and it is the whole reason the columns are still
     * nullable: a business that has warehouses today did not agree to this validation, and a row it
     * cannot load is a row it cannot correct. Nothing backfills them — `docs/QUEUE.md` forbids
     * writing to existing data, and there is no honest place to read a province from anyway.
     */
    private function handleWarehouseForm(Request $request, EntityManagerInterface $entityManager, Warehouse $warehouse, string $action): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $status = (string) $request->request->get('status', 'Active');

            $address = [
                'addressLine1' => trim((string) $request->request->get('address_line1', '')),
                'addressLine2' => trim((string) $request->request->get('address_line2', '')),
                'city' => trim((string) $request->request->get('city', '')),
                'province' => trim((string) $request->request->get('province', '')),
                'postalCode' => trim((string) $request->request->get('postal_code', '')),
                'country' => trim((string) $request->request->get('country', '')),
            ];

            // Keyed by the field the person has to go and fix, so the message can be printed at
            // that box as well as at the top of the page. A refusal an admin has to map back onto a
            // field themselves is half a refusal.
            $fieldErrors = [];
            $resolvedCountry = $this->region->normalizeCountry($address['country']);
            if ($name === '') {
                $fieldErrors['name'] = 'Warehouse name is required.';
            }

            // Creating a warehouse creates the fulfillment region it serves, and the region's NAME
            // is what every document, company row and stock lookup matches on. A name that a region
            // already wears therefore has exactly two honest outcomes, and this decides which:
            //
            //  - nothing serves that region yet — the building being created is its answer, and
            //    createRegionForWarehouse() links the two rather than minting a second row. That is
            //    the state the first-login seeder leaves three regions in, and this screen is where
            //    the Fulfillment Regions screen sends an admin to resolve it.
            //  - another warehouse already serves it — a region draws stock from one building
            //    (uniq_wfr_region), so there is nothing to link and the only thing left would be a
            //    SECOND region of the same name. That splits the stock silently: half the
            //    application resolves the name to one row, half to the other. Refused, at the box
            //    the person has to change, before anything is written.
            //
            // Create only. An update creates no region, so renaming a warehouse cannot reach this.
            $existingRegionOfThatName = $warehouse->getId() === null ? $this->warehouses->regionNamed($name) : null;
            if ($existingRegionOfThatName instanceof FulfillmentRegion && !isset($fieldErrors['name'])) {
                $servedBy = $this->warehouses->warehouseForRegion($existingRegionOfThatName);
                if ($servedBy instanceof Warehouse) {
                    $fieldErrors['name'] = sprintf(
                        'The fulfillment region "%s" already exists and warehouse "%s" already serves it. A region '
                            . 'draws its stock from exactly one building, so this warehouse cannot serve it too. Give '
                            . 'this warehouse a different name.',
                        $existingRegionOfThatName->getName(),
                        $servedBy->getName(),
                    );
                }
            }

            $fieldErrors += $this->regionFieldErrors(
                $address['country'],
                $address['province'],
                'country',
                'province',
                'Country is required. Choose the country this building is in — it is what the province below is read against.',
                'Province is required. A warehouse is a building and a building is somewhere, '
                    . 'and this is the province every purchase order and vendor bill raised against it computes tax from. '
                    . 'Choose one from the list.',
            );

            if ($fieldErrors !== []) {
                $this->addFlash('error', implode(' ', $fieldErrors));

                return $this->render('admin/config/warehouse_form.html.twig', [
                    'mode' => $action === 'created' ? 'Create' : 'Update',
                    // What was typed, not what was stored: the person is being sent back to correct
                    // one field and must not find the other five silently emptied.
                    'warehouse' => array_merge($address, [
                        'id' => (string) ($warehouse->getId() ?? ''),
                        'name' => $name,
                        'status' => $status,
                        'fulfillmentRegion' => $this->warehouses->regionForWarehouse($warehouse)?->getName() ?? '',
                    ]),
                    'fieldErrors' => $fieldErrors,
                    'provinceMissingOnFile' => $warehouse->getId() !== null && $warehouse->getProvince() === null,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $isNew = $warehouse->getId() === null;
            // The CODES the check above resolved, not the raw post. `Warehouse::setProvince()`
            // normalises country-blind — it has to, because it is handed no country — so feeding it
            // the raw value would resolve it a second time against both countries and undo the
            // scoping that just refused 'CA' under Canada. Validated within the country, stored as
            // the code that validation produced.
            $warehouse
                ->setName($name)
                ->setStatus($status)
                ->setAddressLine1($address['addressLine1'])
                ->setAddressLine2($address['addressLine2'])
                ->setCity($address['city'])
                ->setProvince($this->region->normalizeProvince((string) $resolvedCountry, $address['province']) ?? $address['province'])
                ->setPostalCode($address['postalCode'])
                ->setCountry((string) $resolvedCountry);
            $entityManager->persist($warehouse);
            $entityManager->flush();

            if ($isNew) {
                // A warehouse nothing can order from holds unreachable stock, so the region it
                // serves is created with it — the mirror of what creating a region does (#546).
                // Or ADOPTED: a region of that name with no warehouse yet gets this one, rather
                // than a second row of the same name being minted beside it. The check above has
                // already refused the one case this cannot answer.
                $this->warehouses->createRegionForWarehouse($warehouse);
                $entityManager->flush();
            }

            // Said out loud when a region was adopted rather than created. An admin who typed a
            // name that already meant something is entitled to know that it did, and that the
            // region's own settings — status, guest visibility, price list, default-for-new-customer
            // — are the ones already on file and were not restated from this form.
            $this->addFlash('success', $existingRegionOfThatName instanceof FulfillmentRegion
                ? sprintf(
                    'Warehouse "%s" was %s successfully. It now serves the fulfillment region "%s", which already '
                        . 'existed and keeps its own settings — no second region of that name was created.',
                    $warehouse->getName(),
                    $action,
                    $existingRegionOfThatName->getName(),
                )
                : sprintf('Warehouse "%s" was %s successfully.', $warehouse->getName(), $action));

            return $this->redirectToRoute('admin_warehouse');
        }

        return $this->render('admin/config/warehouse_form.html.twig', [
            'mode' => $action === 'created' ? 'Create' : 'Update',
            'warehouse' => [
                'id' => (string) ($warehouse->getId() ?? ''),
                'name' => $warehouse->getName(),
                'status' => $warehouse->getStatus(),
                'fulfillmentRegion' => $this->warehouses->regionForWarehouse($warehouse)?->getName() ?? '',
                'addressLine1' => $warehouse->getAddressLine1() ?? '',
                'addressLine2' => $warehouse->getAddressLine2() ?? '',
                'city' => $warehouse->getCity() ?? '',
                'province' => $warehouse->getProvince() ?? '',
                'postalCode' => $warehouse->getPostalCode() ?? '',
                'country' => $warehouse->getCountry() ?? '',
            ],
            'fieldErrors' => [],
            // A row that predates queue item 61. It still loads — that is the point of leaving the
            // column nullable — but the form says on arrival why it cannot be saved as it stands,
            // rather than letting somebody press the button to find out.
            'provinceMissingOnFile' => $warehouse->getId() !== null && $warehouse->getProvince() === null,
        ]);
    }

    #[Route('/guest-fulfillment-regions', name: 'admin_guest_fulfillment_regions', methods: ['GET', 'POST'])]
    public function guestFulfillmentRegions(
        Request $request,
        EntityManagerInterface $entityManager,
        CompanyFulfillmentRegionService $companyFulfillmentRegionService
    ): Response {
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $submitted = $request->request->all('regions');
            if (!is_array($submitted)) {
                $submitted = [];
            }

            $errors = [];
            foreach ($regions as $region) {
                $data = $submitted[(string) $region->getId()] ?? [];
                $guestVisible = (($data['guest_visible'] ?? '0') === '1');
                $priceListId = trim((string) ($data['guest_price_list_id'] ?? ''));

                $errors = array_merge($errors, $companyFulfillmentRegionService->validateActivation($guestVisible, $priceListId !== '', $region->getName()));
            }

            if ($errors === []) {
                foreach ($regions as $region) {
                    $data = $submitted[(string) $region->getId()] ?? [];
                    $guestVisible = (($data['guest_visible'] ?? '0') === '1');
                    $priceListId = trim((string) ($data['guest_price_list_id'] ?? ''));
                    $priceList = $priceListId !== '' ? $entityManager->find(PriceList::class, (int) $priceListId) : null;

                    $region->setGuestVisible($guestVisible)->setGuestPriceList($priceList instanceof PriceList ? $priceList : null);
                }
                $entityManager->flush();

                $this->addFlash('success', 'Guest fulfillment regions were updated successfully.');

                return $this->redirectToRoute('admin_guest_fulfillment_regions');
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->render('admin/config/guest_fulfillment_regions.html.twig', [
                'rows' => $this->guestFulfillmentRegionRowsFromRequest($regions, $submitted),
                'priceLists' => $this->priceListRowsFromDatabase($entityManager),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/config/guest_fulfillment_regions.html.twig', [
            'rows' => $this->guestFulfillmentRegionRows($regions),
            'priceLists' => $this->priceListRowsFromDatabase($entityManager),
        ]);
    }

    /**
     * @param list<FulfillmentRegion> $regions
     * @return list<array{id: string, name: string, guestVisible: bool, guestPriceListId: string}>
     */
    private function guestFulfillmentRegionRows(array $regions): array
    {
        return array_map(fn (FulfillmentRegion $region): array => [
            'id' => (string) $region->getId(),
            'name' => $region->getName(),
            'guestVisible' => $region->isGuestVisible(),
            'guestPriceListId' => (string) ($region->getGuestPriceList()?->getId() ?? ''),
        ], $regions);
    }

    /**
     * @param list<FulfillmentRegion> $regions
     * @param array<string, array<string, mixed>> $submitted
     * @return list<array{id: string, name: string, guestVisible: bool, guestPriceListId: string}>
     */
    private function guestFulfillmentRegionRowsFromRequest(array $regions, array $submitted): array
    {
        return array_map(static function (FulfillmentRegion $region) use ($submitted): array {
            $data = $submitted[(string) $region->getId()] ?? [];

            return [
                'id' => (string) $region->getId(),
                'name' => $region->getName(),
                'guestVisible' => (($data['guest_visible'] ?? '0') === '1'),
                'guestPriceListId' => trim((string) ($data['guest_price_list_id'] ?? '')),
            ];
        }, $regions);
    }

    #[Route('/payment-terms', name: 'admin_payment_terms', methods: ['GET'])]
    public function paymentTerms(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = $this->configTablePage($entityManager, $request, 'payment_term', ['name', 'description', 'status', 'sort_order']);
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/config/_payment_term_rows.html.twig', ['rows' => $page['rows']]),
                'total' => $page['total'],
                'page' => $page['page'],
                'limit' => $page['limit'],
                'pages' => $page['pages'],
            ]);
        }

        return $this->render('admin/config/payment_terms.html.twig', [
            ...$page,
        ]);
    }

    #[Route('/payment-terms/create', name: 'admin_payment_term_create', methods: ['GET', 'POST'])]
    public function createPaymentTerm(Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'payment_term');
    }

    #[Route('/payment-terms/{id}/update', name: 'admin_payment_term_update', methods: ['GET', 'POST'])]
    public function updatePaymentTerm(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'payment_term', $id);
    }

    #[Route('/payment-terms/{id}/delete', name: 'admin_payment_term_delete', methods: ['POST'])]
    public function deletePaymentTerm(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->deleteSimpleConfigRow($request, $entityManager, 'payment_term', $id);
    }

    #[Route('/credit-memo-types', name: 'admin_credit_memo_types', methods: ['GET'])]
    public function creditMemoTypes(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = $this->configTablePage($entityManager, $request, 'credit_memo_type', ['name', 'status']);
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/config/_credit_memo_type_rows.html.twig', ['rows' => $page['rows']]),
                'total' => $page['total'],
                'page' => $page['page'],
                'limit' => $page['limit'],
                'pages' => $page['pages'],
            ]);
        }

        return $this->render('admin/config/credit_memo_types.html.twig', [
            ...$page,
        ]);
    }

    #[Route('/credit-memo-types/create', name: 'admin_credit_memo_type_create', methods: ['GET', 'POST'])]
    public function createCreditMemoType(Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'credit_memo_type');
    }

    #[Route('/credit-memo-types/{id}/update', name: 'admin_credit_memo_type_update', methods: ['GET', 'POST'])]
    public function updateCreditMemoType(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'credit_memo_type', $id);
    }

    #[Route('/credit-memo-types/{id}/delete', name: 'admin_credit_memo_type_delete', methods: ['POST'])]
    public function deleteCreditMemoType(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->deleteSimpleConfigRow($request, $entityManager, 'credit_memo_type', $id);
    }

    #[Route('/sales-tax', name: 'admin_sales_tax', methods: ['GET'])]
    public function salesTax(SalesTaxRepository $salesTaxRepository, Request $request): Response
    {
        $page = $this->salesTaxTablePage($salesTaxRepository, $request);
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/config/_sales_tax_rows.html.twig', ['rows' => $page['rows']]),
                'total' => $page['total'],
                'page' => $page['page'],
                'limit' => $page['limit'],
                'pages' => $page['pages'],
            ]);
        }

        return $this->render('admin/config/sales_tax.html.twig', [
            ...$page,
        ]);
    }

    #[Route('/sales-tax/create', name: 'admin_sales_tax_create', methods: ['GET', 'POST'])]
    public function createSalesTax(Request $request, SalesTaxRepository $salesTaxRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSalesTaxForm($request, $salesTaxRepository, $entityManager, null);
    }

    #[Route('/sales-tax/{id}/update', name: 'admin_sales_tax_update', methods: ['GET', 'POST'])]
    public function updateSalesTax(int $id, Request $request, SalesTaxRepository $salesTaxRepository, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSalesTaxForm($request, $salesTaxRepository, $entityManager, $id);
    }

    #[Route('/sales-tax/{id}/delete', name: 'admin_sales_tax_delete', methods: ['POST'])]
    public function deleteSalesTax(int $id, Request $request, SalesTaxRepository $salesTaxRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $salesTax = $salesTaxRepository->find($id);
        if ($salesTax === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Sales Tax could not be found.'], Response::HTTP_NOT_FOUND);
        }

        if ($salesTax->getSource() !== null) {
            return new JsonResponse([
                'ok' => false,
                'message' => sprintf('This rate is managed by the %s bundle and cannot be deleted here.', $salesTax->getSource()),
            ], Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($salesTax);
        $entityManager->flush();

        return new JsonResponse([
            'ok' => true,
            'message' => sprintf('Sales Tax "%s %s" was deleted successfully.', $salesTax->getProvinceName(), $salesTax->getTaxType()),
        ]);
    }

    #[Route('/shipping-zones', name: 'admin_shipping_zones', methods: ['GET'])]
    public function shippingZones(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = $this->configTablePage($entityManager, $request, 'shipping_zone', ['area_name', 'delivery_days', 'free_shipping_minimum', 'delivery_fee', 'status', 'sort_order']);
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/config/_shipping_zone_rows.html.twig', ['rows' => $page['rows']]),
                'total' => $page['total'],
                'page' => $page['page'],
                'limit' => $page['limit'],
                'pages' => $page['pages'],
            ]);
        }

        return $this->render('admin/config/shipping_zones.html.twig', [
            ...$page,
        ]);
    }

    #[Route('/shipping-zones/create', name: 'admin_shipping_zone_create', methods: ['GET', 'POST'])]
    public function createShippingZone(Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'shipping_zone');
    }

    #[Route('/shipping-zones/{id}/update', name: 'admin_shipping_zone_update', methods: ['GET', 'POST'])]
    public function updateShippingZone(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->handleSimpleConfigForm($request, $entityManager, 'shipping_zone', $id);
    }

    #[Route('/shipping-zones/{id}/delete', name: 'admin_shipping_zone_delete', methods: ['POST'])]
    public function deleteShippingZone(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->deleteSimpleConfigRow($request, $entityManager, 'shipping_zone', $id);
    }

    #[Route('/shipping-zones/reorder', name: 'admin_shipping_zone_reorder', methods: ['POST'])]
    public function reorderShippingZones(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->reorderSimpleConfigRows($request, $entityManager, 'shipping_zone');
    }

    #[Route('/shipping-zones/{id}/move/{direction}', name: 'admin_shipping_zone_move', methods: ['POST'], requirements: ['direction' => 'up|down'])]
    public function moveShippingZone(int $id, string $direction, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->moveSimpleConfigRow($request, $entityManager, 'shipping_zone', $id, $direction);
    }

    private function handleSettingForm(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings, AppSetting $setting, string $action): Response
    {
        $isProtectedExisting = $setting->getId() !== null && $this->isProtectedSettingKey($setting->getSettingKey());
        $categories = $this->distinctSettingCategories($entityManager);

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $key = $isProtectedExisting ? $setting->getSettingKey() : strtolower(trim((string) $request->request->get('setting_key', '')));
            $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';

            if ($key === '' && $name !== '') {
                $key = strtolower(trim($name));
                $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
                $key = trim($key, '_');
            }

            // The key decides who the row belongs to, so resolve that before anything is written.
            // updateSetting() has already refused an existing Tech Support row; this covers the two
            // ways to arrive at one through the create route instead — naming a Tech Support key
            // whose row is missing, and renaming an ordinary row onto it.
            $visibility = $this->visibilityForSettingKey($entityManager, $appSettings, $key);
            if ($visibility === AppSetting::VISIBILITY_TECH_SUPPORT) {
                $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');
            }

            $value = trim((string) $request->request->get('setting_value', ''));
            $category = trim((string) $request->request->get('category', ''));

            $violations = Validation::createValidator()->validate(
                new \ArrayObject(['name' => $name, 'key' => $key]),
                new ValidAppSettingRequest($entityManager, $setting->getId()),
            );
            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());
            } else {
                $setting
                    ->setName($name)
                    ->setSettingKey($key)
                    ->setSettingValue($value !== '' ? $value : null)
                    ->setDescription($this->nullableRequest($request, 'description'))
                    ->setCategory($category !== '' ? $category : null)
                    ->setVisibility($visibility)
                    ->touch();
                $entityManager->persist($setting);
                $entityManager->flush();
                $appSettings->clearCache();
                $this->addFlash('success', sprintf('Setting "%s" was %s successfully.', $setting->getName(), $action));

                return $this->redirectToRoute('admin_settings');
            }

            return $this->render('admin/config/setting_form.html.twig', [
                'mode' => $action === 'created' ? 'Create' : 'Update',
                'key_locked' => $isProtectedExisting,
                'env_vars' => $this->envVarNames(),
                'categories' => $categories,
                'setting' => [
                    'id' => (string) ($setting->getId() ?? ''),
                    'key' => $key,
                    'name' => $name,
                    'value' => $value,
                    'description' => (string) $request->request->get('description', ''),
                    'category' => $category,
                ],
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/config/setting_form.html.twig', [
            'mode' => $action === 'created' ? 'Create' : 'Update',
            'key_locked' => $isProtectedExisting,
            'env_vars' => $this->envVarNames(),
            'categories' => $categories,
            'setting' => $this->settingRow($setting),
        ]);
    }

    private function handleEmailTemplateForm(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings, EmailTemplate $template, string $action, ?EmailTemplateResolver $emailTemplates = null): Response
    {
        if ($request->isMethod('POST')) {
            $module = trim((string) $request->request->get('module', ''));
            $subject = trim((string) $request->request->get('subject', ''));
            $body = trim((string) $request->request->get('body', ''));

            $violations = Validation::createValidator()->validate(
                new \ArrayObject(['module' => $module, 'subject' => $subject, 'body' => $body]),
                new ValidEmailTemplateRequest(),
            );
            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());
            } else {
                // Only derive code on create. Recomputing it on update from an edited module
                // label silently breaks code-based lookups (e.g. sendInvoice()'s
                // 'invoice_customer'/'invoice_self', whose labels don't slugify back to their codes).
                if ($template->getCode() === '') {
                    $template->setCode($this->templateCode($module));
                }

                $submitted = [
                    'module' => $module,
                    'sentTo' => (string) $request->request->get('sent_to', 'Customer'),
                    'subject' => $subject,
                    'body' => $body,
                    'description' => $this->nullableRequest($request, 'description'),
                    'status' => (string) $request->request->get('status', 'Active'),
                ];

                // Only what actually DIFFERS from what ships is stored; anything matching goes back
                // to null, which means "inherit" (#507). So an admin who edits the body and leaves
                // the subject alone keeps receiving shipped corrections to that subject, and a
                // template edited back to its original stops being an override at all rather than
                // becoming a frozen copy that silently stops tracking.
                $shipped = $this->emailCatalogue->get($template->getCode());
                foreach ($submitted as $field => $value) {
                    $submitted[$field] = $shipped !== null && $value === $shipped[$field] ? null : $value;
                }

                $template
                    ->setModule($submitted['module'])
                    ->setSentTo($submitted['sentTo'])
                    ->setSubject($submitted['subject'])
                    ->setBody($submitted['body'])
                    ->setDescription($submitted['description'])
                    ->setStatus($submitted['status'])
                    ->touch();

                // Nothing differs from what ships, so there is nothing to store. Removing the row
                // (or never creating it) is the same outcome the Revert button produces.
                if ($shipped !== null && array_filter($submitted, static fn ($v): bool => $v !== null) === []) {
                    if ($template->getId() !== null) {
                        $entityManager->remove($template);
                    }
                } else {
                    $entityManager->persist($template);
                }

                $entityManager->flush();
                $appSettings->clearCache();
                $this->addFlash('success', sprintf('Email template "%s" was %s successfully.', $template->getModule(), $action));

                return $this->redirectToRoute('admin_email_template');
            }

            return $this->render('admin/config/email_template_form.html.twig', [
                'mode' => $action === 'created' ? 'Create' : 'Update',
                'template' => [
                    'id' => (string) ($template->getId() ?? ''),
                    'module' => $module,
                    'sentTo' => (string) $request->request->get('sent_to', 'Customer'),
                    'subject' => $subject,
                    'body' => $body,
                    'description' => (string) $request->request->get('description', ''),
                    'status' => (string) $request->request->get('status', 'Active'),
                ],
                'previewUrl' => $template->getCode() !== ''
                    ? $this->generateUrl('admin_email_template_preview', ['code' => $template->getCode()])
                    : $this->generateUrl('admin_email_template_preview'),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/config/email_template_form.html.twig', [
            'mode' => $action === 'created' ? 'Create' : 'Update',
            'template' => $this->emailTemplateRow($template),
            'previewUrl' => $template->getCode() !== ''
                ? $this->generateUrl('admin_email_template_preview', ['code' => $template->getCode()])
                : $this->generateUrl('admin_email_template_preview'),
        ]);
    }

    private function upsertAppSetting(EntityManagerInterface $entityManager, string $key, string $name, string $value, ?string $description = null): void
    {
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())
                ->setName($name)
                ->setSettingKey($key);
        }

        $setting
            ->setName($name)
            ->setSettingValue($value !== '' ? $value : null)
            ->setDescription($description)
            ->touch();

        $entityManager->persist($setting);
    }

    /**
     * Create or update a fulfillment region — and, on create, the warehouse it draws stock from.
     *
     * ## Why this screen asks where a building is (queue item 61)
     *
     * Creating a region creates the warehouse serving it (#546), and since item 61 a warehouse may
     * not exist without a province. A `FulfillmentRegion` has none to lend — the owner has ruled it
     * never will, because a region is a delivery area and may legitimately span several — so the
     * only place the fact can come from is the person filling this form in. The two boxes belong to
     * the WAREHOUSE and say so; nothing about the region is stored from them.
     *
     * They appear on create only. Updating a region creates nothing, so demanding a province to
     * rename one would refuse a save that was never going to write a warehouse row.
     */
    private function handleFulfillmentRegionForm(Request $request, EntityManagerInterface $entityManager, FulfillmentRegion $region, string $action, CompanyFulfillmentRegionService $companyFulfillmentRegionService): Response
    {
        $isNewRegion = $region->getId() === null;

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $defaultForNewCompany = ((string) $request->request->get('default_for_new_company', '0')) === '1';
            $province = trim((string) $request->request->get('warehouse_province', ''));
            $country = trim((string) $request->request->get('warehouse_country', ''));

            $fieldErrors = [];
            $violations = Validation::createValidator()->validate($name, new ValidFulfillmentRegionRequest($entityManager, $region->getId()));
            if (count($violations) > 0) {
                $fieldErrors['name'] = (string) $violations[0]->getMessage();
            }

            if ($isNewRegion) {
                $fieldErrors += $this->regionFieldErrors(
                    $country,
                    $province,
                    'warehouse_country',
                    'warehouse_province',
                    'The warehouse\'s country is required. Choose the country the building is in.',
                    'The warehouse\'s province is required. Creating this region creates the '
                        . 'building its stock is counted in, and that building\'s province is what every purchase order and '
                        . 'vendor bill raised against it computes tax from. Choose one from the list.',
                );
            }

            if ($fieldErrors === []) {
                $region
                    ->setName($name)
                    ->setStatus((string) $request->request->get('status', 'Active'))
                    ->setDefaultForNewCompany($defaultForNewCompany);
                $entityManager->persist($region);
                $entityManager->flush();

                if ($isNewRegion) {
                    $companyFulfillmentRegionService->backfillForNewRegion($region);
                    // Same reason as before item 61: a brand-new region has to have somewhere to
                    // draw stock from, so the warehouse serving it is created alongside it (#546).
                    // What is new is that the address came from the form above rather than from
                    // nowhere. The CODES the check produced are handed on, for the reason
                    // handleWarehouseForm() states: re-resolving the raw value country-blind would
                    // undo the scoping that just refused 'CA' under Canada.
                    $resolvedCountry = (string) $this->region->normalizeCountry($country);
                    $this->warehouses->createWarehouseForRegion(
                        $region,
                        $this->region->normalizeProvince($resolvedCountry, $province) ?? $province,
                        $resolvedCountry,
                    );
                    $entityManager->flush();
                }

                $this->addFlash('success', sprintf('Fulfillment region "%s" was %s successfully.', $region->getName(), $action));

                return $this->redirectToRoute('admin_fulfillment_region');
            }

            $this->addFlash('error', implode(' ', $fieldErrors));

            return $this->render('admin/config/fulfillment_region_form.html.twig', [
                'mode' => $action === 'created' ? 'Create' : 'Update',
                'region' => [
                    'id' => (string) ($region->getId() ?? ''),
                    'name' => $name,
                    'status' => (string) $request->request->get('status', 'Active'),
                    'defaultForNewCompany' => $defaultForNewCompany ? 'Yes' : 'No',
                    'warehouseProvince' => $province,
                    'warehouseCountry' => $country,
                ],
                'asksForWarehouseAddress' => $isNewRegion,
                'fieldErrors' => $fieldErrors,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/config/fulfillment_region_form.html.twig', [
            'mode' => $action === 'created' ? 'Create' : 'Update',
            'region' => array_merge($this->fulfillmentRegionRow($region), [
                'warehouseProvince' => '',
                'warehouseCountry' => '',
            ]),
            'asksForWarehouseAddress' => $isNewRegion,
            'fieldErrors' => [],
        ]);
    }

    /** @return array<string, string> */
    private function settingRow(AppSetting $setting): array
    {
        return [
            'id' => (string) ($setting->getId() ?? ''),
            'key' => $setting->getSettingKey(),
            'name' => $setting->getName(),
            'value' => $setting->getSettingValue() ?? '',
            'description' => $setting->getDescription() ?? '',
            'category' => $setting->getCategory() ?: 'General',
        ];
    }

    /** @return array<string, string> */
    private function emailTemplateRow(EmailTemplate $template): array
    {
        // Falls back to what ships, so the edit form opens pre-filled with the text the admin
        // actually receives rather than with blanks for every field they have not overridden.
        $shipped = $this->emailCatalogue->get($template->getCode());

        return [
            'id' => (string) ($template->getId() ?? ''),
            'code' => $template->getCode(),
            'module' => $template->getModule() ?? $shipped['module'] ?? '',
            'sentTo' => $template->getSentTo() ?? $shipped['sentTo'] ?? 'Customer',
            'subject' => $template->getSubject() ?? $shipped['subject'] ?? '',
            'body' => $template->getBody() ?? $shipped['body'] ?? '',
            'description' => $template->getDescription() ?? $shipped['description'] ?? '',
            'status' => $template->getStatus() ?? $shipped['status'] ?? 'Active',
        ];
    }

    /** @return list<array<string, string>> */
    private function fulfillmentRegionRows(EntityManagerInterface $entityManager): array
    {
        return array_map(
            fn (FulfillmentRegion $region): array => $this->fulfillmentRegionRow($region),
            $entityManager->getRepository(FulfillmentRegion::class)->findBy([], ['id' => 'ASC'])
        );
    }

    /** @return array<string, string> */
    private function fulfillmentRegionRow(FulfillmentRegion $region, ?int $lockedId = null): array
    {
        $isLocked = $lockedId !== null && $region->getId() === $lockedId;

        return [
            'id' => (string) ($region->getId() ?? ''),
            'name' => $region->getName(),
            'status' => $region->getStatus(),
            'defaultForNewCompany' => $region->isDefaultForNewCompany() ? 'Yes' : 'No',
            'locked' => $isLocked ? 'Yes' : 'No',
        ];
    }

    private function lockedFulfillmentRegionId(EntityManagerInterface $entityManager): ?int
    {
        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy([], ['id' => 'ASC']);
        return $region instanceof FulfillmentRegion ? $region->getId() : null;
    }

    /*
     * ensureFulfillmentRegionsSettingExists() and syncFulfillmentRegionsFromSettings() are gone.
     *
     * Between them they created the `fulfillment_regions` app setting AND every fulfillment_region
     * row, and their only callers were two GET actions — fulfillmentRegions() and warehouses().
     * App\Service\ReferenceData\Seeders\FulfillmentRegionSeeder owns the rows now and runs once,
     * on the first admin login; the setting itself is still self-healed by ensureCoreSettingsExist(),
     * which has listed `fulfillment_regions` among its defaults all along.
     *
     * Deleted rather than left unused: a "sync from settings" helper that writes is exactly what the
     * next read path reaches for, and the description on that setting already says it is a first-run
     * seed that is ignored once any region exists.
     */

    private function fulfillmentRegionSortField(Request $request): string
    {
        $sort = trim((string) $request->query->get('sort', 'id'));
        $allowed = ['id', 'name', 'status'];
        if (!in_array($sort, $allowed, true)) {
            return 'l.id';
        }

        return 'l.' . $sort;
    }

    private function fulfillmentRegionSortDir(Request $request): string
    {
        return strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, string>>
     */
    private function configTableRows(EntityManagerInterface $entityManager, string $tableName, array $columns): array
    {
        $connection = $entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist([$tableName])) {
            return [];
        }

        $table = $connection->createSchemaManager()->introspectTable($tableName);
        $availableColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $table->hasColumn($column)
        ));
        $select = array_merge(['id'], $availableColumns);
        $orderBy = in_array('sort_order', $availableColumns, true) ? 'sort_order ASC, id ASC' : 'id ASC';
        $records = $connection->fetchAllAssociative(sprintf(
            'SELECT %s FROM %s ORDER BY %s',
            implode(', ', $select),
            $tableName,
            $orderBy
        ));

        return array_map(static function (array $record): array {
            return array_map(static fn (mixed $value): string => trim((string) $value), $record);
        }, $records);
    }

    /**
     * @param list<string> $columns
     * @return array{rows: list<array<string, string>>, total: int, page: int, limit: int, pages: int}
     */
    private function configTablePage(EntityManagerInterface $entityManager, Request $request, string $tableName, array $columns): array
    {
        $connection = $entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$tableName])) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 10, 'pages' => 1];
        }

        $table = $schemaManager->introspectTable($tableName);
        $availableColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $table->hasColumn($column)
        ));
        $select = array_merge(['id'], $availableColumns);
        $searchableColumns = array_values(array_filter(
            $availableColumns,
            static fn (string $column): bool => $column !== 'sort_order'
        ));

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(0, $request->query->getInt('limit', 100));
        $search = trim((string) $request->query->get('q', ''));
        $filters = $this->configFiltersFromRequest($request, $availableColumns);
        $conditions = [];
        $params = [];
        $types = [];

        if ($search !== '' && $searchableColumns !== []) {
            $parts = [];
            foreach ($searchableColumns as $index => $column) {
                $param = 'q'.$index;
                $parts[] = sprintf('CAST(%s AS TEXT) LIKE :%s', $column, $param);
                $params[$param] = '%'.$search.'%';
            }
            $conditions[] = '('.implode(' OR ', $parts).')';
        }

        foreach ($filters as $column => $value) {
            $param = 'filter_'.$column;
            if ($this->isNumericConfigFilterColumn($column)) {
                $normalizedValue = $this->normalizeNumericConfigFilterString($value);
                $normalized = $this->normalizeNumericConfigFilterValue($value);
                if ($normalized !== null && $normalizedValue !== null) {
                    $columnExpr = sprintf(
                        "REPLACE(REPLACE(REPLACE(CAST(%s AS TEXT), '$', ''), '%%', ''), ',', '')",
                        $column
                    );
                    $conditions[] = sprintf(
                        '(ABS(CAST(%1$s AS REAL) - :%2$s_num) < 0.0005 OR %3$s)',
                        $columnExpr,
                        $param,
                        $this->numericPrefixFilterSql($columnExpr, $param, $normalizedValue)
                    );
                    $params[$param.'_num'] = $normalized;
                    $params[$param.'_text'] = $normalizedValue;
                    if (str_contains($normalizedValue, '.')) {
                        $params[$param.'_prefix'] = $normalizedValue.'%';
                    } else {
                        $params[$param.'_prefix'] = $normalizedValue.'.%';
                    }
                    continue;
                }
            }

            $conditions[] = sprintf('CAST(%s AS TEXT) LIKE :%s', $column, $param);
            $params[$param] = '%'.$value.'%';
        }

        $whereSql = $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions);

        $sort = trim((string) $request->query->get('sort', ''));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';

        if ($sort !== '' && in_array($sort, $availableColumns, true)) {
            $orderBy = sprintf('%s %s, id ASC', $sort, $dir);
        } else {
            $orderBy = in_array('sort_order', $availableColumns, true) ? 'sort_order ASC, id ASC' : 'id ASC';
        }
        $total = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s%s', $tableName, $whereSql), $params);
        $pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
        $page = min($page, $pages);
        $sql = sprintf('SELECT %s FROM %s%s ORDER BY %s', implode(', ', $select), $tableName, $whereSql, $orderBy);

        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params['limit'] = $limit;
            $params['offset'] = ($page - 1) * $limit;
            $types['limit'] = ParameterType::INTEGER;
            $types['offset'] = ParameterType::INTEGER;
        }

        $records = $connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $rows = array_map(static function (array $record): array {
            return array_map(static fn (mixed $value): string => trim((string) $value), $record);
        }, $records);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'search' => $search,
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
        ];
    }

    /**
     * @param list<string> $availableColumns
     * @return array<string, string>
     */
    private function configFiltersFromRequest(Request $request, array $availableColumns): array
    {
        $filters = $request->query->all('filters');
        if ($filters === []) {
            $rawFilters = (string) $request->query->get('filters', '');
            $decoded = json_decode($rawFilters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        $clean = [];
        foreach ($filters as $column => $value) {
            if (!is_string($column) || !in_array($column, $availableColumns, true)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $clean[$column] = $value;
            }
        }

        return $clean;
    }

    private function isNumericConfigFilterColumn(string $column): bool
    {
        return in_array($column, ['rate', 'free_shipping_minimum', 'delivery_fee', 'sort_order'], true);
    }

    private function normalizeNumericConfigFilterValue(string $value): ?float
    {
        $normalized = $this->normalizeNumericConfigFilterString($value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function normalizeNumericConfigFilterString(string $value): ?string
    {
        $normalized = str_replace([',', '$', '%', ' '], '', trim($value));

        return $normalized === '' ? null : $normalized;
    }

    private function numericPrefixFilterSql(string $columnExpr, string $param, string $normalizedValue): string
    {
        if (str_contains($normalizedValue, '.')) {
            return sprintf('%s LIKE :%s_prefix', $columnExpr, $param);
        }

        return sprintf('(%1$s = :%2$s_text OR %1$s LIKE :%2$s_prefix)', $columnExpr, $param);
    }

    /** @return array<string, mixed> */
    private function salesTaxMeta(): array
    {
        return [
            'title' => 'Sales Tax',
            'indexRoute' => 'admin_sales_tax',
            'createRoute' => 'admin_sales_tax_create',
            'updateRoute' => 'admin_sales_tax_update',
            'deleteRoute' => 'admin_sales_tax_delete',
            'fields' => ['province_name', 'abbreviation', 'tax_type', 'rate', 'status'],
            'required' => ['province_name', 'abbreviation', 'tax_type'],
            'numeric' => ['rate'],
            'statuses' => ['Active', 'Inactive'],
        ];
    }

    /** @return array<string, string> */
    private function salesTaxToRow(SalesTax $salesTax): array
    {
        return [
            'id' => (string) $salesTax->getId(),
            'province_name' => $salesTax->getProvinceName(),
            'abbreviation' => $salesTax->getAbbreviation(),
            'tax_type' => $salesTax->getTaxType(),
            // 5 decimals: bundle-owned rows store rate as a fraction (e.g. 0.09975 for
            // 9.975% QST) and the admin list multiplies by 100 for display — 3 decimals
            // here would round that away before the multiplication ever happens.
            'rate' => number_format($salesTax->getRate(), 5, '.', ''),
            'status' => $salesTax->getStatus(),
            'slug' => $salesTax->getSlug() ?? '',
            'source' => $salesTax->getSource() ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function salesTaxTablePage(SalesTaxRepository $salesTaxRepository, Request $request): array
    {
        $columns = ['province_name', 'abbreviation', 'tax_type', 'rate', 'status'];
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(0, $request->query->getInt('limit', 100));
        $search = trim((string) $request->query->get('q', ''));
        $filters = $this->configFiltersFromRequest($request, $columns);
        $sort = trim((string) $request->query->get('sort', ''));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $result = $salesTaxRepository->search($search, $filters, $sort !== '' ? $sort : null, $dir, $page, $limit);
        $total = $result['total'];
        $pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
        $page = min($page, $pages);

        $rows = array_map(fn (SalesTax $salesTax): array => $this->salesTaxToRow($salesTax), $result['rows']);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'search' => $search,
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => $dir,
        ];
    }

    private function handleSalesTaxForm(Request $request, SalesTaxRepository $salesTaxRepository, EntityManagerInterface $entityManager, ?int $id): Response
    {
        $meta = $this->salesTaxMeta();
        $salesTax = $id !== null ? $salesTaxRepository->find($id) : new SalesTax();

        if ($id !== null && $salesTax === null) {
            $this->addFlash('error', sprintf('%s could not be found.', $meta['title']));

            return $this->redirectToRoute($meta['indexRoute']);
        }

        // Bundle-owned rows (source !== null) may only have their status toggled here;
        // rate/province/abbreviation/tax_type are managed on the owning bundle's config page.
        $isBundleOwned = $salesTax->getSource() !== null;

        if ($request->isMethod('POST')) {
            $row = $this->salesTaxToRow($salesTax);
            foreach ($meta['fields'] as $field) {
                if ($isBundleOwned && $field !== 'status') {
                    continue;
                }

                $value = trim((string) $request->request->get($field, ''));
                $row[$field] = match ($field) {
                    'rate' => number_format((float) str_replace(',', '', $value), 3, '.', ''),
                    default => $value,
                };
            }

            if (!in_array($row['status'], $meta['statuses'], true)) {
                $row['status'] = $meta['statuses'][0];
            }

            $errors = $this->validateSimpleConfigRow($row, $meta);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/config/simple_config_form.html.twig', [
                    'mode' => $id === null ? 'Create' : 'Update',
                    'meta' => $meta,
                    'row' => $row,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $salesTax->setProvinceName($row['province_name'])
                ->setAbbreviation($row['abbreviation'])
                ->setTaxType($row['tax_type'])
                ->setRate((float) $row['rate'])
                ->setStatus($row['status']);

            if ($id === null) {
                $entityManager->persist($salesTax);
            }
            $entityManager->flush();

            $this->addFlash('success', sprintf(
                '%s "%s %s" was %s successfully.',
                $meta['title'],
                $row['province_name'],
                $row['tax_type'],
                $id === null ? 'created' : 'updated'
            ));

            return $this->redirectToRoute($meta['indexRoute']);
        }

        return $this->render('admin/config/simple_config_form.html.twig', [
            'mode' => $id === null ? 'Create' : 'Update',
            'meta' => $meta,
            'row' => $this->salesTaxToRow($salesTax),
        ]);
    }

    private function handleSimpleConfigForm(Request $request, EntityManagerInterface $entityManager, string $configKey, ?int $id = null): Response
    {
        $meta = self::SIMPLE_CONFIG[$configKey];
        $row = $id !== null
            ? $this->findSimpleConfigRow($entityManager, $configKey, $id)
            : $this->emptySimpleConfigRow($configKey);

        if ($id !== null && $row === null) {
            $this->addFlash('error', sprintf('%s could not be found.', $meta['title']));

            return $this->redirectToRoute($meta['indexRoute']);
        }

        if ($request->isMethod('POST')) {
            $row = $this->simpleConfigRowFromRequest($request, $configKey, $row ?? []);
            $errors = $this->validateSimpleConfigRow($row, $meta);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/config/simple_config_form.html.twig', [
                    'mode' => $id === null ? 'Create' : 'Update',
                    'meta' => $meta,
                    'row' => $row,
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $connection = $entityManager->getConnection();
            $data = [];
            foreach ($meta['fields'] as $field) {
                $data[$field] = $row[$field] ?? '';
            }

            if ($id === null) {
                $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                if ($this->isOrderableConfig($configKey) && $this->tableHasColumn($entityManager, $meta['table'], 'sort_order')) {
                    $maxOrder = (int) $connection->fetchOne(sprintf('SELECT COALESCE(MAX(sort_order), 0) FROM %s', $meta['table']));
                    $data['sort_order'] = $maxOrder + 10;
                }
                $connection->insert($meta['table'], $data);
                $this->addFlash('success', sprintf('%s "%s" was created successfully.', $meta['title'], $this->simpleConfigDisplayName($row, $configKey)));
            } else {
                $connection->update($meta['table'], $data, ['id' => $id]);
                $this->addFlash('success', sprintf('%s "%s" was updated successfully.', $meta['title'], $this->simpleConfigDisplayName($row, $configKey)));
            }

            return $this->redirectToRoute($meta['indexRoute']);
        }

        return $this->render('admin/config/simple_config_form.html.twig', [
            'mode' => $id === null ? 'Create' : 'Update',
            'meta' => $meta,
            'row' => $row ?? $this->emptySimpleConfigRow($configKey),
        ]);
    }

    private function moveSimpleConfigRow(Request $request, EntityManagerInterface $entityManager, string $configKey, int $id, string $direction): JsonResponse
    {
        if (!$this->isOrderableConfig($configKey)) {
            return new JsonResponse(['ok' => false, 'message' => 'This list cannot be reordered.'], Response::HTTP_BAD_REQUEST);
        }

        $meta = self::SIMPLE_CONFIG[$configKey];
        if (!$this->tableHasColumn($entityManager, $meta['table'], 'sort_order')) {
            return new JsonResponse(['ok' => false, 'message' => sprintf('%s ordering is not ready. Please run migrations.', $meta['title'])], Response::HTTP_BAD_REQUEST);
        }

        $connection = $entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(sprintf('SELECT id, sort_order FROM %s ORDER BY sort_order ASC, id ASC', $meta['table']));
        if ($rows === []) {
            return new JsonResponse(['ok' => false, 'message' => sprintf('%s could not be found.', $meta['title'])], Response::HTTP_NOT_FOUND);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $currentIndex = array_search($id, $ids, true);
        if ($currentIndex === false) {
            return new JsonResponse(['ok' => false, 'message' => sprintf('%s could not be found.', $meta['title'])], Response::HTTP_NOT_FOUND);
        }

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if (!isset($ids[$targetIndex])) {
            return new JsonResponse(['ok' => true, 'message' => sprintf('%s is already at the %s.', $meta['title'], $direction === 'up' ? 'top' : 'bottom')]);
        }

        [$ids[$currentIndex], $ids[$targetIndex]] = [$ids[$targetIndex], $ids[$currentIndex]];
        foreach ($ids as $index => $rowId) {
            $connection->update($meta['table'], ['sort_order' => ($index + 1) * 10], ['id' => $rowId]);
        }

        return new JsonResponse(['ok' => true, 'message' => sprintf('%s order was updated.', $meta['title'])]);
    }

    private function reorderSimpleConfigRows(Request $request, EntityManagerInterface $entityManager, string $configKey): JsonResponse
    {
        if (!$this->isOrderableConfig($configKey)) {
            return new JsonResponse(['ok' => false, 'message' => 'This list cannot be reordered.'], Response::HTTP_BAD_REQUEST);
        }

        $meta = self::SIMPLE_CONFIG[$configKey];
        if (!$this->tableHasColumn($entityManager, $meta['table'], 'sort_order')) {
            return new JsonResponse(['ok' => false, 'message' => sprintf('%s ordering is not ready. Please run migrations.', $meta['title'])], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : []),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return new JsonResponse(['ok' => false, 'message' => 'No row order was provided.'], Response::HTTP_BAD_REQUEST);
        }

        $connection = $entityManager->getConnection();
        $existingIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $connection->fetchFirstColumn(sprintf('SELECT id FROM %s ORDER BY sort_order ASC, id ASC', $meta['table']))
        );

        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $ids, true)) {
                $ids[] = $existingId;
            }
        }

        foreach ($ids as $index => $rowId) {
            if (in_array($rowId, $existingIds, true)) {
                $connection->update($meta['table'], ['sort_order' => ($index + 1) * 10], ['id' => $rowId]);
            }
        }

        return new JsonResponse(['ok' => true, 'message' => sprintf('%s order was updated.', $meta['title'])]);
    }

    private function deleteSimpleConfigRow(Request $request, EntityManagerInterface $entityManager, string $configKey, int $id): JsonResponse
    {
        $meta = self::SIMPLE_CONFIG[$configKey];
        $row = $this->findSimpleConfigRow($entityManager, $configKey, $id);
        if ($row === null) {
            return new JsonResponse(['ok' => false, 'message' => sprintf('%s could not be found.', $meta['title'])], Response::HTTP_NOT_FOUND);
        }

        $entityManager->getConnection()->delete($meta['table'], ['id' => $id]);

        return new JsonResponse([
            'ok' => true,
            'message' => sprintf('%s "%s" was deleted successfully.', $meta['title'], $this->simpleConfigDisplayName($row, $configKey)),
        ]);
    }

    /** @return array<string, string>|null */
    private function findSimpleConfigRow(EntityManagerInterface $entityManager, string $configKey, int $id): ?array
    {
        $meta = self::SIMPLE_CONFIG[$configKey];
        $connection = $entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist([$meta['table']])) {
            return null;
        }

        $row = $connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = :id', $meta['table']), ['id' => $id]);
        if ($row === false) {
            return null;
        }

        return array_map(static fn (mixed $value): string => trim((string) $value), $row);
    }

    private function isOrderableConfig(string $configKey): bool
    {
        return in_array($configKey, self::ORDERABLE_CONFIGS, true);
    }

    private function tableHasColumn(EntityManagerInterface $entityManager, string $tableName, string $columnName): bool
    {
        $schemaManager = $entityManager->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist([$tableName])) {
            return false;
        }

        return $schemaManager->introspectTable($tableName)->hasColumn($columnName);
    }

    /** @return array<string, string> */
    private function emptySimpleConfigRow(string $configKey): array
    {
        $meta = self::SIMPLE_CONFIG[$configKey];
        $row = ['id' => ''];
        foreach ($meta['fields'] as $field) {
            $row[$field] = match ($field) {
                'status' => $meta['statuses'][0],
                'rate', 'free_shipping_minimum', 'delivery_fee' => '0.00',
                'sort_order' => '0',
                default => '',
            };
        }

        return $row;
    }

    /** @return array<string, string> */
    private function simpleConfigRowFromRequest(Request $request, string $configKey, array $existing): array
    {
        $meta = self::SIMPLE_CONFIG[$configKey];
        $row = $existing;
        foreach ($meta['fields'] as $field) {
            $value = trim((string) $request->request->get($field, ''));
            $row[$field] = match ($field) {
                'rate' => number_format((float) str_replace(',', '', $value), 3, '.', ''),
                'free_shipping_minimum', 'delivery_fee' => number_format((float) str_replace(',', '', $value), 2, '.', ''),
                'sort_order' => (string) max(0, (int) $value),
                default => $value,
            };
        }

        if (isset($meta['statuses']) && in_array('status', $meta['fields'], true) && !in_array($row['status'] ?? '', $meta['statuses'], true)) {
            $row['status'] = $meta['statuses'][0];
        }

        return $row;
    }

    /** @param array<string, mixed> $meta */
    private function validateSimpleConfigRow(array $row, array $meta): array
    {
        $violations = Validation::createValidator()->validate(
            new \ArrayObject($row),
            new ValidSimpleConfigRow($meta['required'], $meta['numeric'] ?? []),
        );

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }

    private function simpleConfigDisplayName(array $row, string $configKey): string
    {
        return $row['name'] ?? $row['province_name'] ?? $row['area_name'] ?? self::SIMPLE_CONFIG[$configKey]['title'];
    }

    private function nullableRequest(Request $request, string $key): ?string
    {
        $value = trim((string) $request->request->get($key, ''));

        return $value === '' ? null : $value;
    }

    private function templateCode(string $module): string
    {
        $code = strtolower(trim($module));
        $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return substr($code !== '' ? $code : 'email_template', 0, 80);
    }

    private function isProtectedSettingKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::PROTECTED_SETTING_KEYS, true);
    }

    /**
     * Leaves Tech Support rows out of a settings query unless the viewer is Tech Support.
     *
     * ROLE_TECH_SUPPORT is the team that runs the software; ROLE_SUPER_ADMIN is the store owner, and
     * inherits everything else Tech Support can do (config/packages/security.yaml role_hierarchy) —
     * which is why the gate names the operator role and not one the store owner also holds.
     *
     * Every query that lists app_setting rows generically goes through here, so there is one place
     * to change and a screen that forgets it shows nothing rather than everything.
     */
    private function hideTechSupportSettings(QueryBuilder $qb): void
    {
        if ($this->isGranted('ROLE_TECH_SUPPORT')) {
            return;
        }

        $qb->andWhere('s.visibility <> :visibilityTechSupport')
            ->setParameter('visibilityTechSupport', AppSetting::VISIBILITY_TECH_SUPPORT);
    }

    /**
     * Hiding a row from the list is cosmetic on its own: /admin/settings/{id}/update is a plain POST
     * and /admin/settings/{id}/delete is reachable with nothing but the id. This is the boundary the
     * list filter is a convenience for. Denial is the same 403 every other role-gated admin route
     * raises (see DatabaseConsoleController), not a special response shape.
     */
    private function denyUnlessSettingIsVisible(AppSetting $setting): void
    {
        if ($setting->isTechSupportOnly()) {
            $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');
        }
    }

    /**
     * The visibility a row with this key must have: its own if the row exists, otherwise the one
     * ensureCoreSettingsExist() would self-heal it with. The second half matters — a Tech Support key
     * whose row is missing (deleted, or a database that predates the seed) must not be creatable as
     * an ordinary store row by whoever reaches the create form first.
     */
    private function visibilityForSettingKey(EntityManagerInterface $entityManager, AppSettings $appSettings, string $key): string
    {
        $existing = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if ($existing instanceof AppSetting) {
            return $existing->getVisibility();
        }

        return (string) ($this->coreSettingDefaults($appSettings)[$key]['visibility'] ?? AppSetting::VISIBILITY_STORE);
    }

    /** @return list<string> */
    private function distinctSettingCategories(EntityManagerInterface $entityManager): array
    {
        $qb = $entityManager->getRepository(AppSetting::class)->createQueryBuilder('s')
            ->select('DISTINCT s.category');
        // A Tech Support row's category would otherwise name it on the store owner's tab strip and
        // in the setting form's category datalist.
        $this->hideTechSupportSettings($qb);

        $rows = $qb->getQuery()->getScalarResult();

        $categories = ['General'];
        foreach ($rows as $row) {
            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '' && !in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        sort($categories);

        return $categories;
    }

    /** @return list<string> */
    private function envVarNames(): array
    {
        $paths = [
            $this->getParameter('kernel.project_dir') . '/.env',
            $this->getParameter('kernel.project_dir') . '/.env.local',
            $this->getParameter('kernel.project_dir') . '/.env.dev',
            $this->getParameter('kernel.project_dir') . '/.env.prod',
        ];

        $names = [];
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                if (!str_contains($line, '=')) {
                    continue;
                }

                $key = trim((string) strtok($line, '='));
                if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || AppSettings::isSensitiveEnvVarName($key)) {
                    continue;
                }

                $names[] = $key;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
