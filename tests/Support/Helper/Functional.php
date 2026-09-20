<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use App\Contract\Status\StatusVocabularyLoaderInterface;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Service\ReferenceData\ReferenceDataSeeder;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Status\StatusVocabularyRegistry;
use Codeception\Module;
use Codeception\Module\Symfony;
use Codeception\TestInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Extends FunctionalTester with a relative-path multipart POST, for the one case
 *  (real file uploads) that submitForm's crawler-driven FileFormField can't cover once the
 *  crawler's form-action resolution is bypassed — see AdminProductImportCest and the
 *  host-based external-URL guard documented in CartHoldConfigCest. */
final class Functional extends Module
{
    /**
     * Primes {@see StatusVocabularyRegistry} before every test method, not just before every
     * HTTP request or console command.
     *
     * StatusVocabularyRegistrySubscriber's three hooks (kernel.request, console.command, Doctrine's
     * preFlush) all assume something has already happened — a request, a command, a flush — before
     * an entity's status is ever touched. A Functional Cest routinely builds an AdminUser/CustomerUser/
     * Company fixture and calls its (now HasStatusSeamTrait-backed) `setStatus()` on it — to move it
     * off the constructor's own default, e.g. `(new AdminUser())->setStatus('Inactive', ...)` — before
     * `$I->amOnPage()`/`$I->haveInRepository()` ever runs, which is the very first thing this suite's
     * one shared kernel does in a fresh run. Without this, every one of those calls throws "the status
     * vocabulary registry was never primed" — confirmed by running AdminUserCest, where all 23 tests
     * failed on exactly that before this hook existed.
     *
     * Cheap and idempotent (`StatusVocabularyRegistry::use()` just replaces a static property), so
     * priming again here ahead of the subscriber's own hooks costs nothing. Never reset in `_after()`:
     * this suite reuses one kernel/container for the whole run (see tests/_bootstrap.php), so staying
     * primed for its whole duration is the correct behaviour, not a leak between tests — `reset()` is
     * for the isolated unit tests {@see StatusVocabularyRegistry}'s own docblock describes.
     */
    public function _before(TestInterface $test): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        StatusVocabularyRegistry::use($symfony->grabService(StatusVocabularyLoaderInterface::class));
    }

    public function sendMultipartPostRequest(string $uri, array $params, array $files): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $symfony->_request('POST', $uri, $params, $files);
    }

    /** Plain relative-path form POST — no X-Requested-With header, i.e. exactly what a browser
     *  with JavaScript turned off sends when a submit button is pressed. sendAjaxPostRequest()
     *  is the suite's usual stand-in, but a test about the no-JS path should not be announcing
     *  itself as an XHR; submitForm() can't be used here at all, because the crawler resolves the
     *  form's action against the admin Host header and the module then refuses it as an external
     *  URL (see AdminCompanyInfoRegionCest and CartHoldConfigCest). */
    public function sendFormPostRequest(string $uri, array $params): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        // _loadPage rather than _request: _request leaves the module's crawler pointing at the
        // previous page, so seeElement()/seeNumberOfElements() would silently keep asserting
        // against the page before the POST.
        $symfony->_loadPage('POST', $uri, $params);
    }

    /**
     * Overrides the Symfony module's own sendAjaxPostRequest(), which is the suite's default POST.
     *
     * InnerBrowser::sendAjaxRequest() calls clientRequest() directly and never assigns the result
     * to the module's crawler, so every crawler-backed assertion after one — seeElement(),
     * dontSeeElement(), grabAttributeFrom(), seeInField(), seeNumberOfElements() — kept asserting
     * against the page loaded *before* the POST. A test written that way passes without ever
     * touching the response it claims to be about, and only fails if the pre-POST page happened to
     * lack the element too (#228).
     *
     * Same request otherwise — identical X-Requested-With header, same redirect handling — routed
     * through _loadPage() so the crawler points at the response, exactly as sendFormPostRequest()
     * already does. The POST also enters the page history now, which _loadPage() needs: it reads
     * the current URI to resolve the new page's base href, and the suite has plenty of tests that
     * POST without loading a page first.
     *
     * Text assertions — see(), dontSee(), grabPageSource() — read the raw response and were never
     * affected either way.
     */
    public function sendAjaxPostRequest(string $uri, array $params = []): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $symfony->_loadPage('POST', $uri, $params, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /**
     * Gives a company an ACTIVE fulfillment region, creating the region itself if it does not exist.
     *
     * Needed by any test that creates an order through the form. Since #237 a company with no active
     * fulfillment region cannot have a new order created for it: the region resolves the company's
     * price list, and without one orderProductRows() falls through to the product's default price,
     * pricing the order off a list nobody negotiated. Refusing at create costs nothing (nothing is
     * in flight); editing an existing order is deliberately still allowed.
     *
     * So a bare Company fixture is no longer a valid starting point for order creation, and this is
     * the one place that says how to make it one — rather than seven copies of the same three
     * entities drifting apart across the order Cests.
     */
    public function haveActiveFulfillmentRegionFor(Company $company, string $name = 'Main'): string
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $symfony->grabService(EntityManagerInterface::class);

        $region = $entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => $name]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName($name)->setStatus('Active');
            $entityManager->persist($region);
            // Paired with the warehouse that serves it, the same way every path that creates a
            // region does (#546) — otherwise the region is orderable with no stock behind it.
            $symfony->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
        }

        $entityManager->persist(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
        );
        $entityManager->flush();

        return $name;
    }

    /**
     * Stocks a product in a region, so an order carrying it can be saved in a status that reserves.
     *
     * The region is what the document names; the stock lands in the warehouse serving it (#546),
     * which is created alongside the region exactly as the admin screens create it.
     *
     * Needed by any test that saves a NON-DRAFT admin order through the form. Since #326 a save that
     * lands the order in a status OrderInventoryBucketResolver counts — Approved and everything
     * after it — is refused unless every line is covered by real availability.
     *
     * A missing ProductInventory row is deliberately treated as zero, not as untracked: reconcile()
     * CREATES the row when it is absent and applies the reservation to it, so an unstocked product
     * saved onto a Pending order lands the inventory at a negative availability, which is exactly
     * the defect #326 exists to stop. Fixtures that skipped this were relying on that hole.
     *
     * Drafts need no stock and should not call this — a draft reserves nothing.
     */
    public function haveStockFor(ProductCore $product, int $quantity = 1000, string $regionName = 'Main'): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $symfony->grabService(EntityManagerInterface::class);

        /** @var WarehouseFulfillmentRegionService $warehouses */
        $warehouses = $symfony->grabService(WarehouseFulfillmentRegionService::class);
        $warehouse = $warehouses->warehouseForRegionNameOrCreate($regionName, 'BC', 'CA');

        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

        $inventory->setQuantity($quantity);
        $entityManager->persist($inventory);
        $entityManager->flush();
    }

    /**
     * Stocks every product currently in the database in one region.
     *
     * For fixtures whose orders move between several named regions: stock has to exist in whichever
     * region the LINE resolves to, and a per-product call has to guess that in advance. Stocking the
     * region wholesale sidesteps the guess without weakening anything — these tests are about region
     * and status handling, not about scarcity, and the ones that DO test scarcity set an explicit
     * quantity of their own afterwards.
     */
    public function haveStockInRegionForAllProducts(string $regionName, int $quantity = 1000): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $symfony->grabService(EntityManagerInterface::class);

        foreach ($entityManager->getRepository(ProductCore::class)->findAll() as $product) {
            $this->haveStockFor($product, $quantity, $regionName);
        }
    }

    /**
     * Drops every cookie the client is holding — a different person, at a different browser.
     *
     * Needed by any test that logs a SECOND person in through the real form. Two other ways of
     * getting there do not work:
     *
     *  - staying logged in and reloading `/admin/login` hits AuthController's already-authenticated
     *    guard, which redirects to `/admin`, so there is no form and no CSRF token to scrape;
     *  - visiting `/admin/logout` while NOT authenticated is worse than useless — `^/admin` requires
     *    ROLE_ADMIN, so the access listener stores `/admin/logout` as the firewall's target path, and
     *    the next successful login redirects straight to it and logs the new session out again. That
     *    is a genuinely confusing failure (the login succeeds, the seeding happens, and every page
     *    afterwards renders the login screen) and it is why this exists rather than a logout call.
     */
    public function startAFreshBrowserSession(): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $symfony->client?->getCookieJar()->clear();
    }

    /**
     * Runs the central reference data seeder, exactly as the first admin LOGIN does.
     *
     * Needed by any test that drives a screen backed by shipped reference data — units of measure,
     * tracking policies, inventory adjustment reasons, the fee catalogues, the Canadian tax rates.
     * Those rows used to be created by RENDERING the screen that lists them, so a test only had to
     * open the page; now {@see \App\EventSubscriber\AdminLoginReferenceDataSeedSubscriber} creates
     * them on `LoginSuccessEvent` and the screens read.
     *
     * **`amLoggedInAs()` does not fire that event.** It installs a token straight into the token
     * storage and never runs the authenticator, so no `LoginSuccessEvent` is dispatched and nothing
     * is seeded — which is why this exists rather than the suite getting it for free. A test that
     * wants to exercise the SUBSCRIBER itself must post the real login form; see
     * `ReferenceDataSeedingCest`, which does exactly that.
     *
     * Idempotent, like the thing it calls: a second call in the same test writes nothing, because
     * the first one marked every key in `reference_data_seed_mark`.
     */
    public function haveSeededReferenceData(): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $symfony->grabService(ReferenceDataSeeder::class)->run();
    }

    /** Raw-body POST with custom headers — needed for the Stripe webhook, whose payload is a JSON
     *  body authenticated by a Stripe-Signature header rather than form fields. */
    public function sendRawPostRequest(string $uri, string $body, array $server = []): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $symfony->_request('POST', $uri, [], [], $server, $body);
    }

    /**
     * The app-wide CSRF token for the page currently loaded, read from its
     * <meta name="csrf-token"> exactly as a curl or Guzzle client would.
     *
     * Explicit on purpose: each call site passes the token itself, so a test meaning to send a bad
     * token (or none) still does and the check under test is never masked. If no page is loaded
     * yet, '/' is fetched purely to obtain one - the same two-step a non-browser client performs.
     */
    /**
     * The HTML body of the last email built for a recipient, read from email_log.
     *
     * Use this instead of seeEmailIsSent() / grabLastSentEmail(). Those inspect the mail
     * transport, and local delivery here is sandboxed by design — the transport records nothing,
     * so a transport assertion reports "Transport has sent 1 emails (0 sent)" against a perfectly
     * correct application. Three tests failed that way for as long as they existed, which is worse
     * than useless: a permanently red test teaches everyone to ignore the suite.
     *
     * email_log holds the body that was actually rendered and handed to the mailer, which is the
     * thing these assertions are about. It is also the path #35 proved matters — the DB template
     * row wins over the file, so asserting on what was built is the only way to catch a stale row.
     */
    public function grabLastSentEmailBody(string $recipient): string
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $symfony->grabService(\Doctrine\ORM\EntityManagerInterface::class);

        $log = $em->getRepository(\App\Entity\EmailLog::class)
            ->findOneBy(['recipient' => $recipient], ['id' => 'DESC']);

        \PHPUnit\Framework\Assert::assertNotNull(
            $log,
            'No email_log row was written for ' . $recipient . '. Nothing was built to assert on.'
        );

        return (string) $log->getBody();
    }

    /** Asserts an email was built for this recipient, without caring what it said. */
    public function seeEmailWasBuiltFor(string $recipient): void
    {
        $this->grabLastSentEmailBody($recipient);
    }

    /**
     * Asserts the last response did not clear the session cookie.
     *
     * seeCookie() cannot do this job. It reads BrowserKit's cookie jar, which is the client's
     * accumulated state, not the headers the response actually sent — so a response that clears the
     * session cookie still leaves an entry in the jar and seeCookie() passes. Verified by mutation:
     * making a CSRF refusal call clearCookie() on its response kills nothing when the assertion is
     * seeCookie(), and kills this one.
     *
     * Symfony expires a cookie by re-sending it with a past date and Max-Age=0 (the shape
     * AbstractSessionListener produces for an empty session), so the header, not the jar, is the
     * only place the difference is visible.
     */
    public function dontSeeSessionCookieCleared(string $name = 'MOCKSESSID'): void
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        $headers = $symfony->client?->getInternalResponse()?->getHeader('Set-Cookie', false) ?? [];

        foreach ((array) $headers as $header) {
            if (!str_starts_with((string) $header, $name . '=')) {
                continue;
            }
            $cleared = str_contains((string) $header, $name . '=deleted')
                || str_contains((string) $header, $name . '=;')
                || preg_match('/max-age=0(?![0-9])/i', (string) $header) === 1;
            if ($cleared) {
                \PHPUnit\Framework\Assert::fail(
                    'The response cleared the ' . $name . ' session cookie: ' . $header
                );
            }
        }

        \PHPUnit\Framework\Assert::assertTrue(true);
    }

    /**
     * A response header, by name. Neither the Symfony module nor InnerBrowser exposes one — they
     * cover the request side (haveHttpHeader) and the body, but a download is defined almost
     * entirely by its Content-Type and Content-Disposition, and asserting on the body alone would
     * pass for a file the browser renders inline instead of saving.
     *
     * Returns '' rather than null when the header is absent, so a caller can assert on the value
     * without a null check and still fail on a missing header.
     */
    public function grabResponseHeader(string $name): string
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');

        return (string) ($symfony->client?->getInternalResponse()?->getHeader($name) ?? '');
    }

    public function csrfToken(): string
    {
        $token = $this->scrapeCsrfToken();
        if ($token !== '') {
            return $token;
        }

        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        try {
            $symfony->amOnPage('/');
        } catch (\Throwable) {
            return '';
        }

        return $this->scrapeCsrfToken();
    }

    private function scrapeCsrfToken(): string
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');

        try {
            $html = $symfony->_getResponseContent();
        } catch (\Throwable) {
            return '';
        }

        if (!preg_match('/<meta\s+name="csrf-token"\s+content="([^"]*)"/i', $html, $m)) {
            return '';
        }

        return html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');
    }

    /**
     * Everything one named form on the CURRENT page would submit if a browser posted it untouched,
     * as the nested array `lines[0][qty]` names — the CSRF field included, because on the page it
     * is a control like any other.
     *
     * This is the browser's job, done here, so that a test can make the round trip an admin makes
     * by opening a document and pressing Save. It matters for anything asserting that a save did
     * NOT rewrite a figure: `LineDenomination::boxUntouched()` compares a box against its hidden
     * `*_rendered` twin BYTE FOR BYTE, and a payload assembled by hand rather than read off the
     * page proves nothing about the round trip a person actually makes.
     *
     * It is also the only honest way to test that a field is REACHABLE. A hand-built payload posts
     * fields no screen renders just as happily as fields it does — which is exactly how
     * `lines[N][stock_override_reason]` came to be read by two controllers, covered by tests, and
     * typeable on no screen at all (#326).
     *
     * A browser's rules, not a crawler's:
     *  - controls inside a `<template>` or a `<noscript>` are skipped, because a browser running
     *    scripts parses neither as part of the page;
     *  - an unchecked checkbox or radio posts nothing, and neither does a disabled control;
     *  - submit / button / reset / file controls are left out — WHICH submit was pressed is a
     *    statement about what the save means, so it stays the caller's to add;
     *  - a `<select>` with nothing marked selected posts its first option, as a browser does.
     *
     * @return array<string, mixed>
     */
    public function grabFormPayload(string $formId): array
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $symfony->_getResponseContent(), \LIBXML_NOWARNING | \LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@id="' . $formId . '"]')->item(0);
        if (!$form instanceof \DOMElement) {
            \PHPUnit\Framework\Assert::fail('There is no form #' . $formId . ' on this page to read.');
        }

        $payload = [];
        foreach ($xpath->query('.//input | .//select | .//textarea', $form) as $control) {
            /** @var \DOMElement $control */
            $name = $control->getAttribute('name');
            if ($name === '' || $control->hasAttribute('disabled')) {
                continue;
            }

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
                if ($value === null && $options->length > 0) {
                    $first = $options->item(0);
                    /** @var \DOMElement $first */
                    $value = $first->hasAttribute('value') ? $first->getAttribute('value') : $first->textContent;
                }
                $this->assignPostedValue($payload, $name, (string) $value);
                continue;
            }

            if ($tag === 'textarea') {
                $this->assignPostedValue($payload, $name, $control->textContent);
                continue;
            }

            $type = strtolower($control->getAttribute('type')) ?: 'text';
            if (\in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }
            if (\in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
                continue;
            }

            $this->assignPostedValue($payload, $name, $control->getAttribute('value'));
        }

        return $payload;
    }

    /** Turns `lines[0][qty]` into $payload['lines'][0]['qty'], and a trailing `[]` into the next slot. */
    private function assignPostedValue(array &$payload, string $name, string $value): void
    {
        if (!str_contains($name, '[') || !preg_match_all('/\[([^\]]*)\]/', $name, $matches)) {
            $payload[$name] = $value;

            return;
        }

        $keys = array_merge([substr($name, 0, (int) strpos($name, '['))], $matches[1]);
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
}
