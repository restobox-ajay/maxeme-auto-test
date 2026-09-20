<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The FRAME the three sell-side EDIT screens sit in — `admin/_base/commercial_document_edit.html.twig`.
 *
 * The order form, the quote form and the invoice create screen were three hand-built pages that
 * happened to look alike. Each carried its own copy of the same hero panel, the same workspace
 * card, the same line-items section, the same lines footer and the same customer chooser, and each
 * time one was touched the other two drifted a little further — one of the three quietly growing a
 * wrapper the others never had, or losing one. Componentising the CONTENTS fixed none of that,
 * because the contents were never where they disagreed.
 *
 * What is pinned here is the FRAME: which regions a sell-side edit screen has, and in what order.
 * That order is the sales order's, which is the standard the three are held to.
 *
 * ## How these are written (#624, #627)
 *
 * Conducted: every fixture is built here and the real screens are driven. Nothing asserts a bare
 * word or a bare number — the frame is read as an ORDERED LIST of elements, by tag and class,
 * compared in full, so a region that moved, vanished or appeared twice fails on the sequence
 * rather than on a `see()` that a stray match elsewhere on the page would satisfy.
 *
 * Every absence is paired with a positive control on the SAME selector: "the edit screen has no
 * action row above its grid" is asserted beside "the create screen has exactly one", using one
 * XPath, so a selector that had stopped matching anything could not pass as the finding.
 *
 * The last two tests are the ones that matter most. A frame refactor that changes one field name,
 * or reformats one figure on its way into a box, saves a different number with a green flash and
 * no error at all. So the form is re-posted EXACTLY as the page rendered it — every control the
 * browser would have submitted, read off the page, including the hidden `*_rendered` twins
 * `LineDenomination::boxUntouched()` compares byte for byte — and the stored figures are read back
 * out of their columns.
 */
final class SellSideEditBaseFrameCest
{
    private int $seq = 0;

