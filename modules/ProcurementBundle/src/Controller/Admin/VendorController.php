<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\CustomFieldRenderer;
use App\Service\DisplayNumber;
use App\Service\DocumentActorResolver;
use App\Service\Region;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPaymentApplication;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use ProcurementBundle\Entity\VendorReturn;
use App\Http\RequestedParent;
use ProcurementBundle\Reporting\ApAgingReport;
use ProcurementBundle\Repository\VendorNoteRepository;
use ProcurementBundle\Repository\VendorRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vendors and their master data (#555, #605, #606).
 *
 * Vendors are deactivated, never deleted. Every purchase order, receipt and bill ever raised
 * against one points at it, and a vendor's own name is snapshotted onto each of those documents
 * precisely so that history reads correctly — deleting the row would either cascade that history
 * away or leave it pointing at nothing, and neither is a thing an AP department can live with.
 *
 * ## Except a vendor nobody ever raised anything against (#613)
 *
 * Every sentence above is about a vendor with documents. A row typed in with the name misspelled and
 * saved before anybody noticed has none, and until now the only thing that could be done with it was
 * to set it Inactive and leave it in the table forever — where it goes on appearing in the vendor
 * picker's history and in every "which of these two is the real one" conversation.
 *
 * So delete counts the three columns that name a vendor — `purchase_order.vendor_id`,
 * `goods_receipt.vendor_id`, `vendor_bill.vendor_id` — and refuses with the numbers if any is
 * non-zero. REFUSED rather than cascaded, and the FK makes that mandatory rather than merely
 * preferable: `vendor_id` is `NOT NULL` on all three, so there is no SET NULL to fall back on and
 * the database would take the documents with the vendor. `vendor_address`, `vendor_contact` and
 * `vendor_note` are the things that do go with it, and correctly — all three are part of the vendor
 * record, and every document that ever printed an address carries its own frozen copy.
 *
 * ## The master data screens (#605)
 *
 * Contacts, notes, addresses and their purposes are all panels on ONE detail page,
 * `/admin/bundles/procurement/vendors/{id}`, rather than four screens. That is a detail page and
 * carries no `.table-scroll-region` — the region's 220px floor and 100vh clamp are for list screens
 * and break a page made of stacked sections.
 *
 * **Every panel here works with JavaScript off**, which is what shapes the markup: each repeatable
 * row is a real `<form>` emitted OUTSIDE the table and joined to the controls inside it with
 * `form="..."`, because a `<form>` nested in a `<form>` is invalid HTML and every parser drops the
 * inner one. Adding a contact is a POST that comes back with the row and a fresh blank row under it
 * — the same submit-to-add-row loop #590 P2 gave transfer lines, and not a JavaScript clone of a
 * template row.
 *
 * ## Vendor contacts are records, never accounts
 *
 * Nothing in this controller hashes anything, assigns a role, or creates a security identity. See
 * VendorContact: a supplier's staff have no login here, by design and by test.
 */
#[Route('/admin/bundles/procurement/vendors')]
final class VendorController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly Region $region,
        private readonly ApAgingReport $apAging,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    /**
     * The vendor list, built the way the company list is (#660).
     *
     * One filter input per column rather than one search box over three of them, the same
     * `.table-card > form > .table-scroll-region > .wide-table-wrap > table` skeleton, the same
     * footer trio, and a Create Vendor button in the title row instead of a create form sitting on
     * the list. `/admin/company` is the screen this conforms to.
     *
     * Rows are ARRAYS, not entities, exactly as `CompanyController::index()` hands
     * `companyToRow()` output to `admin/company/_list_rows.html.twig`. That is what keeps the
     * primary contact and the remit-to address off the template's hot path: resolving them per row
     * in Twig is one lazy-load per row, and the per-page control offers 500.
     */
    #[Route('', name: 'admin_bundle_procurement_vendors', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, VendorRepository::FILTER_KEYS);
        $paging = $this->paging($request, 'name', 'asc');

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $result = $repo->search($filters, $paging['page'], $paging['limit'], $paging['sort'], $paging['dir']);

        $ids = array_values(array_filter(array_map(
            static fn (Vendor $vendor): int => (int) $vendor->getId(),
            $result['rows'],
        )));
        $contacts = $repo->primaryContactsFor($ids);
        $remitTo = $repo->remitToAddressesFor($ids);

        $rows = array_map(
            fn (Vendor $vendor): array => $this->vendorToRow(
                $vendor,
                $contacts[(int) $vendor->getId()] ?? null,
                $remitTo[(int) $vendor->getId()] ?? null,
            ),
            $result['rows'],
        );

        return $this->render('@Procurement/vendors.html.twig', [
            'rows' => $rows,
            'total' => $result['total'],
            'statuses' => Vendor::statuses(),
            'filters' => $filters,
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'pages' => max(1, (int) ceil($result['total'] / $paging['limit'])),
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * One list row, flattened for the template.
     *
     * The counterpart of `AbstractAdminController::companyToRow()`, and deliberately the same
     * shape: every value is already a display string, `'-'` stands for "nothing recorded", and the
     * template decides nothing except how to lay it out.
     *
     * @return array<string, string>
     */
    private function vendorToRow(Vendor $vendor, ?VendorContact $primaryContact, ?VendorAddress $remitTo): array
    {
        $remitToLine = '-';
        if ($remitTo instanceof VendorAddress) {
            // Same four parts in the same order as companyToRow()'s billing address, so the two
            // screens' address columns read identically.
            $parts = array_filter([
                $remitTo->getAddressLine1(),
                $remitTo->getCity(),
                (string) $remitTo->getProvince(),
                $remitTo->getCountry(),
            ], static fn (?string $part): bool => $part !== null && trim($part) !== '');
            $remitToLine = implode(', ', $parts) ?: '-';
        }

        $contact = '-';
        if ($primaryContact instanceof VendorContact) {
            // A supplier's orders desk is a real contact with no personal name on it, so the email
            // or the phone stands in rather than the row reading as empty. `company` cannot hit
            // this case — its contact is two columns on the company row itself.
            $contact = trim($primaryContact->getName())
                ?: (string) ($primaryContact->getEmail() ?? $primaryContact->getPhone() ?? '');
            $contact = $contact === '' ? '-' : $contact;
        }

        return [
            'id' => (string) $vendor->getId(),
            'name' => $vendor->getName(),
            'email' => $vendor->getEmail() ?? '-',
            'contact' => $contact,
            'remitTo' => $remitToLine,
            'accountNumber' => $vendor->getAccountNumber() ?? '-',
            'paymentTerm' => $vendor->getPaymentTerm() ?? '-',
            'currency' => $vendor->getCurrency(),
            'status' => $vendor->getStatus(),
        ];
    }

    /**
     * Creating a vendor is its own page (#660), reached by Create Vendor on the list.
     *
     * It was an inline form on the list screen, which is the one thing `/admin/company` has never
     * done: `admin_company_create` is a page at `/admin/company/create`, and this is its
     * counterpart. The FIELDS are not duplicated here — `_vendor_fields.html.twig` is the single
     * copy, included by this page and by the detail screen's edit form, so the two cannot drift.
     *
     * A rejected submission comes back as 422 with what was typed still in the boxes, the way
     * `CompanyController::create()` does, rather than as a redirect that throws the typing away.
     */
    #[Route('/new', name: 'admin_bundle_procurement_vendor_create', methods: ['GET', 'POST'])]
    public function create(Request $request, CustomFieldRenderer $customFieldRenderer): Response
    {
        $this->denyIfInactive();

        if (!$request->isMethod('POST')) {
            return $this->render('@Procurement/vendor_form.html.twig', [
                'vendor' => $this->blankVendorForm(),
                'paymentTerms' => $this->paymentTerms(),
                'statuses' => Vendor::statuses(),
                'customFieldFragment' => $customFieldRenderer->renderFields('vendor', null, 'add'),
            ]);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'A vendor needs a name. It is what every document raised against them will say.');

            return $this->render('@Procurement/vendor_form.html.twig', [
                'vendor' => $this->postedVendorForm($request),
                'paymentTerms' => $this->paymentTerms(),
                'statuses' => Vendor::statuses(),
                // No entity exists yet — nothing was persisted before this refusal — so this is
                // null, exactly like the render above. A field the admin had typed is lost on this
                // one refusal path, the same trade-off renderFields()'s own docblock documents for
                // every other create screen in the app; $submitted exists for the one screen that
                // has opted in so far and this is not it.
                'customFieldFragment' => $customFieldRenderer->renderFields('vendor', null, 'add'),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $vendor = new Vendor();
        $this->em->persist($vendor);
        $this->applyVendorRequest($vendor, $request);
        $this->em->flush();
        // After flush, not before: saveFromRequest() needs $vendor->getId(), which persist() alone
        // does not assign — AUTOINCREMENT only exists once the INSERT has actually run.
        $customFieldRenderer->saveFromRequest('vendor', $vendor, $request);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s created. Addresses, contacts and notes go on the record below.',
            $vendor->getName(),
        ));

        // To the record, not back to the list. The four master-data panels a new vendor still needs
        // — addresses, contacts, notes, the payment term — are all on the detail page and reachable
        // from nowhere else, so a redirect to the list would make finding the row again the next
        // step every single time. `/admin/company` can go back to its list because its address book
        // and payment methods have routes of their own off the row menu.
        return $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);
    }

    /**
     * The vendor record's own edit page — the counterpart of `admin_company_update`.
     *
     * The customer record's Details card is read-only, with editing on this separate page; the
     * vendor record follows the same shape now. Reuses `vendor_form.html.twig` (mode='Update')
     * rather than a second copy of the form, and posts through the existing `save()` below —
     * nothing about how a vendor is written changes, only where the form to do it lives.
     */
    #[Route('/{id}/edit', name: 'admin_bundle_procurement_vendor_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id, CustomFieldRenderer $customFieldRenderer): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);

        return $this->render('@Procurement/vendor_form.html.twig', [
            'vendor' => $vendor,
            'paymentTerms' => $this->paymentTerms(),
            'statuses' => Vendor::statuses(),
            'mode' => 'Update',
            'customFieldFragment' => $customFieldRenderer->renderFields('vendor', $vendor, 'edit'),
        ]);
    }

    /**
     * The create form's starting values.
     *
     * An array rather than a `new Vendor()` so that the same template renders a rejected
     * submission — where `payment_term_id` may be an id no term has — without a half-built entity
     * being handed to Twig.
     *
     * @return array<string, string>
     */
    private function blankVendorForm(): array
    {
        return [
            'id' => '0',
            'name' => '',
            'accountNumber' => '',
            'email' => '',
            'phone' => '',
            'paymentTerm' => '',
            'paymentTermId' => '',
            'currency' => 'CAD',
            'status' => Vendor::STATUS_ACTIVE,
        ];
    }

    /**
     * What the rejected submission actually said, so the page comes back filled in.
     *
     * @return array<string, string>
     */
    private function postedVendorForm(Request $request): array
    {
        $form = $this->blankVendorForm();

        foreach ([
            'name' => 'name',
            'accountNumber' => 'account_number',
            'email' => 'email',
            'phone' => 'phone',
            'paymentTerm' => 'payment_term',
            'paymentTermId' => 'payment_term_id',
            'currency' => 'currency',
            'status' => 'status',
        ] as $key => $field) {
            $posted = $request->request->get($field);
            if (\is_scalar($posted)) {
                $form[$key] = trim((string) $posted);
            }
        }

        return $form;
    }


    /*
     * ------------------------------------------------------------------------------------------
     * The vendor's documents (item 43)
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The documents raised AGAINST this vendor, one tab per type, in the panel header on the record.
     *
     * The buy-side counterpart of `App\Controller\Admin\CompanyController::DOCUMENT_TABS`, built by
     * reading that one rather than inventing a second pattern, and it keeps the three decisions
     * baked into it:
     *
     *  - **Subtabs on the panel, not the page.** Switching document type swaps only this card, so
     *    Details, Contacts, Addresses and Notes stay on screen and you never lose sight of whose
     *    documents you are reading.
     *  - **Links, not JavaScript.** Each tab is a plain GET carrying `?docs=`, so the screen works
     *    with scripting off — the house rule — and one vendor's bills get a URL that can be
     *    bookmarked and mailed around.
     *  - **One list per tab, never one mixed list with a Type column.** The four do not share a
     *    meaning of "number", "date" or "status", and a mixed list invites reading a row without
     *    noticing which of the four it is.
     *
     * ## Where this one differs from the customer side, and why it must
     *
     * The customer's three tabs all implement `App\Contract\Document\CommercialDocument`, so one row
     * mapper and one fixed set of headers serve all three. The buy side's four do not:
     *
     *  - `PurchaseOrder` and `VendorBill` are commercial documents and state money.
     *  - `VendorReturn` deliberately is NOT one — see its docblock. It moves goods and states no
     *    money at all; `DebitMemo` is its money counterpart. Printing a Total column of '0.00'
     *    against a return to satisfy a shared header would be inventing a figure the document does
     *    not have.
     *  - A payment is not a document at all. It has no number and no status; it is one movement of
     *    money, and the thing worth naming beside it is the bill it went against.
     *
     * So the COLUMNS are per tab and come from the table below, and the template renders whatever
     * each tab declares. Nothing in the template branches on which type it is holding — that is the
     * property the customer side gets from a fixed header row, kept here by declaring the header
     * row per tab instead.
     *
     * `listRoute` is the footer drill-through, and it is null on two of the four ON PURPOSE:
     * `/procurement/vendor-returns` takes no vendor filter at all (`VendorReturnRepository::search()`
     * accepts `q` and `status` only), and payments have no list screen of their own. A link built on
     * a text search for the vendor's name would quietly stop meaning "this vendor" the day two
     * suppliers share a word, which is the same reasoning that left the customer side's Estimates
     * and Invoices tabs without one.
     *
     * @var array<string, array{
     *     label: string,
     *     columns: list<string>,
     *     link: string,
     *     status: ?string,
     *     empty: string,
     *     listRoute: ?string,
     *     listLabel: ?string
     * }>
     */
    private const DOCUMENT_TABS = [
        'purchase-orders' => [
            'label' => 'Purchase Orders',
            'columns' => ['Date', 'Number', 'Items', 'Total', 'Status'],
            'link' => 'Number',
            'status' => 'Status',
            'empty' => 'Nothing has been ordered from this vendor yet.',
            'listRoute' => 'admin_bundle_procurement_purchase_orders',
            'listLabel' => 'View all purchase orders for this vendor',
        ],
        'bills' => [
            'label' => 'Bills',
            'columns' => ['Date', 'Number', 'Due', 'Total', 'Balance due', 'Status'],
            'link' => 'Number',
            'status' => 'Status',
            'empty' => 'This vendor has not billed us yet.',
            'listRoute' => 'admin_bundle_procurement_bills',
            'listLabel' => 'View all bills for this vendor',
        ],
        'returns' => [
            'label' => 'Returns',
            // No money column: a vendor return states what left the building and nothing about what
            // it was worth. Units is the figure the document actually holds.
            'columns' => ['Requested', 'Number', 'Units', 'Status'],
            'link' => 'Number',
            'status' => 'Status',
            'empty' => 'Nothing has been sent back to this vendor.',
            'listRoute' => null,
            'listLabel' => null,
        ],
        'payments' => [
            'label' => 'Payments',
            'columns' => ['Paid', 'Bill', 'Method', 'Amount', 'Recorded by'],
            'link' => 'Bill',
            'status' => null,
            'empty' => 'Nothing has been paid to this vendor yet.',
            'listRoute' => null,
            'listLabel' => null,
        ],
    ];

    /**
     * Purchase Orders, because "what is on order" is the question the vendor page could not answer
     * at all and the one most often asked of a supplier's record.
     */
    private const DEFAULT_DOCUMENT_TAB = 'purchase-orders';

    /**
     * The vendor record, its documents, and its Details/Contacts/Notes panels on one page.
     *
     * Addresses have their own Address Book page now (admin_bundle_procurement_vendor_address_book),
     * the way the customer record's do — `?address={id}` for editing one is read there, not here.
     *
     * `?docs={key}` selects the documents subtab; see DOCUMENT_TABS.
     */
    #[Route('/{id}', name: 'admin_bundle_procurement_vendor', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);

        /** @var VendorNoteRepository $noteRepo */
        $noteRepo = $this->em->getRepository(VendorNote::class);

        // Read through all() rather than get(): InputBag::get() throws a BadRequestException when
        // the parameter arrived as an array (?docs[]=bills), and a 400 is the same wrong answer as a
        // 404 here. Every shape of unusable value takes the one path below.
        $requestedTab = $request->query->all()['docs'] ?? null;
        $activeTab = \is_string($requestedTab) ? $requestedTab : self::DEFAULT_DOCUMENT_TAB;
        if (!\array_key_exists($activeTab, self::DOCUMENT_TABS)) {
            // An unknown ?docs= is a stale bookmark, not a bad request — a tab that was renamed, or
            // somebody's guess. A 404 would throw away the vendor the admin actually asked for and
            // answer a question nobody asked; the default tab answers the one they came with.
            $activeTab = self::DEFAULT_DOCUMENT_TAB;
        }
        $tab = self::DOCUMENT_TABS[$activeTab];

        $documentTabs = [];
        foreach (self::DOCUMENT_TABS as $key => $definition) {
            $documentTabs[] = [
                'key' => $key,
                'label' => $definition['label'],
                'url' => $this->generateUrl('admin_bundle_procurement_vendor', ['id' => $vendor->getId(), 'docs' => $key]),
                'current' => $key === $activeTab,
            ];
        }

        return $this->render('@Procurement/admin/company/detail.html.twig', [
            'vendor' => $vendor,
            'paymentTerms' => $this->paymentTerms(),
            'statuses' => Vendor::statuses(),
            'contactStatuses' => VendorContact::statuses(),
            'notes' => $noteRepo->forVendor((int) $vendor->getId()),
            'noteMaxLength' => VendorNote::MAX_LENGTH,
            // The customer record's own detail page shows its users/contacts as flat display ROWS
            // (companyToRow()-style arrays), not entities — see contactRowsForVendor() below, built
            // to the same shape.
            'contacts' => $this->contactRowsForVendor($vendor),
            // Country/province NAMES for the codes actually stored, resolved for display only. The
            // columns keep the codes — see VendorAddress on why this side is not widened to names.
            'regionNames' => $this->regionNames($vendor),
            // What we owe this vendor, taken from the report the AP Aging drill-down came from
            // rather than re-derived here; see openBalances().
            'openBalances' => $this->openBalances($vendor),
            'agingUrl' => $this->generateUrl('admin_bundle_procurement_bill_aging', ['filters' => ['vendor' => $vendor->getId()]]),
            'documentTabs' => $documentTabs,
            'documentColumns' => $tab['columns'],
            'documentLinkColumn' => $tab['link'],
            'documentStatusColumn' => $tab['status'],
            'documentRows' => $this->documentRows($activeTab, $vendor),
            'documentEmptyText' => $tab['empty'],
            'documentListUrl' => $tab['listRoute'] === null
                ? null
                : $this->generateUrl($tab['listRoute'], ['filters' => ['vendor' => $vendor->getId()]]),
            'documentListLabel' => $tab['listLabel'],
        ]);
    }

    /**
     * One row per vendor contact, in the shape
     * `App\Controller\Admin\AbstractAdminController::userRowsFromDatabase()` hands the customer's
     * Users card — name, email, status, and a fourth descriptive column. Company's fourth column is
     * account TYPE (a CustomerUser is a real login); a vendor contact never is one (#605), so there
     * is no type to show and 'jobTitle' takes that column instead — the nearest thing a contact
     * record actually has.
     *
     * @return list<array<string, string>>
     */
    private function contactRowsForVendor(Vendor $vendor): array
    {
        $rows = [];
        foreach ($vendor->getContacts() as $contact) {
            $rows[] = [
                'id' => (string) $contact->getId(),
                'name' => $contact->getName() ?: '-',
                'email' => $contact->getEmail() ?? '-',
                'status' => $contact->getStatus(),
                'jobTitle' => $contact->getJobTitle() ?? '-',
                'primary' => $contact->isPrimary() ? 'Yes' : '',
            ];
        }

        return $rows;
    }

    /**
     * The address row `?address=` names, looked for among THIS vendor's addresses.
     *
     * Membership rather than existence, and {@see RequestedParent::notOneOf()} rather than
     * `unresolved()`: an address id that belongs to another vendor is a real row, so "there is no
     * such address" would be a confident lie on the case that brings somebody here — a link copied
     * from the wrong vendor's page. One true sentence covers both that and the typo.
     *
     * @return RequestedParent<VendorAddress>
     */
    private function requestedAddress(Request $request, Vendor $vendor): RequestedParent
    {
        $requestedId = RequestedParent::requestedIdIn($request->query->all(), 'address');

        if ($requestedId === null) {
            return RequestedParent::none('address');
        }

        foreach ($vendor->getAddresses() as $candidate) {
            if ((string) $candidate->getId() === $requestedId) {
                return RequestedParent::of($candidate, $requestedId, 'address');
            }
        }

        return RequestedParent::notOneOf($requestedId, 'address', "one of this vendor's addresses");
    }

    /**
     * What we owe this vendor right now, per currency, straight from `ApAgingReport`.
     *
     * **The figure is not computed here, and that is the whole point.** AP Aging is what links to
     * this page — the vendor name in every aging row is a link and it lands here — so the balance
     * this page prints has to be the same number the row the admin just clicked was showing. Calling
     * the report is how that is guaranteed; summing `VendorBill::getBalance()` in this method would
     * be a second definition of "owed", free to disagree with the first the day either changed.
     *
     * The report's rules, stated there and inherited here rather than restated: Draft and Void bills
     * are excluded (`VendorBillStatus::counts()`), a settled bill drops out at zero, an OVERPAID one
     * is kept with a negative balance because money sitting with a vendor is information, and rows
     * are grouped by currency and never converted.
     *
     * Item 40 asks for a `payable` method ON the bill so the rule stops being re-derived per report.
     * **That method does not exist yet** — `VendorBill` has `getBalance()` and the enum has
     * `counts()` / `isPayable()`, and nothing joins them. This page therefore reads the existing
     * report rather than inlining a third copy of the rule; when item 40 lands, `ApAgingReport`
     * adopts it and this page follows for free because it never held a copy.
     *
     * @return list<array{currency: string, total: string}>
     */
    private function openBalances(Vendor $vendor): array
    {
        $report = $this->apAging->build(new \DateTimeImmutable(), (int) $vendor->getId());

        $balances = [];
        foreach ($report['totals'] as $currency => $figures) {
            $balances[] = ['currency' => (string) $currency, 'total' => $figures['total']];
        }

        return $balances;
    }


    /**
     * The rows for ONE tab — only the active one is queried.
     *
     * Loading all four sets to render one quadruples the page's cost for rows nobody asked to see,
     * which is the same call `CompanyController::detail()` makes for the same reason.
     *
     * Each row is `{href, cells}` and the template prints `cells` in the order the tab's `columns`
     * declare. Every value is already a display string by the time it leaves here: money is
     * formatted with its currency, a missing value is an em dash, and the template decides nothing
     * except how to lay it out — the same contract `vendorToRow()` above keeps for the list screen.
     *
     * @return list<array{href: ?string, cells: array<string, string>}>
     */
    private function documentRows(string $tab, Vendor $vendor): array
    {
        return match ($tab) {
            'purchase-orders' => $this->purchaseOrderRows($vendor),
            'bills' => $this->billRows($vendor),
            'returns' => $this->returnRows($vendor),
            'payments' => $this->paymentRows($vendor),
            // Unreachable: detail() has already replaced any key that is not in DOCUMENT_TABS with
            // the default. Loud rather than an empty tab, because an empty tab is what a vendor with
            // no documents looks like and the two must not be confusable (#624).
            default => throw new \LogicException(sprintf('No row builder for the %s tab.', $tab)),
        };
    }

    /**
     * Money as it is written everywhere else on the buy side: the currency code, then two decimals.
     *
     * Two decimals ALWAYS, including on a whole amount, and the currency ALWAYS, because a purchase
     * document is read in the currency it was raised in and nothing here converts between them —
     * '1,142.22' with no code beside it is not money on a screen that can hold both CAD and USD.
     */
    private static function money(string $currency, string $amount): string
    {
        return $currency . ' ' . number_format((float) $amount, 2);
    }

    /** @return list<array{href: ?string, cells: array<string, string>}> */
    private function purchaseOrderRows(Vendor $vendor): array
    {
        /** @var list<PurchaseOrder> $orders */
        $orders = $this->em->getRepository(PurchaseOrder::class)
            ->findBy(['vendor' => $vendor], ['id' => 'DESC']);

        return array_map(fn (PurchaseOrder $order): array => [
            'href' => $this->generateUrl('admin_bundle_procurement_purchase_order', ['id' => $order->getId()]),
            'cells' => [
                'Date' => $order->getDocumentDate(),
                'Number' => $order->getDocumentNumber(),
                'Items' => (string) $order->getLines()->count(),
                'Total' => self::money($order->getCurrency(), $order->getTotal()),
                'Status' => $order->getStatus(),
            ],
        ], $orders);
    }

    /**
     * Bills, with the balance the bill screen and AP Aging both print.
     *
     * `getBalance()` is the document's own arithmetic — total less the payments recorded against it
     * — and item 40 settled that it is CORRECT as arithmetic and must not be zeroed or hidden: a
     * void bill with no payments genuinely has a balance of its full amount. What a void bill is not
     * is PAYABLE, and that is a different question answered in the balance panel above by the AP
     * report, not by quietly printing a different number in this column than the bill itself shows.
     * The Status column beside it is what says which of the two a row is.
     *
     * @return list<array{href: ?string, cells: array<string, string>}>
     */
    private function billRows(Vendor $vendor): array
    {
        /** @var list<VendorBill> $bills */
        $bills = $this->em->getRepository(VendorBill::class)
            ->findBy(['vendor' => $vendor], ['id' => 'DESC']);

        return array_map(fn (VendorBill $bill): array => [
            'href' => $this->generateUrl('admin_bundle_procurement_bill', ['id' => $bill->getId()]),
            'cells' => [
                'Date' => $bill->getDocumentDate(),
                'Number' => $bill->getDocumentNumber(),
                'Due' => $bill->getDueDate() ?? '—',
                'Total' => self::money($bill->getCurrency(), $bill->getTotal()),
                'Balance due' => self::money($bill->getCurrency(), $bill->getBalance()),
                'Status' => $bill->getStatus(),
            ],
        ], $bills);
    }

    /**
     * Returns, with units and no money.
     *
     * A vendor return states what left the building; `DebitMemo` is what states the money, exactly
     * as `CreditMemo` is `SalesReturn`'s counterpart on the sell side. A Total column here would
     * have to print a figure the document does not hold.
     *
     * @return list<array{href: ?string, cells: array<string, string>}>
     */
    private function returnRows(Vendor $vendor): array
    {
        /** @var list<VendorReturn> $returns */
        $returns = $this->em->getRepository(VendorReturn::class)
            ->findBy(['vendor' => $vendor], ['id' => 'DESC']);

        return array_map(fn (VendorReturn $return): array => [
            'href' => $this->generateUrl('admin_bundle_procurement_vendor_return', ['id' => $return->getId()]),
            'cells' => [
                'Requested' => $return->getRequestedAt()->format('Y-m-d'),
                'Number' => $return->getDocumentNumber(),
                'Units' => (new DisplayNumber())->qty($return->totalUnits()),
                'Status' => $return->getStatus()->value,
            ],
        ], $returns);
    }

    /**
     * Every payment claim made against this vendor's bills.
     *
     * `vendor_bill_payment_application` hangs off the bill and not off the vendor, so this is a join
     * rather than a findBy — which is also why the answer is worth having on this page at all: "what
     * have we paid Steelhead" is otherwise four bill screens opened one after another.
     *
     * The APPLICATION and not the underlying payment (#708: a payment can settle several bills, so
     * one payment made to this vendor can be more than one row here — one per bill it touched, which
     * is exactly the granularity "what has this vendor been paid against each bill" wants). The row
     * names the BILL rather than the payment, because a payment has no identity a person uses: the
     * link goes to that bill's payments screen, where the row can actually be corrected.
     *
     * @return list<array{href: ?string, cells: array<string, string>}>
     */
    private function paymentRows(Vendor $vendor): array
    {
        /** @var list<VendorBillPaymentApplication> $applications */
        $applications = $this->em->createQueryBuilder()
            ->select('a', 'b')
            ->from(VendorBillPaymentApplication::class, 'a')
            ->join('a.bill', 'b')
            ->where('b.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->orderBy('a.appliedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(function (VendorBillPaymentApplication $application): array {
            $bill = $application->getBill();

            return [
                'href' => $this->generateUrl('admin_bundle_procurement_bill_payments', ['id' => $bill->getId()]),
                'cells' => [
                    'Paid' => $application->getAppliedAt()->format('Y-m-d'),
                    'Bill' => $bill->getDocumentNumber(),
                    'Method' => $application->getMethod(),
                    'Amount' => self::money($bill->getCurrency(), $application->getAmount()),
                    'Recorded by' => $application->getRecordedBy()?->getUserIdentifier() ?? '—',
                ],
            ];
        }, $applications);
    }

    /**
     * Display names for each address's stored codes, keyed by `vendor_address.id`.
     *
     * Resolution for the screen and nothing else: the row still holds 'BC' and 'CA' afterwards. An
     * unrecognised code (a country the reference data has not been seeded with) resolves to null and
     * the screen shows the code alone, which is more honest than inventing a name for it.
     *
     * @return array<int, array{country: ?string, province: ?string}>
     */
    private function regionNames(Vendor $vendor): array
    {
        $names = [];
        foreach ($vendor->getAddresses() as $address) {
            $addressId = $address->getId();
            if ($addressId === null) {
                continue;
            }

            $country = $address->getCountry();
            $province = (string) $address->getProvince();

            $names[$addressId] = [
                'country' => $this->region->countryName($country),
                'province' => $province === '' ? null : $this->region->provinceName($country, $province),
            ];
        }

        return $names;
    }

    #[Route('/save', name: 'admin_bundle_procurement_vendor_save', methods: ['POST'])]
    public function save(Request $request, CustomFieldRenderer $customFieldRenderer): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $vendor = $id > 0 ? $this->em->find(Vendor::class, $id) : null;

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'A vendor needs a name. It is what every document raised against them will say.');

            // Back to the record whose form was submitted, so the rest of what was typed is still
            // on screen. Only a save with no vendor behind it falls back to the list — which since
            // #660 is a create, and creates go to /vendors/new and come back there themselves.
            return $vendor instanceof Vendor
                ? $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()])
                : $this->redirectToRoute('admin_bundle_procurement_vendors');
        }

        if (!$vendor instanceof Vendor) {
            $vendor = new Vendor();
            $this->em->persist($vendor);
        }

        $this->applyVendorRequest($vendor, $request);

        $this->em->flush();
        // After flush, same reason as create(): a brand-new $vendor has no id until the INSERT runs.
        $customFieldRenderer->saveFromRequest('vendor', $vendor, $request);
        $this->em->flush();

        $this->addFlash('success', sprintf('%s saved. Documents already raised keep the name they were raised under.', $vendor->getName()));

        return $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);
    }

    /**
     * Every editable column on the vendor record, written from a posted form.
     *
     * ONE copy, called by the create page and by the detail screen's Save (#660). The two screens
     * render the same fields from `_vendor_fields.html.twig`; if they did not also write them
     * through the same method, a field added to the partial would be stored by one screen and
     * silently dropped by the other.
     */
    private function applyVendorRequest(Vendor $vendor, Request $request): void
    {
        $vendor
            ->setName(trim((string) $request->request->get('name', '')))
            ->setAccountNumber($this->nullable((string) $request->request->get('account_number', '')))
            ->setEmail($this->nullable((string) $request->request->get('email', '')))
            ->setPhone($this->nullable((string) $request->request->get('phone', '')))
            ->setCurrency((string) $request->request->get('currency', 'CAD'))
            ->setStatus((string) $request->request->get('status', Vendor::STATUS_ACTIVE));

        $this->applyPaymentTerm($vendor, $request);
    }

    /**
     * Writes `vendor.payment_term_id` and `vendor.payment_term` together, from whichever control the
     * screen rendered.
     *
     * Both columns, deliberately — `company` has the id and every purchase order snapshots the name,
     * so dropping either would break one of the two readers. The id is authoritative when the
     * dropdown is available, and the name is derived from it so the two cannot disagree; when the
     * `payment_term` table does not exist the screen falls back to a free-text box and only the name
     * is written, exactly as it always was.
     *
     * Only ever from a form somebody submitted. NOTHING here scans existing rows to guess an id for
     * a term that was typed as free text — that mapping is a human judgement per vendor, and the
     * SQL for it belongs in a report, unrun.
     */
    private function applyPaymentTerm(Vendor $vendor, Request $request): void
    {
        $terms = $this->paymentTerms();

        if ($terms === []) {
            $vendor->setPaymentTerm($this->nullable((string) $request->request->get('payment_term', '')));

            return;
        }

        $chosen = $request->request->getInt('payment_term_id', 0);

        foreach ($terms as $term) {
            if ($term['id'] === $chosen) {
                $vendor->setPaymentTermId($term['id'])->setPaymentTerm($term['name']);

                return;
            }
        }

        // "—" chosen, or an id that is not on the list. Both mean no term, and clearing both columns
        // together keeps them from disagreeing.
        $vendor->setPaymentTermId(null)->setPaymentTerm(null);
    }

    /**
     * The one-click status toggle the titlebar carries — the counterpart of
     * `CompanyController::deactivate()`/`reactivate()`. No customer users to cascade a status
     * change onto here: a vendor contact is never a login (#605), so this is only ever the one
     * column.
     */
    #[Route('/{id}/deactivate', name: 'admin_bundle_procurement_vendor_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivateStatus(int $id): JsonResponse
    {
        $this->denyIfInactive();

        $vendor = $this->em->find(Vendor::class, $id);
        if (!$vendor instanceof Vendor) {
            return new JsonResponse(['ok' => false, 'message' => 'Vendor could not be found.'], Response::HTTP_NOT_FOUND);
        }

        if ($vendor->getStatus() === Vendor::STATUS_INACTIVE) {
            return new JsonResponse(['ok' => true, 'message' => sprintf('%s is already inactive.', $vendor->getName())]);
        }

        $name = $vendor->getName();
        $vendor->setStatus(Vendor::STATUS_INACTIVE);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('%s was deactivated successfully.', $name)]);
    }

    #[Route('/{id}/activate', name: 'admin_bundle_procurement_vendor_activate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activateStatus(int $id): JsonResponse
    {
        $this->denyIfInactive();

        $vendor = $this->em->find(Vendor::class, $id);
        if (!$vendor instanceof Vendor) {
            return new JsonResponse(['ok' => false, 'message' => 'Vendor could not be found.'], Response::HTTP_NOT_FOUND);
        }

        if ($vendor->getStatus() === Vendor::STATUS_ACTIVE) {
            return new JsonResponse(['ok' => true, 'message' => sprintf('%s is already active.', $vendor->getName())]);
        }

        $name = $vendor->getName();
        $vendor->setStatus(Vendor::STATUS_ACTIVE);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'message' => sprintf('%s was activated successfully.', $name)]);
    }

    // ------------------------------------------------------------------- addresses (#605, #606)

    /**
     * The card-based Address Book page — the counterpart of `CompanyController::addressBook()`.
     *
     * Company shows two default-purpose cards (Billing/Shipping); a vendor has four (Order To/
     * Ship From/Remit To/Return To), each falling back to the vendor's own default address exactly
     * as `Vendor::addressFor()` already does for every document that freezes one of these onto
     * itself — the same rule, read here instead of re-derived.
     *
     * Addresses are passed as ENTITIES, not row arrays: this bundle's address templates already
     * read VendorAddress accessors directly (the create/edit form does the same), so there is no
     * second row-shape to keep in sync with the entity the way `addressRowsForCompany()` must.
     */
    #[Route('/{id}/address-book', name: 'admin_bundle_procurement_vendor_address_book', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function addressBook(int $id): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);

        return $this->render('@Procurement/vendor_address_book.html.twig', [
            'vendor' => $vendor,
            'addresses' => $vendor->getAddresses(),
            'orderTo' => $vendor->getOrderToAddress(),
            'shipFrom' => $vendor->getShipFromAddress(),
            'remitTo' => $vendor->getRemitToAddress(),
            'returnTo' => $vendor->getReturnToAddress(),
        ]);
    }

    /**
     * The blank or prefilled Address page — the counterpart of `admin_company_address_create` /
     * `admin_company_address_update`. One view for both, the way the old detail-page `?address=`
     * link worked: present when the id names one of this vendor's own addresses, blank otherwise.
     * Posts through the existing `saveAddress()` below either way — nothing about how an address is
     * written changes here, only where the form to do it lives.
     */
    #[Route('/{id}/address', name: 'admin_bundle_procurement_vendor_address_form', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function addressForm(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $requested = $this->requestedAddress($request, $vendor);

        return $this->render('@Procurement/vendor_address_form.html.twig', [
            'vendor' => $vendor,
            'address' => $requested->entity(),
            'badParents' => $this->unresolvedParents($requested),
        ]);
    }

    #[Route('/{id}/address', name: 'admin_bundle_procurement_vendor_address_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveAddress(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $addressId = $request->request->getInt('address_id', 0);
        $address = $addressId > 0 ? $this->em->find(VendorAddress::class, $addressId) : null;

        if ($address instanceof VendorAddress && $address->getVendor()->getId() !== $vendor->getId()) {
            $this->addFlash('error', 'That address belongs to a different vendor.');

            return $redirect;
        }

        $line1 = trim((string) $request->request->get('address_line_1', ''));
        $city = trim((string) $request->request->get('city', ''));
        if ($line1 === '' || $city === '') {
            $this->addFlash('error', 'An address needs at least a street and a city — it is printed on the purchase order.');

            return $redirect;
        }

        // The country and the province are checked BEFORE anything is built or persisted.
        //
        // This screen had no check at all. `normalizedProvince()` below resolves through `Region`
        // and, when that resolves nothing, KEEPS the typed value clipped to the column width — so
        // `province=CA` on a `country=CA` address stored the two letters 'CA', and the screen then
        // printed a Canadian address whose province is a US state. Nothing refused it and nothing
        // said so. The boxes are dropdowns now, which stops a person reaching that state, and this
        // stops anything else reaching it: a select constrains a browser and nothing else.
        //
        // Read INSIDE the submitted country, against the same `Region` rows the dropdowns are built
        // from. A country-blind check cannot tell 'CA'-the-country-code from California.
        //
        // Both fields stay OPTIONAL, because they are optional on this screen and this change does
        // not widen what is required — a vendor may be a postal box somewhere neither list covers.
        // Blank is accepted; a value that is not in the list is not.
        $rawCountry = trim((string) $request->request->get('country', 'CA'));
        $rawProvince = trim((string) $request->request->get('province', ''));
        $resolvedCountry = $rawCountry === '' ? null : $this->region->normalizeCountry($rawCountry);

        if ($rawCountry !== '' && ($resolvedCountry === null || !$this->region->isValidCountry($resolvedCountry))) {
            $this->addFlash('error', sprintf(
                '"%s" is not a country this application knows, so the address was not saved. Choose one from the list.',
                $rawCountry,
            ));

            return $redirect;
        }

        if ($rawProvince !== '' && !$this->region->isValidProvince((string) $resolvedCountry, $rawProvince)) {
            $this->addFlash('error', sprintf(
                '"%s" is not a province or state of %s, so the address was not saved. Choose one from the list — '
                    . '"CA" is California, not Canada, and an address carrying it computes the wrong tax in silence.',
                $rawProvince,
                (string) $resolvedCountry,
            ));

            return $redirect;
        }

        $isNew = !$address instanceof VendorAddress;
        if ($isNew) {
            $address = new VendorAddress();
            $vendor->addAddress($address);
            $this->em->persist($address);
        }

        // Validated the same way VendorContact's own email is (see addContact() below): checked
        // before anything is built, nothing saved on a refusal, blank accepted since neither field
        // is required — a vendor may have no contact email on file at all.
        $emailPrimary = $this->nullable((string) $request->request->get('email_primary', ''));
        $emailSecondary = $this->nullable((string) $request->request->get('email_secondary', ''));

        if ($emailPrimary !== null && filter_var($emailPrimary, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', sprintf('"%s" is not an email address. Nothing was saved.', $emailPrimary));

            return $redirect;
        }

        if ($emailSecondary !== null && filter_var($emailSecondary, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', sprintf('"%s" is not an email address. Nothing was saved.', $emailSecondary));

            return $redirect;
        }

        $country = $this->normalizedCountry($rawCountry);

        $address
            ->setLabel($this->nullable((string) $request->request->get('label', '')))
            ->setAddressLine1($line1)
            ->setAddressLine2($this->nullable((string) $request->request->get('address_line_2', '')))
            ->setCity($city)
            ->setCountry($country)
            ->setProvince($this->normalizedProvince($country, $rawProvince))
            ->setPostalCode($this->nullable((string) $request->request->get('postal_code', '')))
            // Who to speak to at this location (#605) — the block `company_address` has always had
            // and this table never did.
            ->setFirstName($this->nullable((string) $request->request->get('first_name', '')))
            ->setLastName($this->nullable((string) $request->request->get('last_name', '')))
            ->setCompanyName($this->nullable((string) $request->request->get('company_name', '')))
            ->setEmailPrimary($emailPrimary)
            ->setEmailSecondary($emailSecondary)
            ->setPhone($this->nullable((string) $request->request->get('phone_number', '')))
            ->setFax($this->nullable((string) $request->request->get('fax', '')))
            ->setDeliveryInstructions($this->nullable((string) $request->request->get('delivery_instructions', '')))
            ->setIsDefault($request->request->getBoolean('is_default'))
            ->setIsOrderTo($request->request->getBoolean('is_order_to'))
            ->setIsShipFrom($request->request->getBoolean('is_ship_from'))
            ->setIsRemitTo($request->request->getBoolean('is_remit_to'))
            ->setIsReturnTo($request->request->getBoolean('is_return_to'));

        // At most one row per purpose, and at most one default. Enforced here rather than by a
        // constraint for the reason the default already was: "which address does a new PO use" has
        // to have one answer, and two rows claiming it would make the answer depend on row order.
        //
        // One row may still wear SEVERAL purposes — that is the common case for a small supplier,
        // and why these are four flags rather than one enum.
        foreach ([
            'isDefault' => [$address->isDefault(), 'setIsDefault'],
            'isOrderTo' => [$address->isOrderTo(), 'setIsOrderTo'],
            'isShipFrom' => [$address->isShipFrom(), 'setIsShipFrom'],
            'isRemitTo' => [$address->isRemitTo(), 'setIsRemitTo'],
            'isReturnTo' => [$address->isReturnTo(), 'setIsReturnTo'],
        ] as [$claimed, $clear]) {
            if ($claimed !== true) {
                continue;
            }

            foreach ($vendor->getAddresses() as $other) {
                if ($other !== $address) {
                    $other->{$clear}(false);
                }
            }
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Address %s. Purchase orders, receipts and bills already raised keep the address frozen onto them.',
            $isNew ? 'added' : 'saved',
        ));

        return $redirect;
    }

    /**
     * Removes one address row.
     *
     * Nothing is stranded by this: every document that ever printed this address carries its own
     * frozen copy in `purchase_order.vendor_address`, `goods_receipt.ship_from_address` or
     * `vendor_bill.remit_to_address`, so a deleted row changes what the NEXT document says and
     * nothing about any document already raised.
     */
    #[Route('/{id}/address/delete', name: 'admin_bundle_procurement_vendor_address_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAddress(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $address = $this->em->find(VendorAddress::class, $request->request->getInt('address_id', 0));

        if (!$address instanceof VendorAddress || $address->getVendor()->getId() !== $vendor->getId()) {
            $this->addFlash('error', 'That address is not on this vendor. Nothing was deleted.');

            return $redirect;
        }

        $vendor->removeAddress($address);
        $this->em->flush();

        $this->addFlash('success', 'Address removed. Documents already raised keep their frozen copy of it.');

        return $redirect;
    }

    // -------------------------------------------------------------------- contacts (#605)

    /**
     * The blank row at the bottom of the Contacts table, submitted.
     *
     * Comes back to the detail page with the contact as a row and a fresh blank row under it — the
     * submit-to-add-row loop, not a JavaScript clone. Nothing on this path needs scripting.
     */
    /**
     * The blank Add Contact page — the counterpart of `admin_company_user_create`.
     *
     * Customer's Users card has no inline row at all: a login account is created on its own page
     * and this screen has never shown edit/delete controls for one. A vendor contact has no login
     * to create (#605), but the same "read-only list, Add goes to its own page" shape now applies.
     */
    #[Route('/{id}/contacts', name: 'admin_bundle_procurement_vendor_contact_add', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function addContact(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        if (!$request->isMethod('POST')) {
            return $this->render('@Procurement/vendor_contact_form.html.twig', [
                'vendor' => $vendor,
                'contactStatuses' => VendorContact::statuses(),
            ]);
        }

        $contact = new VendorContact();
        // Attached to the vendor BEFORE validation because applyContact() needs the vendor to clear
        // any other primary, and persisted only AFTER it — a rejected row is never persisted and so
        // never becomes one.
        $vendor->addContact($contact);

        if (!$this->applyContact($contact, $request)) {
            $vendor->removeContact($contact);

            return $redirect;
        }

        $this->em->persist($contact);
        $this->em->flush();

        $this->addFlash('success', sprintf('%s added to %s.', $contact->getName(), $vendor->getName()));

        return $redirect;
    }

    /** One contact's own row, saved. `contact_id` is the hidden field inside that row's form. */
    #[Route('/{id}/contacts/update', name: 'admin_bundle_procurement_vendor_contact_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateContact(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $contact = $this->contactOnVendor($vendor, $request->request->getInt('contact_id', 0));
        if (!$contact instanceof VendorContact) {
            $this->addFlash('error', 'That contact is not on this vendor. Nothing was saved.');

            return $redirect;
        }

        if (!$this->applyContact($contact, $request)) {
            return $redirect;
        }

        $this->em->flush();

        $this->addFlash('success', sprintf('%s saved.', $contact->getName()));

        return $redirect;
    }

    #[Route('/{id}/contacts/delete', name: 'admin_bundle_procurement_vendor_contact_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteContact(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $contact = $this->contactOnVendor($vendor, $request->request->getInt('contact_id', 0));
        if (!$contact instanceof VendorContact) {
            $this->addFlash('error', 'That contact is not on this vendor. Nothing was deleted.');

            return $redirect;
        }

        $name = $contact->getName();
        $vendor->removeContact($contact);
        $this->em->flush();

        $this->addFlash('success', sprintf('%s removed. Documents already raised are unaffected — none of them join to a contact.', $name));

        return $redirect;
    }

    /**
     * Fills a contact from a posted row, or flashes why it could not and returns false.
     *
     * Note what is NOT read here: no password, no roles, no API flag. There is no field for any of
     * them on the form and no column for any of them on the table (VendorContact), and this method
     * is the only place a contact is ever written.
     */
    private function applyContact(VendorContact $contact, Request $request): bool
    {
        $first = $this->nullable((string) $request->request->get('first_name', ''));
        $last = $this->nullable((string) $request->request->get('last_name', ''));
        $email = $this->nullable((string) $request->request->get('email', ''));
        $phone = $this->nullable((string) $request->request->get('phone', ''));
        $jobTitle = $this->nullable((string) $request->request->get('job_title', ''));

        // Something has to identify the person. An orders desk with only an address and no name is
        // a real and common contact, so any ONE of these is enough — but a row with none of them is
        // a blank row somebody submitted by accident, and storing it would put an empty line in
        // every picker.
        if ($first === null && $last === null && $email === null && $phone === null) {
            $this->addFlash('error', 'A contact needs at least a name, an email or a phone number — otherwise there is nobody to reach.');

            return false;
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', sprintf('"%s" is not an email address. Nothing was saved.', $email));

            return false;
        }

        $isPrimary = $request->request->getBoolean('is_primary');

        $contact
            ->setFirstName($first)
            ->setLastName($last)
            ->setEmail($email)
            ->setPhone($phone)
            ->setJobTitle($jobTitle)
            ->setStatus((string) $request->request->get('status', VendorContact::STATUS_ACTIVE))
            ->setIsPrimary($isPrimary);

        // One primary, for the reason one default address is one: it decides who a purchase order
        // is emailed to when `vendor.email` is empty, and that has to have one answer.
        if ($isPrimary) {
            foreach ($contact->getVendor()->getContacts() as $other) {
                if ($other !== $contact) {
                    $other->setIsPrimary(false);
                }
            }
        }

        return true;
    }

    private function contactOnVendor(Vendor $vendor, int $contactId): ?VendorContact
    {
        if ($contactId <= 0) {
            return null;
        }

        $contact = $this->em->find(VendorContact::class, $contactId);

        return $contact instanceof VendorContact && $contact->getVendor()->getId() === $vendor->getId() ? $contact : null;
    }

    // ----------------------------------------------------------------------- notes (#605)

    #[Route('/{id}/notes', name: 'admin_bundle_procurement_vendor_note_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addNote(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $text = trim((string) $request->request->get('note', ''));
        if ($text === '') {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => 'Note text is required.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'An empty note says nothing. Nothing was saved.');

            return $redirect;
        }

        // Bounded here as well as by maxlength, which a browser with scripting off still honours but
        // a direct POST does not. Truncated rather than refused: the text is what somebody typed and
        // losing all of it to protect a column limit helps nobody.
        if (mb_strlen($text) > VendorNote::MAX_LENGTH) {
            $text = mb_substr($text, 0, VendorNote::MAX_LENGTH);
        }

        $note = (new VendorNote())
            ->setText($text)
            ->setUserName($this->actor()->displayName);
        $vendor->addNoteEntry($note);
        $this->em->persist($note);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => true,
                'id' => $note->getId(),
                'date' => $this->noteDate($note),
                'author' => $note->getUserName(),
                'note' => $note->getText(),
                'message' => 'Note was added successfully.',
            ]);
        }

        $this->addFlash('success', 'Note added. Only admins see it; the vendor never does.');

        return $redirect;
    }

    /**
     * Corrects the wording of a note.
     *
     * `created_at` is deliberately untouched, exactly as `CompanyController::updateNote()` leaves
     * it: an edit fixes what a note says, it does not make it a new note.
     */
    #[Route('/{id}/notes/update', name: 'admin_bundle_procurement_vendor_note_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateNote(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $redirect = $this->redirectToRoute('admin_bundle_procurement_vendor', ['id' => $vendor->getId()]);

        $note = $this->noteOnVendor($vendor, $request->request->getInt('note_id', 0));
        if (!$note instanceof VendorNote) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => 'That note is not on this vendor.'], Response::HTTP_NOT_FOUND);
            }

            $this->addFlash('error', 'That note is not on this vendor. Nothing was saved.');

            return $redirect;
        }

        $text = trim((string) $request->request->get('note', ''));
        if ($text === '') {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => 'Note text is required.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'A note cannot be emptied. Delete it instead.');

            return $redirect;
        }

        $note->setText(mb_strlen($text) > VendorNote::MAX_LENGTH ? mb_substr($text, 0, VendorNote::MAX_LENGTH) : $text);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => true,
                'id' => $note->getId(),
                'date' => $this->noteDate($note),
                'author' => $note->getUserName(),
                'note' => $note->getText(),
                'message' => 'Note was updated successfully.',
            ]);
        }

        $this->addFlash('success', 'Note saved. Its date and author are unchanged — an edit corrects a note, it does not replace it.');

        return $redirect;
    }

    /**
     * Always JSON, matching `CompanyController::deleteNote()`: the delete control this route
     * serves has no non-JS fallback in the markup, only a `data-url` a click handler posts to.
     */
    #[Route('/{id}/notes/delete', name: 'admin_bundle_procurement_vendor_note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteNote(int $id, Request $request): JsonResponse
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($id);
        $note = $this->noteOnVendor($vendor, $request->request->getInt('note_id', $request->request->getInt('id', 0)));

        if (!$note instanceof VendorNote) {
            return new JsonResponse(['ok' => false, 'message' => 'That note is not on this vendor.'], Response::HTTP_NOT_FOUND);
        }

        $vendor->removeNoteEntry($note);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'message' => 'Note was deleted successfully.']);
    }

    /** The one place a vendor note's timestamp becomes display text, so the page and the JSON agree. */
    private function noteDate(VendorNote $note): string
    {
        return $note->getCreatedAt()->format('M j, Y g:i A');
    }

    /**
     * A note addressed BY ID and checked against this vendor, never by position in a list.
     *
     * That is the whole bug class #358 removed from the sell side: `company.notes` used to be one
     * packed string addressed by array index, so a whitespace-only line shifted every position after
     * it and deleting the entry an admin clicked removed a different one.
     */
    private function noteOnVendor(Vendor $vendor, int $noteId): ?VendorNote
    {
        if ($noteId <= 0) {
            return null;
        }

        $note = $this->em->find(VendorNote::class, $noteId);

        return $note instanceof VendorNote && $note->getVendor()->getId() === $vendor->getId() ? $note : null;
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * A country CODE, normalised through the reference data when it is recognised.
     *
     * When it is not recognised the typed value is kept rather than dropped: refusing a country
     * because `geo_country` has not been seeded would make this screen depend on a table that a
     * fresh instance may not have populated yet, and losing what somebody typed is worse than
     * storing a code nothing resolves.
     */
    private function normalizedCountry(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return 'CA';
        }

        // Clipped to the column's own width when the reference data cannot resolve it. The form's
        // maxlength already says 2, so this only ever fires on a hand-crafted POST — and clipping
        // keeps the stored value inside the width the column declares rather than relying on
        // SQLite's willingness to ignore it.
        return $this->region->normalizeCountry($raw) ?? mb_substr($raw, 0, 2);
    }

    private function normalizedProvince(string $country, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        return $this->region->normalizeProvince($country, $raw) ?? mb_substr(strtoupper($raw), 0, 8);
    }

    /**
     * The admin-managed `payment_term` table, read directly, as id + name.
     *
     * Raw SQL because that table is deliberately outside the ORM — it is one of the tables
     * RawSqlTablesSurviveTheChainTest protects — and mapping an entity onto it here would put this
     * bundle in charge of a table core's own screens already own.
     *
     * Returns the id as well as the name since #605, because `vendor.payment_term_id` now exists and
     * matches `company.payment_term_id`; core's own AbstractAdminController::paymentTermRowsFromDatabase()
     * reads the same two columns for the company screen.
     *
     * @return list<array{id: int, name: string}>
     */
    private function paymentTerms(): array
    {
        $connection = $this->em->getConnection();

        // Table-exists guard first, exactly as AbstractAdminController::paymentTermRowsFromDatabase()
        // does. A fresh instance that has not created the table yet gets a working vendor screen
        // with a free-text term rather than a 500 on a dropdown.
        if (!$connection->createSchemaManager()->tablesExist(['payment_term'])) {
            return [];
        }

        /** @var list<array{id: mixed, name: mixed}> $rows */
        $rows = $connection->fetchAllAssociative(
            "SELECT id, name FROM payment_term WHERE status = 'Active' ORDER BY sort_order ASC, id ASC"
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows,
        );
    }
}
