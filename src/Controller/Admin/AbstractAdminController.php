<?php

namespace App\Controller\Admin;

use App\Contract\Payment\PaymentMethodInterface;
use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\DenominatedLine;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductAvailableUnit;
use App\Entity\UnitOfMeasure;
use App\Http\RequestedParent;
use App\Service\CompanyListScope;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\TextInput;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use PaymentBundle\Payment\PaymentMethodResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractAdminController extends AbstractController
{
    /**
     * The store-wide quantity scale — see {@see applyLineQuantity()}, the one thing on this class
     * that reads it.
     *
     * Reached through `AbstractController`'s own service locator rather than a constructor, because
     * this class has none and every one of its ~forty subclasses declares its own: adding a
     * parameter here would mean editing all of them to forward it, for one method. This is the
     * mechanism `AbstractController` already uses for `doctrine`, `twig` and the rest, and the
     * subscription below is what puts the service in the locator.
     *
     * @return array<string, string>
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            QuantityScale::class => QuantityScale::class,
        ]);
    }

    protected function quantityScale(): QuantityScale
    {
        return $this->container->get(QuantityScale::class);
    }


    /**
     * A filter box's typed date, as a \DateTimeImmutable — or null when it says nothing usable.
     *
     * Deliberately forgiving, and deliberately NOT TextInput::calendarDate(): what an admin types
     * into a filter box is not what gets stored, so "3/14/2026" has always been accepted here.
     * A value that parses as no date at all returns null, which every caller reads as "no filter" —
     * that is what keeps a malformed ?filters[...]=%%% off the 500 page.
     */
    protected function parseDateFilter(?string $raw): ?\DateTimeImmutable
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }

        // HTML <input type="date"> should yield YYYY-MM-DD, but be forgiving.
        $candidates = ['Y-m-d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y'];
        foreach ($candidates as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $v);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * A filter box's date input, as the 'Y-m-d' string a calendar-date column is compared against.
     *
     * Only the format('Y-m-d') is new — parseDateFilter() above still does the parsing, so the
     * filters accept the same range of typed formats they always have.
     *
     * Shared rather than copied (it began private on OrderController) because the Invoices grid
     * needed the same From/To range over its own calendar-date column, and a date-validation
     * helper that exists twice gets fixed once. Every sales document stores its calendar dates as
     * a plain 'Y-m-d' string for the same reason — the format compares lexicographically in
     * calendar order — so one helper serves all of them.
     */
    protected function calendarDateFilter(mixed $raw): ?string
    {
        $parsed = is_scalar($raw) ? $this->parseDateFilter((string) $raw) : null;

        return $parsed?->format('Y-m-d');
    }

    /** @return list<array<string, string>> */
    protected function paymentTermRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        $connection = $entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['payment_term'])) {
            return [];
        }

        $records = $connection->fetchAllAssociative(
            "SELECT id, name FROM payment_term WHERE status = 'Active' ORDER BY sort_order ASC, id ASC"
        );

        return array_map(static fn (array $r): array => [
            'id'   => (string) $r['id'],
            'name' => (string) $r['name'],
        ], $records);
    }

    /** @return list<array<string, string>> */
    protected function paymentMethodRowsFromDatabase(PaymentMethodResolver $paymentMethodResolver, ?Company $company = null): array
    {
        $methods = $company instanceof Company
            ? $paymentMethodResolver->getAvailableForCompany($company)
            : $paymentMethodResolver->getAllMethods();

        return array_map(static fn (PaymentMethodInterface $m): array => [
            'id'   => $m->getSlug(),
            'name' => $m->getName(),
        ], $methods);
    }

    /** @return list<array<string, string>> */
    protected function priceListRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        return array_map(
            fn (PriceList $priceList): array => $this->priceListToRow($priceList, $entityManager),
            $entityManager->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['id' => 'ASC'])
        );
    }

    /** @return array<string, string> */
    protected function priceListToRow(PriceList $priceList, EntityManagerInterface $entityManager): array
    {
        $assignments = $entityManager->getRepository(\App\Entity\CompanyFulfillmentRegion::class)->findBy([
            'priceList' => $priceList,
            'status' => 'Active',
        ]);

        $companyNames = [];
        foreach ($assignments as $assignment) {
            $companyNames[$assignment->getCompany()->getId()] = $assignment->getCompany()->getName();
        }

        return [
            'id' => (string) $priceList->getId(),
            'key' => (string) $priceList->getId(),
            'name' => $priceList->getName(),
            'currency' => $priceList->getCurrency(),
            'companies' => $companyNames === [] ? '-' : implode(', ', $companyNames),
            'companyCount' => (string) count($companyNames),
            'status' => $priceList->getStatus(),
        ];
    }

    /** @return list<array<string, string>> */
    protected function categoryRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        $rows = $entityManager->createQuery(
            'SELECT IDENTITY(p.category) AS catId, COUNT(p.id) AS cnt
             FROM ' . ProductCore::class . ' p
             GROUP BY p.category'
        )->getResult();

        $countMap = [];
        foreach ($rows as $row) {
            $countMap[(int) $row['catId']] = (int) $row['cnt'];
        }

        /** @var ProductCategory[] $all */
        $all = $entityManager->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);

        // Group by parent id (null = root)
        $childrenMap = []; // parentId (int|null) => ProductCategory[]
        foreach ($all as $cat) {
            $parentId = $cat->getParent()?->getId();
            $childrenMap[$parentId ?? 'root'][] = $cat;
        }

        // Depth-first walk to produce tree-ordered flat list
        $ordered = [];
        $walk = function (string|int $parentKey) use (&$walk, &$ordered, $childrenMap, $countMap): void {
            foreach ($childrenMap[$parentKey] ?? [] as $cat) {
                $ordered[] = $this->categoryToRow($cat, $countMap);
                $walk($cat->getId());
            }
        };
        $walk('root');

        return $ordered;
    }

    /**
     * @param array<int, int> $productCountMap
     * @return array<string, string>
     */
    protected function categoryToRow(ProductCategory $category, array $productCountMap = []): array
    {
        $depth = 0;
        $parent = $category->getParent();
        while ($parent instanceof ProductCategory) {
            ++$depth;
            $parent = $parent->getParent();
        }

        return [
            'id' => (string) $category->getId(),
            'key' => (string) $category->getId(),
            'name' => $category->getName(),
            'label' => str_repeat('— ', $depth) . $category->getName(),
            'depth' => (string) $depth,
            'parent' => $category->getParent()?->getName() ?? '(none)',
            'parentId' => (string) ($category->getParent()?->getId() ?? ''),
            'status' => $category->getStatus(),
            'products' => (string) ($productCountMap[(int) $category->getId()] ?? 0),
        ];
    }

    /**
     * "Plumbing > Plumbing Fittings", not just "Plumbing Fittings" — a product grid's Category
     * column showing only the leaf name is indistinguishable from a totally different top-level
     * category that happens to share a child name (two categories legitimately can: "Plumbing >
     * Fittings" and "HVAC > Fittings" are different rows). Root first, this category last.
     */
    protected function categoryPath(ProductCategory $category): string
    {
        $names = [$category->getName()];
        $parent = $category->getParent();
        while ($parent instanceof ProductCategory) {
            array_unshift($names, $parent->getName());
            $parent = $parent->getParent();
        }

        return implode(' > ', $names);
    }

    /**
     * The customer a document list is scoped to, when the query names one by id.
     *
     * Lifted here rather than copied a sixth time. OrderController, CreditMemoController and
     * SalesReturnController each carried a private method that read `XSearch[company_id]` through
     * an `(int)` cast and answered a nullable Company, and a nullable Company cannot express the
     * difference between "no customer was named" and "a customer was named and there is no such
     * customer" — see CompanyListScope for why that difference is the whole point. That collapse
     * WAS the defect: all three grids answered an unresolvable id by dropping the where clause and
     * listing every customer's documents, and `12abc` reached customer 12 without even a warning.
     *
     * All five document grids now come through here, and the three private methods that remain
     * (OrderController::companyFromRequest, SalesReturnController::searchCompany) delegate to it —
     * they are the CREATE screens' need, which is a nullable Company and genuinely has no third
     * state: either there is a customer to raise the document against or the screen asks for one.
     * Delegating still fixes the cast for them, so `?company=12abc` no longer pre-fills customer 12.
     *
     * @param string       $searchKey the grid's parameter group, e.g. 'InvoiceSearch'
     * @param list<string> $bareKeys  un-nested spellings of the same parameter, in precedence order
     */
    protected function companyListScope(Request $request, EntityManagerInterface $entityManager, string $searchKey, array $bareKeys = ['company_id']): CompanyListScope
    {
        $requestedId = CompanyListScope::requestedIdIn($request->query->all(), $searchKey, $bareKeys);

        if ($requestedId === null) {
            return CompanyListScope::none();
        }

        if (!CompanyListScope::isIdShaped($requestedId)) {
            return CompanyListScope::unresolved($requestedId);
        }

        $company = $entityManager->find(Company::class, (int) $requestedId);

        return $company instanceof Company
            ? CompanyListScope::of($company, $requestedId)
            : CompanyListScope::unresolved($requestedId);
    }

    /**
     * Of the given {@see RequestedParent}s, the ones that asked for a parent and did not get one —
     * for `templates/admin/_partials/bad_parent_notice.html.twig` (queue item 51, moved here from
     * `AbstractProcurementController` alongside `RequestedParent` itself).
     *
     * @return list<RequestedParent<object>>
     */
    protected function unresolvedParents(RequestedParent ...$candidates): array
    {
        return array_values(array_filter($candidates, static fn (RequestedParent $p): bool => $p->isUnresolved()));
    }

    /** @return list<array<string, string>> */
    protected function companyRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        return array_map(
            fn (Company $company): array => $this->companyToRow($company),
            $entityManager->getRepository(Company::class)->findBy([], ['name' => 'ASC'])
        );
    }

    /** @return array<string, string> */
    protected function companyToRow(Company $company): array
    {
        $billingAddress = '-';
        foreach ($company->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                $parts = array_filter([
                    $address->getAddressLine1(),
                    $address->getCity(),
                    $address->getProvince(),
                    $address->getCountry(),
                ], fn(?string $part) => $part !== null && trim($part) !== '');
                $billingAddress = implode(', ', $parts) ?: '-';
                break;
            }
        }

        return [
            'id' => (string) $company->getId(),
            'name' => $company->getName(),
            'code' => $company->getCode() ?? '',
            'email' => $company->getPrimaryEmail() ?? '',
            'primaryEmail' => $company->getPrimaryEmail() ?? '',
            'phoneNumber' => $company->getPhoneNumber() ?? '',
            'tradeName' => $company->getTradeName() ?? '',
            'firstName' => $company->getFirstName() ?? '',
            'lastName' => $company->getLastName() ?? '',
            'contact' => trim(($company->getFirstName() ?? '').' '.($company->getLastName() ?? '')) ?: '-',
            'billingAddress' => $billingAddress,
            'accountType' => $this->normalizeAccountType($company->getAccountType()),
            'paymentTermId' => (string) ($company->getPaymentTermId() ?? ''),
            'creditLimit' => $company->getCreditLimit() ?? '',
            'salesRepNote' => $company->getSalesRepNote() ?? '',
            'salesRepUserId' => (string) ($company->getSalesRepUser()?->getId() ?? ''),
            'salesRepUserName' => $this->adminDisplayNameFor($company->getSalesRepUser()) ?? '',
            'status' => $company->getStatus(),
            'apiEnabled' => $company->isApiEnabled(),
            'users' => '0',
        ];
    }

    protected function applyCompanyRequest(Company $company, Request $request, EntityManagerInterface $entityManager): void
    {
        $company
            ->setName(trim((string) $request->request->get('name', '')))
            ->setCode($this->cleanNullableRequestValue($request, 'code'))
            ->setPrimaryEmail($this->cleanNullableRequestValue($request, 'primary_email'))
            ->setPhoneNumber($this->cleanNullableRequestValue($request, 'phone_number'))
            ->setTradeName($this->cleanNullableRequestValue($request, 'trade_name'))
            ->setAccountType($this->normalizeAccountType((string) $request->request->get('account_type', 'Business')))
            ->setFirstName($this->cleanNullableRequestValue($request, 'first_name'))
            ->setLastName($this->cleanNullableRequestValue($request, 'last_name'))
            // Outer of the two API gates (#521). A checkbox submits nothing when unticked, so
            // absence has to mean false — reading it as "leave unchanged" would make the box
            // impossible to turn off.
            ->setApiEnabled($request->request->getBoolean('api_enabled'));
        // Notes are managed exclusively via addNote()/updateNote()/deleteNote() (CompanyController) as an
        // append-only "timestamp|text" log — this form has no notes field, so notes must not be touched here.
        // $salesRepNote is likewise untouched here: it is registration-time free text (AuthController),
        // never something this admin form writes — see #718.

        // Through the HasStatus gate, not the fluent chain above: setStatus() needs an actor, and it
        // throws on a value outside {Active, Inactive, Review} rather than writing it silently — the
        // form itself only ever offers the first two, so this only ever refuses a forged POST.
        $actor = $this->getUser();
        $company->setStatus(
            (string) $request->request->get('status', 'Active'),
            $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system(),
        );

        $paymentTermIdRaw = (int) $request->request->get('payment_term_id', 0);
        $company->setPaymentTermId($paymentTermIdRaw > 0 ? $paymentTermIdRaw : null);

        // Nullable, not defaulted to 0: a blank box means "no limit", the same as a box nobody has
        // ever filled in, not a limit of $0 (#724). setCreditLimit() itself treats a typed 0 the
        // same way, for a direct POST that skips this box's own blank-string handling.
        $creditLimitRaw = trim((string) $request->request->get('credit_limit', ''));
        $company->setCreditLimit($creditLimitRaw !== '' ? $creditLimitRaw : null);

        // Only an eligible, real staff account may be assigned (#718) — never trusted from the raw id
        // alone, or a forged POST could assign a company to any admin account regardless of the
        // eligibility flag the picker itself is scoped by.
        $salesRepUserIdRaw = (int) $request->request->get('sales_rep_user_id', 0);
        $salesRepUser = $salesRepUserIdRaw > 0 ? $entityManager->find(\App\Entity\AdminUser::class, $salesRepUserIdRaw) : null;
        $company->setSalesRepUser($salesRepUser instanceof \App\Entity\AdminUser && $salesRepUser->isSalesRepEligible() ? $salesRepUser : null);
    }

    /**
     * @return list<array<string, string>>
     *
     * Filtered in PHP rather than by the raw `sales_rep_eligible` column: eligibility is now also
     * granted by the Sales Rep role (AdminUser::isSalesRepEligible()), and roles live in a JSON
     * column a findBy() criteria array cannot query.
     */
    protected function salesRepRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        $admins = $entityManager->getRepository(\App\Entity\AdminUser::class)
            ->findBy(['status' => 'Active'], ['firstName' => 'ASC', 'lastName' => 'ASC']);

        $eligible = array_filter($admins, static fn (\App\Entity\AdminUser $admin): bool => $admin->isSalesRepEligible());

        return array_map(fn (\App\Entity\AdminUser $admin): array => [
            'id' => (string) $admin->getId(),
            'name' => $this->adminDisplayNameFor($admin) ?? $admin->getEmail(),
        ], $eligible);
    }

    /** Same "First Last" convention used everywhere else a staff name is shown; null only for null input. */
    protected function adminDisplayNameFor(?\App\Entity\AdminUser $admin): ?string
    {
        if (!$admin instanceof \App\Entity\AdminUser) {
            return null;
        }

        $name = trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? ''));

        return $name !== '' ? $name : $admin->getEmail();
    }

    protected function normalizeAccountType(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Business';
        }

        $normalized = strtolower(str_replace(['_', ' '], ['-', '-'], $value));
        if (in_array($normalized, ['non-business', 'nonbusiness', 'non-buisness', 'nonbuisness', 'non-bussiness', 'nonbussiness'], true)) {
            return 'Non-business';
        }

        // Backward compatibility: legacy value.
        if ($normalized === 'personal') {
            return 'Non-business';
        }

        return 'Business';
    }

    protected function cleanNullableRequestValue(Request $request, string $key): ?string
    {
        $value = trim((string) $request->request->get($key, ''));

        return $value !== '' ? $value : null;
    }

    /** @return list<array<string, string>> */
    protected function userRowsFromDatabase(EntityManagerInterface $entityManager, ?Company $company = null): array
    {
        $rows = [];
        
        if ($company === null) {
            $adminUsers = $entityManager->getRepository(\App\Entity\AdminUser::class)->findAll();
            foreach ($adminUsers as $admin) {
                $rows[] = [
                    'id' => (string)$admin->getId(),
                    'firstName' => $admin->getFirstName() ?? '',
                    'lastName' => $admin->getLastName() ?? '',
                    'name' => trim(($admin->getFirstName() ?? '').' '.($admin->getLastName() ?? '')) ?: '-',
                    'email' => $admin->getEmail(),
                    'phone' => $admin->getPhoneNumber() ?? '',
                    'role' => 'Super Admin',
                    'type' => 'Admin',
                    'company' => '-',
                    'status' => $admin->getStatus(),
                ];
            }
        }

        $criteria = $company ? ['company' => $company] : [];
        $customerUsers = $entityManager->getRepository(\App\Entity\CustomerUser::class)->findBy($criteria);
        foreach ($customerUsers as $customer) {
            $roles = $customer->getRoles();
            $rows[] = [
                'id' => (string)$customer->getId(),
                'firstName' => $customer->getFirstName() ?? '',
                'lastName' => $customer->getLastName() ?? '',
                'name' => trim(($customer->getFirstName() ?? '').' '.($customer->getLastName() ?? '')) ?: '-',
                'email' => $customer->getEmail(),
                'phone' => $customer->getPhoneNumber() ?? '',
                'role' => in_array('ROLE_COMPANY_OWNER', $roles, true) ? 'Owner' : 'Company Staff',
                'type' => 'Customer',
                'company' => $customer->getCompany()?->getName() ?? '',
                'companyId' => (string) ($customer->getCompany()?->getId() ?? ''),
                'status' => $customer->getStatus(),
                'apiEnabled' => $customer->isApiEnabled(),
                'companyApiEnabled' => $customer->getCompany()?->isApiEnabled() ?? false,
            ];
        }

        return $rows;
    }

    /** @return list<array{label: string, href: string, text: string}> */
	    protected static function adminSections(): array
	    {
	        return [
	            ['label' => 'Product details', 'href' => '/admin/product/detail/index', 'text' => 'Core product data, private SKUs, status, category, and inventory health.'],
	            ['label' => 'Product pricing', 'href' => '/admin/product/price/index', 'text' => 'Separate pricing rows by price list, company eligibility, and currency.'],
	            ['label' => 'Product import', 'href' => '/admin/product/import', 'text' => 'Bulk import CSV to update product core fields, inventory by location, and price lists.'],
	            ['label' => 'Companies', 'href' => '/admin/company', 'text' => 'Company accounts, assigned price lists, users, and catalog permissions.'],
	            ['label' => 'Configuration', 'href' => '/admin/settings', 'text' => 'Base currency, email templates, inventory locations, and system logs.'],
	        ];
	    }

    protected function nullableString(mixed $value): ?string
    {
        return TextInput::nullableString($value);
    }

    /**
     * A posted `lines[N][lot_id]` — the lot/serial picker's choice (2026-09-14 lot/serial/expiry
     * plan) — as a positive id or null. Zero, blank, and anything unreadable as an int are all "no
     * lot picked", the same way a blank text box already reads as null everywhere else on a line.
     */
    protected function nullableLotId(mixed $value): ?int
    {
        $id = is_scalar($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * A submitted line's own `price`/`cost` text, or '' when the field is missing or arrived as an
     * array instead of a scalar. A line field like `lines[N][price]` is meant to carry one posted
     * value, but a tampered or malformed post can turn it into a nested array (e.g.
     * `lines[N][price][]=`), and casting an array straight to string is a PHP warning some
     * environments promote to an uncaught error and a stack-trace 500 (#395) rather than a value.
     * Treated the same as a blank field.
     */
    protected function rawLineAmount(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * `$request->request->all('lines')`, safe to hand a template as `submitted` — the raw posted
     * rows a save's re-render echoes back UNCHANGED into each box's own `value="..."` attribute
     * (see `admin/_partials/sales_line_row.html.twig`'s own docblock on why that echo is byte-for-
     * byte deliberate, for `LineDenomination::boxUntouched()`).
     *
     * `rawLineAmount()`/`nullableString()` guard price/cost/sku/weight/unit/batch on the SAVE path,
     * but the re-render path never called either — it read the request array straight through, so
     * a tampered `lines[0][price][0][0]=1` sailed past every save-time guard (the row was correctly
     * treated as blank and never persisted) only to 500 on the way back out: Twig's `{{ line.price
     * }}` tries to print the array Twig received, "Warning: Array to string conversion" promoted to
     * an uncaught RuntimeError (#775, second finding — the first was fixed, this was not the same
     * bug). Every field of every row is swept here, not just the six named ones, so a field added
     * to the form later inherits the guard instead of needing to be added to a list by hand.
     *
     * @return array<int|string, array<string, mixed>>
     */
    protected function submittedLinesForRerender(Request $request): array
    {
        // array_map(), not array_walk()/a foreach rebuilding the array: a single-array call keeps
        // the original keys, which matters here — the template looks a row up by the exact index
        // the form rendered it under (`submitted[index]`), not by position.
        return array_map(
            static fn (mixed $row): array => is_array($row)
                ? array_map(static fn (mixed $value): mixed => is_scalar($value) ? $value : '', $row)
                : [],
            $request->request->all('lines'),
        );
    }

    /**
     * The line rows a sell-side form posted, keyed by the index the form rendered each row under,
     * in that order, with the no-JS Remove row already dropped.
     *
     * ## Why every sell-side form posts `lines[N][field]`
     *
     * The order form always did. The quote and the standalone invoice used to post fourteen
     * PARALLEL arrays instead — `line_qty[]`, `line_price[]`, `line_product_id[]` and the rest —
     * and those APPEND: row N's value sits at position N only for as long as every row posts
     * exactly one entry into every array. Both templates carried filler hidden inputs to hold that
     * up, and the quote's did not cover `line_product_id[]`, which only product rows posted. A
     * quote with a custom line above a product line therefore read the product onto the custom
     * line, replaced the typed description with the product's name, and dropped the real product
     * row as empty — a successful save, no warning, on a document that converts into an order.
     *
     * Grouping a row's fields under the row's own index removes the alignment requirement
     * altogether: there is no array left to slip, and no filler input left for anybody to forget.
     * That is why this is the one convention and why a reader for the old one is not kept beside
     * it — two accepted spellings would be the same defect wearing a second name.
     *
     * ## What this decides and what it does not
     *
     * Only the SHAPE of the post: which rows there are, in what order, and that each is an array.
     * What a field MEANS — whether a blank price is "no pricing" or the catalogue's figure, which
     * unit a quantity is said in, whether an absent key is an edit or simply a narrower post — is
     * the document's own rule and stays with the document.
     *
     * A row that did not arrive as an array is dropped rather than coerced: `lines[0]=x` is not a
     * row, and the reasoning rawLineAmount() applies to a scalar field applies here to the row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function postedLineRows(Request $request): array
    {
        $rows = [];
        foreach ($request->request->all('lines') as $index => $row) {
            if (is_array($row)) {
                $rows[(int) $index] = $row;
            }
        }

        // No-JS fallback: the row's Remove control is a real submit carrying its own index — the
        // only thing that says which row was pressed, since every row posts. Dropped before
        // anything reads the rows, and the survivors keep their original keys, exactly as
        // OrderController::edit()'s remove_line handling does.
        $removeIndex = $request->request->get('remove_line');
        if ($removeIndex !== null && $removeIndex !== '' && is_scalar($removeIndex)) {
            unset($rows[(int) $removeIndex]);
        }

        ksort($rows);

        return $rows;
    }

    /**
     * The unit a posted line names, or null for the product's base unit (#659).
     *
     * Shared by the order, the quote and the standalone invoice because the rule is the same on all
     * three and no form's shape has anything to do with it — every one of them posts
     * `lines[N][unit_id]`, and it arrives here as one scalar.
     *
     * The unit must be one THIS product is available in. The list is per product on purpose
     * (`product_available_unit`), so a unit from outside it is not an edit to refuse with a message
     * — it is a posted id that cannot mean anything, and it reads as "base unit", exactly as a row
     * naming no unit at all does. That also covers the row whose product was changed in the browser
     * while the selector still held the previous product's units.
     *
     * The product's own BASE unit answers null rather than itself, which is not a rejection: NULL is
     * how `DenominatedQuantity` encodes "entered in base units", and storing the base unit in the
     * column as well would be the same fact in two places with a factor of 1 between them.
     */
    protected function lineUnitFor(mixed $rawId, ?ProductCore $product, EntityManagerInterface $entityManager): ?UnitOfMeasure
    {
        if ($product === null) {
            return null;
        }

        $id = is_scalar($rawId) ? (int) $rawId : 0;
        if ($id <= 0 || $id === (int) ($product->getBaseUnit()?->getId() ?? 0)) {
            return null;
        }

        $unit = $entityManager->find(UnitOfMeasure::class, $id);
        if (!$unit instanceof UnitOfMeasure) {
            return null;
        }

        $offered = (int) $entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ProductAvailableUnit::class, 'a')
            ->andWhere('a.product = :product')->setParameter('product', $product)
            ->andWhere('a.unit = :unit')->setParameter('unit', $unit)
            ->getQuery()->getSingleScalarResult();

        return $offered > 0 ? $unit : null;
    }

    /**
     * The units each named product may be expressed in, keyed by product id (#659).
     *
     * One query for a whole document rather than one per row: a twenty-line order asking per row is
     * twenty queries to render a dropdown.
     *
     * The base unit is deliberately absent. It is offered by the U/M cell as the empty option — that
     * is what NULL means in the column — and listing it here as well would put the same unit in the
     * dropdown twice, once as "the base" and once by code, posting two values that mean one thing.
     *
     * Plain id/code pairs rather than entities, because that is what every other value in a line row
     * is and a template holding an entity is a template that can lazily fetch mid-render.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<array{id: int, code: string}>>
     */
    protected function lineUnitChoicesFor(array $productIds, EntityManagerInterface $entityManager): array
    {
        /** @var \App\Repository\ProductAvailableUnitRepository $repository */
        $repository = $entityManager->getRepository(ProductAvailableUnit::class);

        $choices = [];
        foreach ($repository->forProducts($productIds) as $productId => $rows) {
            foreach ($rows as $row) {
                $choices[$productId][] = ['id' => (int) $row->getUnit()->getId(), 'code' => $row->getUnit()->getCode()];
            }
        }

        return $choices;
    }

    /**
     * Writes a document line's quantity and the denomination it was said in (#601, #659).
     *
     * Two shapes, because a line entered in another unit and one entered in base units are two
     * different statements:
     *
     *  - **A unit was picked.** DenominatedQuantity::setEnteredQuantity() is the one writer that owns
     *    all three figures — the entered one, the unit, and the base one resolved from both — so a
     *    row can never say "40 BOX-12" while its base column reads 40. It resolves the base itself
     *    rather than taking $baseQuantity, which is why the caller's own figure is passed only for
     *    the base-unit branch: one resolution, in one place.
     *  - **No unit.** The plain setter, exactly as before. It clears `quantity_entered` and `unit_id`
     *    (DenominatedQuantity::forgetEnteredExpression()), which is correct: writing a raw quantity
     *    IS saying "this row is that many base units". It also keeps every existing line's stored
     *    figure formatted precisely as it always was, so no historical row changes shape on a save
     *    that did not re-denominate it.
     *
     * The rounding note for the converted branch: the base is `entered x factorToBase` at the
     * configured scale, so re-expressing 479 base units in boxes of 12 gives 39.9167 boxes and a
     * base of 479.0004. That is the trait's invariant — the pair may never disagree — and 4/10,000
     * of a unit at the column's last digit is the price of it. A line whose base divides evenly by
     * the unit (which is every line the selector actually creates) round-trips exactly.
     *
     * ## Both branches round through {@see QuantityScale}, and the second one used to lose two places
     *
     * The base branch was `number_format($baseQuantity, 2, '.', '')`. Two places, against a
     * `NUMERIC(14, 4)` column and beside an entered branch that was already writing four — so a
     * sales line for 12.3456 was stored as 12.35, and the third and fourth decimal a person had
     * typed were gone by the time anything downstream saw the row. Nothing announced it; the screen
     * simply showed a different number from the one that was entered.
     *
     * Both branches now ask the one service how many places a quantity has. A store set to three
     * gets three from both, which is the whole point of the setting being global.
     */
    protected function applyLineQuantity(DenominatedLine $line, float $entered, float $baseQuantity, ?UnitOfMeasure $unit, ?UnitOfMeasure $base): void
    {
        if ($unit !== null) {
            $line->setEnteredQuantity($this->quantityScale()->round($entered), $unit, $base);

            return;
        }

        // Not on the interface — a base column is named differently on each of the fourteen rows
        // that carry one (DenominatedQuantity's docblock says why) — but every SALES line has it,
        // and those are the three with a selector on them.
        $line->setQuantity($this->quantityScale()->round($baseQuantity));
    }

    /**
     * A unit price at the scale its denomination needs (#601, #659).
     *
     * A line entered in the base unit stores what it always stored — two places — so nothing about
     * an existing document changes shape. A CONVERTED price gets the column's full six, because two
     * places cannot hold "$10.00 per box of 12" at all: it lands on 0.83 and loses two cents on
     * every 600 units. The column has been `NUMERIC(18, 6)` since #645; this is what writes into it.
     */
    protected function lineRate(string $value, ?UnitOfMeasure $unit): string
    {
        return number_format(
            (float) $value,
            $unit === null ? 2 : LineDenomination::RATE_SCALE,
            '.',
            '',
        );
    }

    protected function fullName(mixed $firstName, mixed $lastName, mixed $fallback): ?string
    {
        $name = trim(trim((string) $firstName) . ' ' . trim((string) $lastName));

        return $name !== '' ? $name : $this->nullableString($fallback);
    }

    /**
     * Shared billing/shipping address row shape for the order and estimate forms' address cards.
     *
     * Accepts either an address-book row or a document's own snapshot — they expose the same
     * accessors, and both forms need to render whichever one they have. Falls back to the
     * company's own contact fields when no address-specific value is set.
     *
     * @return array<string, string>
     */
    protected function addressToRow(CompanyAddress|AbstractDocumentAddress|null $address, Company $company): array
    {
        return [
            'id' => (string) ($address?->getId() ?? ''),
            'companyName' => $address?->getCompanyName() ?? $company->getName(),
            'firstName' => $address?->getFirstName() ?? $company->getFirstName() ?? '',
            'lastName' => $address?->getLastName() ?? $company->getLastName() ?? '',
            'phone' => $address?->getPhone() ?? $company->getPhoneNumber() ?? '',
            'addressLine1' => $address?->getAddressLine1() ?? '',
            'addressLine2' => $address?->getAddressLine2() ?? '',
            'city' => $address?->getCity() ?? '',
            'country' => $address?->getCountry() ?? 'CA',
            'province' => $address?->getProvince() ?? '',
            'postalCode' => $address?->getPostalCode() ?? '',
            'fax' => $address?->getFax() ?? '',
            'primaryEmail' => $address?->getEmailPrimary() ?? $company->getPrimaryEmail() ?? '',
            'secondaryEmail' => $address?->getEmailSecondary() ?? '',
            'deliveryInstructions' => $address?->getDeliveryInstructions() ?? '',
        ];
    }

    /**
     * Applies whichever address fields the submitted form actually carried.
     *
     * A field that is *absent* from the POST leaves the stored value alone; only a field that is
     * present and empty clears it. The two are not the same thing: an empty text input still posts
     * (as ''), so a user blanking a box is honoured, while a form that simply never rendered the
     * box cannot destroy what it never showed.
     *
     * This method is shared by the order and quote forms, which do not render an identical field
     * set — the quote form had no delivery-instructions box, so every quote save silently nulled
     * the field, and the loss propagated into converted orders' packing slips and invoices (#235).
     * Guarding here rather than at the call sites means the next field one form gains before the
     * other cannot repeat it.
     */
    protected function applyAddressEditsFromRequest(CompanyAddress|AbstractDocumentAddress $address, string $prefix, Request $request): void
    {
        $setters = [
            '_company_name' => $address->setCompanyName(...),
            '_first_name' => $address->setFirstName(...),
            '_last_name' => $address->setLastName(...),
            '_phone' => $address->setPhone(...),
            '_address_1' => $address->setAddressLine1(...),
            '_address_2' => $address->setAddressLine2(...),
            '_city' => $address->setCity(...),
            '_country' => $address->setCountry(...),
            '_province' => $address->setProvince(...),
            '_postal_code' => $address->setPostalCode(...),
            '_fax' => $address->setFax(...),
            '_primary_email' => $address->setEmailPrimary(...),
            '_secondary_email' => $address->setEmailSecondary(...),
            '_delivery_instructions' => $address->setDeliveryInstructions(...),
        ];

        foreach ($setters as $suffix => $setter) {
            if (!$request->request->has($prefix . $suffix)) {
                continue;
            }

            $raw = $request->request->get($prefix . $suffix);

            // Every field above lands in a column with a declared `length:`, so nullableString() is
            // all they need here — the width is a schema fact, whether or not SQLite enforces it.
            // Delivery instructions are the exception: theirs is `type: 'text'` on both the address
            // book and the document snapshot, so there is no width to fall back on and this card is
            // the one path that can put an unbounded value on an order or quote directly, without
            // it having come from a (now capped) CompanyAddress via copyFrom(). Capping it here is
            // what keeps the limit a property of the field rather than of the screen you saved
            // from — the address book and the document it turns into must not disagree about how
            // much of an instruction survives.
            $setter($suffix === '_delivery_instructions'
                ? TextInput::nullableStringMax($raw, TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH)
                : $this->nullableString($raw));
        }
    }

    /** @return list<array<string, string>> */
    protected function addressBookRows(Company $company): array
    {
        $rows = [];
        foreach ($company->getAddresses() as $address) {
            if (!$address instanceof CompanyAddress) {
                continue;
            }

            $row = $this->addressToRow($address, $company);
            $row['id'] = (string) $address->getId();
            $row['label'] = $this->addressLabel($address);
            $rows[] = $row;
        }

        return $rows;
    }

    protected function addressLabel(CompanyAddress $address): string
    {
        $name = trim(($address->getFirstName() ?? '') . ' ' . ($address->getLastName() ?? ''));
        $parts = array_filter([
            $address->getLabel(),
            $name,
            $address->getAddressLine1(),
            $address->getCity(),
            $address->getProvince(),
            $address->getCountry(),
            $address->getPostalCode(),
        ], fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode(', ', $parts);
    }

    /** Default (or first) address of the given type on the company's address book. */
    protected function defaultAddress(Company $company, string $type): ?CompanyAddress
    {
        $fallback = null;
        foreach ($company->getAddresses() as $address) {
            if (!$address instanceof CompanyAddress) {
                continue;
            }

            $fallback ??= $address;
            if ($type === 'billing' && $address->isDefaultBilling()) {
                return $address;
            }
            if ($type === 'shipping' && $address->isDefaultShipping()) {
                return $address;
            }
        }

        return $fallback;
    }

    /**
     * @param bool $applyEmail false where the screen does not offer the field — a staff edit, where the
     *                         address is the login identifier for the admin firewall. Guarded here
     *                         rather than at the call site so the rule cannot be lost by a future
     *                         caller: the default stays true for the create routes, which must set it.
     */
    protected function applyUserRequest(\App\Entity\AdminUser|\App\Entity\CustomerUser $user, Request $request, EntityManagerInterface $entityManager, bool $applyEmail = true): void
    {
        if ($applyEmail) {
            $user->setEmail(trim((string) $request->request->get('email', '')));
        }

        $user
            ->setFirstName($this->cleanNullableRequestValue($request, 'first_name'))
            ->setLastName($this->cleanNullableRequestValue($request, 'last_name'))
            ->setPhoneNumber($this->cleanNullableRequestValue($request, 'phone_number'));

        // Through the HasStatus gate: it throws on anything outside {Active, Inactive} rather than
        // writing it silently, which this form's own request handling never checked before.
        $actor = $this->getUser();
        $user->setStatus(
            (string) $request->request->get('status', 'Active'),
            $actor instanceof AdminUser ? DocumentActor::forAdmin($actor) : DocumentActor::system(),
        );

        if ($user instanceof \App\Entity\CustomerUser) {
            // Inner of the two API gates (#521) — whether THIS person may hold a key, inside a
            // company that is itself permitted. Deliberately not tied to role: an admin decides per
            // person, and what the key can then do is settled by that person's own permissions,
            // since a request carrying it is authenticated as them. Absence means false, because an
            // unticked checkbox submits nothing.
            $user->setApiEnabled($request->request->getBoolean('api_enabled'));

            $companyId = trim((string) $request->request->get('company', ''));
            $company = ($companyId !== '' && ctype_digit($companyId) && (int) $companyId > 0)
                ? $entityManager->find(Company::class, (int) $companyId)
                : null;
            $user->setCompany($company);
        }

        if ($user instanceof \App\Entity\AdminUser) {
            // Scopes the Customer form's Sales Rep picker (#718), staff only. Same "absence means
            // false" reasoning as api_enabled above — an unticked checkbox submits nothing.
            $user->setSalesRepEligible($request->request->getBoolean('sales_rep_eligible'));
        }
    }

}