    public function _before(FunctionalTester $I): void
    {
        ++$this->seq;
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('edit-base-frame-' . $this->seq . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ── the frame, screen by screen ──────────────────────────────────────────────────

    /**
     * The order form IS the frame — "sales order is golden standard". Every region it has is a
     * region of the frame, in this order.
     */
    public function theOrderFormRendersTheWholeFrameInOrder(FunctionalTester $I): void
    {
        $order = $this->order($I);
        $I->amOnPage('/admin/order/edit/' . $order->getId());

        $I->assertSame(
            [
                'div.admin-content-header',     // the admin layout's, not the frame's
                'style',                        // the no-JS rules
                'noscript',
                'section.panel',                // hero
                'nav.company-action-links',     // tab_nav
                'section.order-create-shell',   // the workspace shell
            ],
            $this->shapeOf($I, '//main[@id="main-content"]/*'),
            'the page regions of a sell-side edit screen, in the frame\'s order',
        );

        $I->assertSame(
            [
                'form.order-workspace-card',    // the save
                'section.table-card',           // after_form — Message
                'section.table-card',           // after_form — Activity Log
                'div.admin-modal-overlay',      // page_end — the status modal
                'template',                     // page_end — the row templates
                'template',
                'template',
                'template',
            ],
            $this->shapeOf($I, '//section[@class="order-create-shell"]/*'),
            'what the shell holds: the form, then what is not part of the save, then the modals'
            . ' and the <template>s',
        );

        $I->assertSame(
            [
                'input',                        // form_head — the CSRF field
                'input',                        // form_head — company_id
                'input.js-order-scroll-y',      // form_head — scroll_y
                'input',                        // form_head — order_id
                'input.js-order-version',       // form_head — version
                'div.order-workspace-grid',     // context
                'section.order-lines-section',  // the line-items section
            ],
            $this->shapeOf($I, '//form[@id="order-form"]/*'),
            'the workspace form: its hidden identity fields, the document\'s own context, and the'
            . ' line-items section',
        );

        $I->assertSame(
            [
                'div.order-lines-toolbar',      // lines_toolbar — #669: Add Product / Add Blank
                                                 //   Line plus the shipping/tax/fee bar, one row
                // lines_note is empty: the "at least one line" hint was never a real rule, only
                // a misleading paragraph — removed app-wide, zero line items is now allowed.
                'div.wide-table-wrap',          // lines_table — FIXED
                'div.order-lines-footer',       // lines_footer — FIXED, and the totals box is in it
                'div.order-bottom-actions',     // bottom_actions
            ],
            $this->shapeOf($I, '//form[@id="order-form"]/section[@class="order-lines-section"]/*'),
            'the line-items section is the frame\'s, in the frame\'s order: the table and the'
            . ' totals box are not something a layout may move',
        );
    }

    public function theQuoteFormSitsInTheSameFrame(FunctionalTester $I): void
    {
        $estimate = $this->estimate($I);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());

        $I->assertSame(
            ['div.admin-content-header', 'style', 'noscript', 'section.panel', 'nav.company-action-links', 'section.order-create-shell'],
            $this->shapeOf($I, '//main[@id="main-content"]/*'),
            'the quote\'s page regions are the order\'s page regions',
        );

        $I->assertSame(
            [
                'input',                        // the CSRF field
                'input.js-estimate-version',    // form_head — version
                'div.order-workspace-grid',     // context
                'section.order-lines-section',
            ],
            $this->shapeOf($I, '//form[@id="estimate-form"]/*'),
            'the quote\'s workspace form has the order\'s shape with the order\'s hidden fields it'
            . ' does not have — no more, and in the same order',
        );

        $I->assertSame(
            [
                'h2',                           // linesHeading
                'div.order-line-actions',       // lines_toolbar
                'div.quote-stock-notice',       // lines_note
                'div.wide-table-wrap',          // lines_table
                'div.order-lines-footer',       // lines_footer
                'div.order-bottom-actions',     // bottom_actions
            ],
            $this->shapeOf($I, '//form[@id="estimate-form"]/section[@class="order-lines-section"]/*'),
            'the line-items section, with the heading the order form does not ask for',
        );
    }

    public function theInvoiceCreateScreenSitsInTheSameFrame(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Edit Frame Invoice Co');
        $this->region($I, $company, 'Edit Frame Invoice Region');
        $this->product($I);
        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());

        $I->assertSame(
            ['div.admin-content-header', 'style', 'noscript', 'section.panel', 'section.order-create-shell'],
            $this->shapeOf($I, '//main[@id="main-content"]/*'),
            'the same page regions minus the one this screen does not have: an invoice being'
            . ' raised has no tab bar, because there is no document yet to have tabs for',
        );

        $I->assertSame(
            [
                'input',                        // the CSRF field
                'input',                        // form_head — company_id
                'div.order-workspace-actions',  // top_actions
                'div.order-workspace-grid',     // context — the delegated contextTemplate's own grid
                'section.order-lines-section',
            ],
            $this->shapeOf($I, '//form[@id="invoice-create-form"]/*'),
            'the delegated contextTemplate opens the workspace grid itself, and the frame does not'
            . ' flatten it into one of its own',
        );

        $I->assertSame(
            [
                'h2',                           // linesHeading
                'div.wide-table-wrap',          // lines_table
                'div.order-line-actions',       // lines_after_table — this screen's no-JS add row
                'div.order-lines-footer',       // lines_footer — the delegated chargeTemplate
                'p.lead',                       // footerNote
                'div.order-bottom-actions',     // bottom_actions
            ],
            $this->shapeOf($I, '//form[@id="invoice-create-form"]/section[@class="order-lines-section"]/*'),
            'this screen puts its add-line control UNDER the table where the other two put theirs'
            . ' above it. That disagreement predates the frame and is left exactly as it was.',
        );
    }

    /**
     * Step one is one page, rendered by the frame, and all three screens now open with it — the
     * order's chooser used to sit outside the workspace shell the other two put theirs in.
     */
    public function stepOneIsTheSameCustomerChooserOnAllThree(FunctionalTester $I): void
    {
        $this->company($I, 'Edit Frame Chooser Co');

        $shapes = [];
        $controls = [];
        foreach (['/admin/order/create', '/admin/estimate/create', '/admin/invoice/create'] as $url) {
            $I->amOnPage($url);
            $shapes[$url] = $this->shapeOf($I, '//section[@class="order-create-shell"]/*')
                + ['picker' => $this->shapeOf($I, '//form[@class="order-company-picker"]/*')];
            $controls[$url] = $this->postableControlNames($I->grabPageSource());
        }

        $I->assertSame(
            [
                '/admin/order/create' => ['section.order-company-card', 'picker' => ['input', 'label', 'div.order-company-select', 'button.button']],
                '/admin/estimate/create' => ['section.order-company-card', 'picker' => ['input', 'label', 'div.order-company-select', 'button.button']],
                '/admin/invoice/create' => ['section.order-company-card', 'picker' => ['input', 'label', 'div.order-company-select', 'button.button']],
            ],
            $shapes,
            'step one is the frame\'s customer chooser, inside the frame\'s shell, on all three',
        );

        $I->assertSame(
            ['_token', 'company_id'],
            $controls['/admin/order/create'],
            'and it posts a customer id and nothing else',
        );
        $I->assertSame($controls['/admin/order/create'], $controls['/admin/estimate/create']);
        $I->assertSame($controls['/admin/order/create'], $controls['/admin/invoice/create']);
    }

    /**
     * The action row above the grid, absent and present on the SAME XPath — the order's edit page
     * keeps its buttons in the hero and at the bottom, so a row there would be a third copy.
     */
    public function theActionRowAboveTheGridIsThereOnlyWhereThereAreActionsForIt(FunctionalTester $I): void
    {
        $order = $this->order($I);
        $path = '//form[@id="order-form"]/div[@class="order-workspace-actions"]';

        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $order->getCompany()->getId());
        $I->assertSame(
            1,
            \count($this->shapeOf($I, $path)),
            'the create page keeps its saves in the row above the grid — the positive control for'
            . ' the absence below',
        );

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->assertSame(
            [],
            $this->shapeOf($I, $path),
            'the edit page renders no empty wrapper where it has nothing to put in it',
        );
    }

    // ── the posting contract (#624) ──────────────────────────────────────────────────

    /**
     * The order form, re-posted exactly as it was rendered, stores the figures it was rendered
     * with. This is the defect a frame refactor causes silently: reformat a value on its way into
     * a box, or drop a hidden `*_rendered` twin, and this round trip rewrites what was stored.
     */
    public function savingAnUntouchedOrderFormRewritesNoFigure(FunctionalTester $I): void
    {
        $order = $this->order($I);
        $id = (int) $order->getId();
        $lineId = (int) $order->getLines()->first()->getId();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $before = $entityManager->getRepository(SalesOrderLine::class)->find($lineId);
        $storedQuantity = $before->getQuantity();
        $storedPrice = $before->getPrice();

        $I->amOnPage('/admin/order/edit/' . $id);
        $posted = $this->formPayload($I->grabPageSource(), 'order-form');

        $I->assertGreaterThan(
            0,
            \count($posted['lines'] ?? []),
            'no line rows were read off the page, so re-posting it would prove nothing',
        );
        $I->assertSame(
            $posted['lines'][0]['qty'],
            $posted['lines'][0]['qty_rendered'],
            'the quantity box and its hidden twin left the server disagreeing, which is the state'
            . ' boxUntouched() reads as "somebody retyped this"',
        );
        $I->assertSame($posted['lines'][0]['price'], $posted['lines'][0]['price_rendered']);

        // One field IS changed, and it is not a figure. It is the positive control: without it a
        // save that silently did nothing at all would satisfy every assertion below (#627).
        $posted['po_number'] = 'PO-ROUND-TRIP';
        $posted['save_mode'] = 'draft_recalc';
        $I->sendFormPostRequest('/admin/order/edit/' . $id, $posted);

        $entityManager->clear();
        $stored = $entityManager->getRepository(SalesOrderLine::class)->find($lineId);
        $savedOrder = $entityManager->getRepository(SalesOrder::class)->find($id);

        $I->assertNotNull($stored, 'the line the form was rendered from is gone after saving it back');
        $I->assertSame('PO-ROUND-TRIP', $savedOrder->getPoNumber(), 'the save did not run at all');
        $I->assertSame($storedQuantity, $stored->getQuantity(), 'the quantity was rewritten by a save that was not asked to touch it');
        $I->assertSame($storedPrice, $stored->getPrice(), 'the price was rewritten by a save that was not asked to touch it');
    }

    /** The same round trip on the quote, whose price box is text and may legitimately be blank. */
    public function savingAnUntouchedQuoteFormRewritesNoFigure(FunctionalTester $I): void
    {
        $estimate = $this->estimate($I);
        $id = (int) $estimate->getId();
        $lineId = (int) $estimate->getLines()->first()->getId();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $before = $entityManager->getRepository(EstimateLine::class)->find($lineId);
        $storedQuantity = $before->getQuantity();
        $storedPrice = $before->getPrice();

        $I->amOnPage('/admin/estimate/edit/' . $id);
        $posted = $this->formPayload($I->grabPageSource(), 'estimate-form');

        $I->assertGreaterThan(
            0,
            \count($posted['lines'] ?? []),
            'no line rows were read off the page, so re-posting it would prove nothing',
        );
        $I->assertSame($posted['lines'][0]['qty'], $posted['lines'][0]['qty_rendered']);
        $I->assertSame($posted['lines'][0]['price'], $posted['lines'][0]['price_rendered']);

        $posted['po_number'] = 'PO-ROUND-TRIP';
        $posted['action'] = 'save';
        $I->sendFormPostRequest('/admin/estimate/edit/' . $id, $posted);

        $entityManager->clear();
        $stored = $entityManager->getRepository(EstimateLine::class)->find($lineId);
        $savedEstimate = $entityManager->getRepository(Estimate::class)->find($id);

        $I->assertNotNull($stored, 'the line the form was rendered from is gone after saving it back');
        $I->assertSame('PO-ROUND-TRIP', $savedEstimate->getPoNumber(), 'the save did not run at all');
        $I->assertSame($storedQuantity, $stored->getQuantity(), 'the quantity was rewritten by a save that was not asked to touch it');
        $I->assertSame($storedPrice, $stored->getPrice(), 'the price was rewritten by a save that was not asked to touch it');
    }

    // ── reading the page ─────────────────────────────────────────────────────────────

    /**
     * The elements an XPath matches, as `tag.first-class`. The frame is a SEQUENCE of regions, so
     * what is compared is the whole sequence rather than the presence of any one of them.
     *
     * @return list<string>
     */
    private function shapeOf(FunctionalTester $I, string $xpath): array
    {
        $document = $this->parse($I->grabPageSource());
        $nodes = (new \DOMXPath($document))->query($xpath);
        $I->assertNotFalse($nodes, 'the XPath ' . $xpath . ' is not valid');

        $shape = [];
        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $class = trim(explode(' ', trim($node->getAttribute('class')))[0]);
            $shape[] = strtolower($node->tagName) . ($class === '' ? '' : '.' . $class);
        }

        return $shape;
    }

    /**
     * The names of every control on the page a browser would submit, in document order, with no
     * duplicates. Controls inside a <template> or a <noscript> are excluded for the reason both
     * exist: a browser with scripting on parses neither as part of the page.
     *
     * @return list<string>
     */
    private function postableControlNames(string $html): array
    {
        $names = [];
        foreach ($this->submittableControls($this->parse($html)) as [$name, $value]) {
            if (!\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Everything one named form would submit if it were posted untouched, as the nested array
     * `lines[0][qty]` names. This is the browser's job, done here, so that the round trip below is
     * the round trip an admin makes by opening a document and pressing Save.
     *
     * @return array<string, mixed>
     */
    private function formPayload(string $html, string $formId): array
    {
        $document = $this->parse($html);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@id="' . $formId . '"]')->item(0);
        if (!$form instanceof \DOMElement) {
            throw new \RuntimeException('no form #' . $formId . ' on the page');
        }

        $payload = [];
        foreach ($this->submittableControls($document, $form) as [$name, $value]) {
            $this->assign($payload, $name, $value);
        }

        return $payload;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function submittableControls(\DOMDocument $document, ?\DOMElement $scope = null): array
    {
        $xpath = new \DOMXPath($document);
        $context = $scope ?? $document->documentElement;
        $controls = [];

        foreach ($xpath->query('.//input | .//select | .//textarea', $context) as $control) {
            /** @var \DOMElement $control */
            if ($control->getAttribute('name') === '' || $control->hasAttribute('disabled')) {
                continue;
            }
            // A <template>'s contents are inert, and a <noscript>'s are text to a browser that is
            // running scripts. Neither posts, so neither is read here.
            for ($parent = $control->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
                if (\in_array(strtolower($parent->tagName), ['template', 'noscript'], true)) {
                    continue 2;
                }
            }

            $tag = strtolower($control->tagName);
            if ($tag === 'select') {
                $options = $xpath->query('.//option', $control);
                $value = null;
                foreach ($options as $option) {
                    /** @var \DOMElement $option */
                    if ($option->hasAttribute('selected')) {
                        $value = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
                    }
                }
                // A browser selects the first option where the markup selects none.
                if ($value === null && $options->length > 0) {
                    $first = $options->item(0);
                    /** @var \DOMElement $first */
                    $value = $first->hasAttribute('value') ? $first->getAttribute('value') : $first->textContent;
                }
                $controls[] = [$control->getAttribute('name'), (string) $value];
                continue;
            }

            if ($tag === 'textarea') {
                $controls[] = [$control->getAttribute('name'), $control->textContent];
                continue;
            }

            $type = strtolower($control->getAttribute('type')) ?: 'text';
            if (\in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }
            if ($type === 'submit' || $type === 'button' || $type === 'reset' || $type === 'file') {
                continue;
            }

            $controls[] = [$control->getAttribute('name'), $control->getAttribute('value')];
        }

        return $controls;
    }

    /** Turns `lines[0][qty]` into $payload['lines'][0]['qty']. */
    private function assign(array &$payload, string $name, string $value): void
    {
        if (!preg_match_all('/\[([^\]]*)\]/', $name, $matches) || !str_contains($name, '[')) {
            $payload[$name] = $value;

            return;
        }

        $keys = array_merge([substr($name, 0, strpos($name, '['))], $matches[1]);
        $cursor = &$payload;
        foreach ($keys as $key) {
            if ($key === '') {
                $cursor[] = [];
                $cursor = &$cursor[array_key_last($cursor)];
                continue;
            }
            if (!isset($cursor[$key]) || !\is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
        }
        $cursor = $value;
    }

    private function parse(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return $document;
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('EBF-' . $this->seq . '-' . uniqid())
            ->setPrimaryEmail('buyer-' . $this->seq . '@edit-frame.example');
        $I->haveInRepository($company);
        $company->addAddress(
            (new CompanyAddress())
                ->setLabel('HQ')
                ->setFirstName('Edit')
                ->setLastName('Frame')
                ->setAddressLine1('1 Frame Street')
                ->setCity('Vancouver')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V5K0A1')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
        );
        $I->grabService(EntityManagerInterface::class)->flush();

        return $company;
    }

    private function region(FunctionalTester $I, Company $company, string $name): void
    {
        $priceList = (new PriceList())->setName($name . ' List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
        $I->haveInRepository($region);
        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );
    }

    private function product(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('EBF-SKU-' . $this->seq)
            ->setName('Edit Frame Widget')
            ->setUnit('EA')
            ->setWeight('12.500')
            ->setSalesTaxCode('G')
            ->setCostPrice('30.55')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product);

        return $product;
    }

    private function order(FunctionalTester $I): SalesOrder
    {
        $company = $this->company($I, 'Edit Frame Order Co');
        $this->region($I, $company, 'Edit Frame Order Region');
        $product = $this->product($I);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('EBF-SO-' . $this->seq)
            ->setFulfillmentRegion('Edit Frame Order Region')
            ->setPoNumber('PO-7788')
            ->setSubtotal('130.50')
            ->setTax('0.00')
            ->setTotal('130.50');
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2')
                ->setPrice('65.25')
                ->setSubtotal('130.50')
        );
        $I->haveInRepository($order);

        return $order;
    }

    private function estimate(FunctionalTester $I): Estimate
    {
        $company = $this->company($I, 'Edit Frame Quote Co');
        $this->region($I, $company, 'Edit Frame Quote Region');
        $product = $this->product($I);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EBF-EST-' . $this->seq)
            ->setSource('Admin')
            ->setPoNumber('PO-4242');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('3.00')
                ->setCost('30.00')
                ->setPrice('65.25')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }
}
