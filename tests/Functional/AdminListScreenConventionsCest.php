<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\Document\CommercialDocument;
use App\Entity\AdminUser;
use Doctrine\DBAL\Driver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Driver as DebugDriver;
use Symfony\Bridge\Twig\DataCollector\TwigDataCollector;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Support\FunctionalTester;

/**
 * The conventions every admin list screen follows, asserted rather than hoped for (#621).
 *
 * ## What went wrong without this
 *
 * An audit of 36 bundle admin screens found 4 ok, 6 broken, 26 inconsistent, and almost every
 * defect was a bundle skipping something core already had: `table-header` 32 uses in core and 0 in
 * bundles, `no-paginate` 40 and 0, `js-` hooks 445 and 7. #618, #619 and #620 then changed 35
 * templates and added no test, so nothing stopped the drift returning the next time a bundle added
 * a screen — and screens keep being added.
 *
 * ## Why this discovers its subjects instead of listing them
 *
 * A conformance test naming its screens in an array passes forever while the next screen quietly
 * skips the rule. That is how the repo reached 2-of-8 documents implementing their own contract,
 * and #636 fixed it by asking Doctrine for every mapped entity rather than typing class names.
 * `EveryDocumentDeclaresItsContractTest` is the shape this file follows: discover, classify,
 * assert, and put every exclusion in a named list with a reason that is itself asserted to still
 * be true.
 *
 * Here the subjects come from the router. Every route the application publishes under `/admin`
 * that a browser can reach with a GET and no parameters is fetched, as a logged-in admin, and the
 * response it actually renders is what gets asserted. A screen that did not exist when this file
 * was written is a subject the day its route is registered; nobody has to remember this test.
 *
 * ## What counts as a list screen
 *
 * Not the route name, and not the template's filename — `/admin/product/detail/index` is a
 * 23-column grid and `templates/admin/system/onboarding.html.twig` is a checklist, so both naming
 * conventions lie. The application already draws the line itself, in CSS:
 *
 *     app.css:7764   .site-admin .content-frame:has(> .table-card .table-scroll-region)
 *
 * The full-height clamp is applied only when a `.table-card` is a DIRECT child of the page's
 * `.content-frame` — i.e. when the grid *is* the page. A `.table-card` nested deeper is a panel on
 * a detail or form page, and the comment above that rule says so in as many words. So:
 *
 *   - GRID     — the page has at least one `.content-frame > .table-card`. This is a list screen.
 *   - EMBEDDED — the page has `.table-card`s, but none of them at the top level. A detail, form or
 *                dashboard page whose tables are panels. The dashboard is the one such page
 *                reachable without a route parameter.
 *   - PLAIN    — no `.table-card` anywhere: a settings page, a create form, a reference sheet.
 *   - UNFRAMED — no `.content-frame` at all: the logged-out login/password pages.
 *
 * The classification is total, and the case it cannot classify fails loudly rather than being
 * skipped: a page carrying a `.table-card` or a `.table-scroll-region` with no `.content-frame`
 * around it is neither a screen nor a bare page, and
 * `everyAdminScreenAnswersAndClassifies()` says so by name.
 *
 * ## PLAIN is not a way out, and used to be
 *
 * For its first version this file said of PLAIN, in as many words, that "none of these conventions
 * has anything to say about them" — and named `/admin/carts` and `/admin/backorders` as the two it
 * was thinking of. Both were lists. Both had no filters at all and neither got the full-height
 * clamp, because that clamp is a CSS rule on `.content-frame > .table-card` and they had no
 * `.table-card` to hang it on. So the classification handed an exemption to precisely the screens
 * that most needed the rules: a grid escaped every convention in this file by never taking the
 * shape the conventions are written about, and the less it conformed the less it was asked.
 *
 * `everyListScreenIsInTheGridModel()` closes that. A page that IS a list and carries no
 * `.table-card` is a finding, and the only way out is a named entry in `PLAIN_LISTS` with a reason
 * — asserted below to still be true, exactly as `KNOWN_OFFENDERS` entries are, so that conforming
 * a screen fails this file until its entry goes with it.
 *
 * ## How the test decides a page is a list
 *
 * Not from a hand-kept list of routes, which is the thing this file exists not to be, and not from
 * the markup the screen chose — the markup is the very thing under test, so reading "is this a
 * list" off it would make the rule circular and vacuous. Two pieces of evidence about what the
 * page DID, either of which is enough:
 *
 *  1. **It paged a table.** The request ran both a counting and a limiting query rooted at one
 *     table — `pagedTablesFrom()`, the same reading that maps a document to its screen further
 *     down. A page that asks how many there are and then fetches one page of them is a list
 *     whatever it is wrapped in.
 *  2. **It rendered an empty state.** A `<table>` whose header declares two or more columns and
 *     whose body holds a single cell spanning all of them. This sweep runs against an empty
 *     database (see below), so that row is the `{% else %}` of a loop over rows the database did
 *     not have — which is a table of data, not a layout table or a form.
 *
 * Neither is perfect alone and that is why there are two: the cart list paged nothing before this
 * (it fetched every cart on file, unbounded), and a list that renders nothing at all when empty has
 * no empty state to find. Both are properties of the response and the query log rather than of a
 * name, so a screen added tomorrow is caught the day it is added.
 *
 * ## What a document list is MADE OF
 *
 * Everything above is mechanical: no double pager, one scroll region, rows that span the table, a
 * filter form that submits with the keyboard. A screen can pass every one of them and still look
 * nothing like the rest of the application. `/admin/bundles/procurement/purchase-orders` does: no
 * status filter bar, three flat buttons where every other grid has one dropdown, and a create
 * button floating in a panel of its own. Well-formed and unrecognisable, which is exactly how it
 * drifted.
 *
 * So five more rules, about what a list is COMPOSED OF rather than how its markup is put together,
 * read off `/admin/order` because that is the screen the others were meant to resemble:
 *
 *   1. a status filter bar — All Orders / Draft / Approved / Partially Invoiced / Invoiced /
 *      Closed;
 *   2. per-column filter inputs, in a row under the header (see also
 *      `templates/admin/company/index.html.twig` lines 59-63);
 *   3. one row-actions dropdown, not a spread of flat buttons
 *      (`templates/admin/company/_list_rows.html.twig` lines 21-32);
 *   4. a create button in the header, wherever the document can be created at all;
 *   5. a Status COLUMN — added for queue item 41, whose ruling is that status belongs "in tables
 *      and on the detail page, big and loud". Rule 1 is about narrowing the grid to one status;
 *      this is about the rows that come back saying which one they are in.
 *
 * ## Why these five apply to nine screens and not to fifty
 *
 * They are rules about DOCUMENTS. A document has a status that moves through a life, a
 * counterparty and a date, so "show me the ones still open", "which state is this one in" and
 * "find the one for that customer" are the questions anybody ever asks of a grid of them — which
 * is what a status bar, a status column and a per-column filter row answer. A price list or a
 * warehouse has no such life, and a report is read rather than worked. The owner drew the line at
 * `App\Contract\Document\CommercialDocument`, which #636 turned into a real, tested statement
 * about which entities are documents, so the subjects of these five are every entity implementing it — taken from Doctrine's metadata exactly as
 * `EveryDocumentDeclaresItsContractTest` takes its own, never typed out. Master data (products,
 * companies, vendors, price lists) and reports are deliberately out of scope.
 *
 * ## Getting from a document to its screen
 *
 * The contract says which classes are documents. Nothing says which SCREEN lists one, and the
 * obvious answers do not survive contact with this application: `SalesOrder` is listed by
 * `OrderController::orders`, no naming rule ties `/admin/credit-memo/index` to `CreditMemo` more
 * tightly than `/admin/credit-memo-types` is tied to it, and `RfqVendorReply` has no list screen at
 * all.
 *
 * So the mapping is read off a behaviour rather than a name: **a list screen pages its rows** — it
 * asks how many there are and then fetches one page of them — so the grid whose request runs both
 * a counting and a limiting query rooted at `purchase_order` IS the purchase order list. The
 * statements come from Doctrine's own query log, which this suite has been filling and nobody has
 * been reading (`queryLog()`); the templates each screen rendered come from Symfony's profiler,
 * which Codeception's connector enables on every request here anyway. Neither changes how the
 * application runs.
 *
 * Where it is fragile is said out loud on `rootTableOf()` and `pagedTablesFrom()`: the first reads
 * SQL with a regular expression, and the second is why `/admin/bundles/procurement/exceptions` —
 * which caps two feeds at 200 rows and counts neither — is not mistaken for both the purchase order
 * list and the vendor bill list. What neither can do is quietly get it wrong for long: a grid that
 * stops paging, or a second grid that starts paging the same table, leaves a document with nought
 * or two screens, and `everyCommercialDocumentHasOneListScreen()` says so by name rather than
 * letting a document fall silently out of the sweep.
 *
 * ## Scope this does not reach
 *
 * Only routes with no parameters, so document/detail pages (`/admin/order/{id}`,
 * `/admin/bundles/warehouse-ops/transfers/{id}`) are not fetched — each would need its own
 * fixture, and a test that seeded thirty documents to look at their markup would be a fixture
 * suite pretending to be a conformance test. What that costs is stated in
 * `theScrollRegionIsOnlyEverTheWholeGrid()`: the "no scroll region on a detail page" half of the
 * rule is asserted against the pages this reaches, not against every detail page in the app.
 *
 * For the same reason the grids here render with an empty database, so it is the empty-state row
 * whose `colspan` gets checked. That is the right half to be sure of — the arithmetic is written
 * once in the template and does not depend on the data — and any real row that does render is
 * checked too.
 */
final class AdminListScreenConventionsCest
{
    /**
     * Routes the sweep does not fetch, and why. Not screens that fail a convention — those are
     * KNOWN_OFFENDERS below — but routes that cannot be part of a sweep at all.
     *
     * Each name is asserted to still be a registered route, so an entry that has been renamed or
     * deleted fails here instead of silently exempting nothing.
     *
     * @var array<string, string>
     */
    private const NOT_FETCHABLE = [
        '_logout_admin' =>
            'ends the session. Fetching it half way through the sweep would log the browser out and'
            . ' every screen after it would be asserted against the login page instead',
    ];

    /**
     * Screens that break a convention TODAY, with what each one does wrong.
     *
     * #621 is a tests-only change: these were found by this test and are deliberately left broken,
     * because a convention test that ships alongside the fixes for everything it found cannot show
     * that it bites. Each entry is asserted below to STILL be wrong, so whoever fixes the screen is
     * told to delete the entry rather than leaving a stale note that reads like a considered
     * exemption.
     *
     * @var array<string, string>
     */
    private const KNOWN_OFFENDERS = [
        // #649 fixed all three entries #621 shipped with — admin_tracking_policy_index (no
        // `no-paginate`, so app.js:394 injected a pager onto a screen that does not page),
        // admin_inventory_index and admin_product_price_index (empty states one column short) —
        // and this constant was empty until the compositional rules below were written. Five is
        // what those rules found on the day they landed, none of them fixed there: #621 is a
        // tests-only change, and a convention test that ships alongside the fixes for everything
        // it found cannot show that it bites.
        //
        // **It is empty again, and that is the mechanism finishing rather than a rule being
        // relaxed.** admin_bundle_procurement_bills went when #658 brought the bill list to invoice
        // parity, and the last four went together when the screens they named were conformed:
        //
        //  - admin_bundle_procurement_purchase_orders — status filter bar off
        //    PurchaseOrderStatus::cases(), View/Edit/Cancel collected into one row-action dropdown,
        //    and "Raise a purchase order" moved into the header panel's .panel-actions.
        //  - admin_bundle_procurement_debit_memos — status filter bar, a per-column filter row
        //    (memo/vendor search, vendor, status) and "New debit memo" in .panel-actions.
        //  - admin_credit_memo_index — status filter bar and a filter row whose search box is a
        //    plain GET parameter the controller reads. The inert js-memo-search box #586 shipped is
        //    gone: it is the control that was being replaced, not kept.
        //  - admin_sales_return_index — the same, plus an Arrived / Not yet arrived filter on the
        //    Received column. Its .table-search box narrowed the rows already on the page rather
        //    than the query behind them, so anything on page two was invisible to it.
        //
        // An entry here is asserted below to STILL be wrong, so a fix cannot quietly leave a stale
        // exemption behind: fixing the screen fails the test until the entry goes too. That is the
        // opposite of an allowlist, which rots the moment somebody fixes something. Four screens
        // were fixed and four entries went with them, in the same commit, because the test would
        // not go green otherwise.
    ];

    /**
     * Lists that are NOT in the grid model, and the argument for each one still being outside it.
     *
     * `everyListScreenIsInTheGridModel()` reports every page that reads as a list — it paged a
     * table, or it rendered an empty state — and carries no `.table-card`. This is the only way out
     * of that rule, and it is the `KNOWN_OFFENDERS` habit rather than an allowlist: each entry is
     * asserted below to still name a reachable route, to still read as a list, and to still have no
     * `.table-card` on it. Conform one of these screens and this file fails until the entry is
     * deleted along with the markup it describes.
     *
     * They are in two groups, and the entries say which.
     *
     * **Master-data lists that should be conformed and have not been.** Four screens fetch every
     * row of a table, unpaged, into a `.panel > table.data-table`. They are the same shape
     * `/admin/backorders` and `/admin/carts` were before this change, and the same work conforms
     * them. They are listed rather than fixed here for the reason #621 listed its own findings
     * rather than fixing them: a rule that ships with every screen it found already fixed cannot be
     * shown to bite.
     *
     * **Pages the evidence reads as a list and a person would not.** Four screens render a table of
     * rows with an empty state without being a grid of that table: a scan tally held in the URL, a
     * rules table inside a settings form, the bin table of a map, and the candidate list of a
     * compile form. The evidence is doing what it says — each really does tabulate rows from the
     * database — and the honest answer is to say what the page is, not to add a special case to the
     * evidence so that the count comes out flattering.
     *
     * @var array<string, string>
     */
    private const PLAIN_LISTS = [
        'admin_redirect_index' =>
            'lists every redirect in `.panel > table.data-table`, unpaged, with no filter of any'
            . ' kind — the shape /admin/backorders had. RedirectController::index() fetches'
            . ' findAllOrdered() and the template loops it. It should be a .content-frame >'
            . ' .table-card with a filter row on source path and target; it is not one yet',
        'admin_bundle_news_index' =>
            'the same: NewsPostController::index() fetches every post with findAllOrdered() into a'
            . ' bare table, four columns and nothing to narrow them by. The two settings toggles'
            . ' above it are a form and are fine where they are; the grid below them is not in the'
            . ' model',
        'admin_bundle_fee_user_defined_index' =>
            'lists every user-defined fee from findBySource(), unpaged, in a bare table. Five'
            . ' columns, no filter row, no footer count — and fees are the kind of master data that'
            . ' accumulates one row per province per commodity',
        'admin_bundle_payment_manual_index' =>
            'lists every manual payment method from findBySource() in a bare three-column table.'
            . ' The smallest of the four and the same defect: no .table-card, so nothing in this'
            . ' file reaches it',
        'admin_bundle_procurement_settings' =>
            'is a settings FORM whose middle section happens to tabulate the per-product receiving'
            . ' rules, with an empty state ("No product requires anything at receiving yet"). The'
            . ' page is two tolerance fields and a rule editor, not a grid of'
            . ' product_receiving_rule: the rows are edited in place beneath the form that writes'
            . ' them, and a filter row over them would filter away the thing being edited',
        'admin_bundle_warehouse_ops_bin_map' =>
            'is a MAP. The bin table under it exists so that every bin is accounted for — the'
            . ' controller says in as many words that a map showing 180 of 200 bins would be worse'
            . ' than none — and its cells are number inputs that write the coordinates back. It'
            . ' already filters by warehouse, zone and product from the page above the grid; it'
            . ' tabulates bins in order to place them, not in order to list them',
        'admin_bundle_warehouse_ops_pick_list_new' =>
            'is the pick-list COMPILE form. Its table is the candidate set — orders still owing'
            . ' goods — with a checkbox per row, and submitting it creates a pick list from the'
            . ' ticked ones. The rows are an input to one action, not a grid somebody works; the'
            . ' list screen of a pick list is /admin/bundles/warehouse-ops/pick-lists, which is a'
            . ' .table-card and is held to every rule in this file',
    ];

    /**
     * Commercial documents that have no list screen of their own, and why there is none.
     *
     * The compositional rules below apply to the list screen of every entity implementing
     * `App\Contract\Document\CommercialDocument` (see the class docblock for why that line and not
     * another). A document with no such screen is not an offender — there is nothing to hold to
     * the rules — but it must say so here, with a reason, and the entry is asserted to still be
     * true: the day somebody gives the document a grid, `theNamedExclusionsAreStillTrue()` fails
     * until this entry goes with it.
     *
     * @var array<class-string, string>
     */
    private const NO_LIST_SCREEN = [
        'ProcurementBundle\Entity\RfqVendorReply' =>
            'is read on the RFQ it answers, not on a list of its own. /admin/bundles/procurement/'
            . 'rfqs/{id} puts every vendor\'s reply to that one requirement side by side, which is'
            . ' the only comparison anybody makes of them; a flat list of replies across RFQs would'
            . ' be a column of prices with the questions taken away. The same argument the contract'
            . ' itself makes about Rfq — a requirement put to several vendors is not one document'
            . ' per counterparty — read from the other end',
    ];

    /**
     * @var array<string, array{path: string, code: int, contentType: string, html: string, framed: bool, cards: int, topCards: int, url: string, pagedTables: list<string>, templates: list<string>}>|null
     */
    private static ?array $pages = null;

    /**
     * @var array<class-string, array{table: string, routes: list<string>}>|null
     */
    private static ?array $documentScreens = null;

    /**
     * Every list screen's `.table-card` opts out of the JavaScript pager.
     *
     * `public/assets/js/app.js:383` walks every `.table-card` on the page and, unless the card says
     * otherwise, calls `initFooterControls()` on it — which appends its own footer with a Per page
     * select and a row count. On a list screen the server has already paged the rows and rendered
     * exactly those two controls, so the page ends up with two of each, disagreeing.
     *
     * The two ways out are the app's own, read straight from that loop rather than invented here: a
     * card with no `<table>` inside it is skipped, and so is one carrying
     * `data-link-controls="true"`. Everything else must carry `no-paginate`.
     */
    public function everyGridCardOptsOutOfTheJavascriptPager(FunctionalTester $I): void
    {
        $offenders = [];

        foreach ($this->gridScreens($I) as $route => $page) {
            $this->topLevelCards($this->dom($page))->each(function (Crawler $card) use (&$offenders, $route, $page): void {
                if ($card->filter('table')->count() === 0) {
                    return; // app.js:391 — `if (!$table.length) { return; }`
                }

                if (strtolower((string) $card->attr('data-link-controls')) === 'true') {
                    return; // app.js:392 — the card drives its own controls through links.
                }

                if ($this->hasClass($card, 'no-paginate')) {
                    return;
                }

                $offenders[$route] = sprintf(
                    '%s (%s) — .table-card class="%s"',
                    $route,
                    $page['path'],
                    (string) $card->attr('class'),
                );
            });
        }

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These list screens let app.js paginate a server-paged grid, which puts a second Per page"
            . " control and a second row count in the footer:",
        );
    }

    /**
     * A `.table-scroll-region` is only ever the one grid that fills the page.
     *
     * It is not a decoration. `app.css:7803` gives it `min-height: 220px` and `overflow: auto`, and
     * `app.css:7764` clamps the whole `.content-frame` around it to `height: calc(100vh - 3rem)` —
     * but only when the `.table-card` holding it is a DIRECT child of that frame. Put one anywhere
     * else and neither half lands: a two-row panel table becomes a 220px scrolling box, and a page
     * with sections after it has them pushed past the bottom of the frame.
     *
     * So three things, all read off those two rules: at most one per page, always inside a
     * top-level `.table-card`, and reaching it either directly or through the filter `<form>` that
     * `app.css:7792` styles for exactly that purpose. The second of the three is what says a detail
     * or document page may not have one — on such a page no `.table-card` is top-level.
     */
    public function theScrollRegionIsOnlyEverTheWholeGrid(FunctionalTester $I): void
    {
        $tooMany = [];
        $misplaced = [];
        $seen = 0;

        foreach ($this->screens($I) as $route => $page) {
            $regions = $this->dom($page)->filter('.table-scroll-region');
            $seen += $regions->count();

            if ($regions->count() > 1) {
                $tooMany[$route] = sprintf('%s (%s) has %d', $route, $page['path'], $regions->count());
            }

            $regions->each(function (Crawler $region) use (&$misplaced, $route, $page): void {
                $chain = $this->chainToTopLevelCard($region);

                if ($chain === null) {
                    $misplaced[$route] = sprintf(
                        '%s (%s) — a .table-scroll-region that is not inside a .content-frame >'
                        . ' .table-card, so it gets the 220px floor with none of the full-height'
                        . ' clamp that makes it make sense',
                        $route,
                        $page['path'],
                    );

                    return;
                }

                if ($chain !== [] && $chain !== ['form']) {
                    $misplaced[$route] = sprintf(
                        '%s (%s) — .table-scroll-region sits under <%s> inside its .table-card;'
                        . ' app.css only carries the flex chain down through a direct-child <form>',
                        $route,
                        $page['path'],
                        implode('><', $chain),
                    );
                }
            });
        }

        $I->assertGreaterThan(30, $seen, 'Only ' . $seen . ' .table-scroll-regions found across every admin screen. The grid model has either been replaced or renamed, and the two assertions below are now passing over nothing.');

        $this->assertOnly($I, [], $tooMany, 'These screens render more than one .table-scroll-region, so they have two independently scrolling regions competing for the same clamped height:');
        $this->assertOnly($I, [], $misplaced, 'These .table-scroll-regions are in a place the CSS that defines them does not reach:');
    }

    /**
     * Every row of a grid spans the whole table.
     *
     * A filter cell that has drifted one column off its header is not a rendering error; it is a
     * box that files a Serial number under Qty, and the page looks fine. `/admin/bundles/
     * inventory-depth/movements` shipped exactly that, and a transfer's detail table shipped an
     * empty state spanning 9 columns over 7.
     *
     * The table's width is taken from its own header — the widest header row, counting `colspan`
     * and carrying `rowspan` down the way a browser does — and then every header row (the filter
     * row included, since it is one), every body row and every empty state has to agree with it.
     * One number, four things compared against it, because they are one fact: the filter row
     * matching the header while the empty state does not is the same defect twice.
     */
    public function everyGridRowSpansTheWholeTable(FunctionalTester $I): void
    {
        $offenders = [];
        $widest = 0;

        foreach ($this->gridScreens($I) as $route => $page) {
            $this->topLevelCards($this->dom($page))->each(function (Crawler $card) use (&$offenders, &$widest, $route, $page): void {
                $card->filter('table')->each(function (Crawler $table) use (&$offenders, &$widest, $route, $page): void {
                    $headerRows = $this->rowWidths($table, 'thead');

                    if ($headerRows === []) {
                        return; // No header: nothing declares how wide the table is meant to be.
                    }

                    $columns = max(array_column($headerRows, 'width'));
                    $widest = max($widest, $columns);

                    foreach ([...$headerRows, ...$this->rowWidths($table, 'tbody')] as $row) {
                        if ($row['width'] === $columns) {
                            continue;
                        }

                        $offenders[$route] = trim(($offenders[$route] ?? '') . sprintf(
                            "\n  %s (%s) — the header makes this table %d columns wide, but a <%s>"
                            . ' row%s spans %d',
                            $route,
                            $page['path'],
                            $columns,
                            $row['section'],
                            $row['class'] === '' ? '' : ' class="' . $row['class'] . '"',
                            $row['width'],
                        ));
                    }
                });
            });
        }

        // A width of zero everywhere would agree with itself perfectly and assert nothing. The
        // widest admin grid is the 23-column product detail table; anything under ten columns means
        // the walk above stopped seeing cells, not that the screens got narrower.
        $I->assertGreaterThan(
            10,
            $widest,
            'The widest grid measured is only ' . $widest . ' columns, so the row walk is no longer'
            . ' finding cells and every comparison above is between zeroes.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These grids have a row that does not span the whole table, so a cell sits under the"
            . " wrong header or a message stops short of the right edge:",
        );
    }

    /**
     * A filter form can be submitted with the keyboard, with JavaScript off.
     *
     * HTML only submits a form on Enter — implicit submission — when it has a submit button, or
     * when it has exactly one text field. A GET filter form with two or more boxes and no button
     * has neither, so pressing Enter does nothing at all and the filters cannot be applied without
     * a mouse and a script. `/admin/bundles/warehouse-ops/pick-lists` shipped four boxes and no
     * button.
     *
     * Fields declared `form="…"` count: the grid model routinely puts the filter inputs in the
     * table's own filter row and the button in the footer, outside the `<form>` element itself.
     */
    public function everyFilterFormSubmitsWithoutJavascript(FunctionalTester $I): void
    {
        $offenders = [];
        $subjects = 0;

        foreach ($this->gridScreens($I) as $route => $page) {
            $crawler = $this->dom($page);

            $crawler->filter('form')->each(function (Crawler $form) use (&$offenders, &$subjects, $route, $page, $crawler): void {
                if (strtolower((string) ($form->attr('method') ?? 'get')) !== 'get') {
                    return; // The filter form is the GET one; a POST form is an editor, not a filter.
                }

                if ($this->controlCount($form, $crawler, 'text') < 2) {
                    return; // One text field still submits implicitly; zero has nothing to submit.
                }

                ++$subjects;

                if ($this->controlCount($form, $crawler, 'submit') > 0) {
                    return;
                }

                $offenders[$route] = sprintf(
                    '%s (%s) — GET form#%s has %d text fields and no submit button',
                    $route,
                    $page['path'],
                    (string) $form->attr('id') ?: '(none)',
                    $this->controlCount($form, $crawler, 'text'),
                );
            });
        }

        $I->assertGreaterThan(20, $subjects, 'Only ' . $subjects . ' multi-box GET filter forms found across every list screen; the rule has stopped finding the forms it is about.');

        $this->assertOnly($I, self::KNOWN_OFFENDERS, $offenders, 'These filter forms cannot be submitted by pressing Enter, and with JavaScript off that is the only way to submit them:');
    }

    /**
     * No `<form>` is nested inside another `<form>`.
     *
     * Invalid HTML with a silent failure mode: every browser drops the inner form and reparents its
     * controls onto the outer one, so a row's Delete button submits the filter form instead —
     * pointing at the filter action, carrying the filter fields, and not deleting anything. Core
     * works around it by leaving the filter `<form>` empty and pointing the row controls at it by
     * `form="…"` id (see `templates/admin/unit_of_measure/index.html.twig`).
     *
     * Read off the raw response, not the parsed DOM, because the parser drops the inner form too —
     * asking a DOM for `form form` is asking the thing that already discarded the evidence.
     */
    public function noFormIsNestedInsideAnotherForm(FunctionalTester $I): void
    {
        $offenders = [];
        $opened = 0;

        foreach ($this->screens($I) as $route => $page) {
            $depth = 0;
            $source = (string) preg_replace(['#<script\b.*?</script>#is', '#<!--.*?-->#s'], '', $page['html']);

            foreach ($this->matchAll('#</?form\b#i', $source) as $tag) {
                if (str_starts_with($tag, '</')) {
                    $depth = max(0, $depth - 1);

                    continue;
                }

                ++$depth;
                ++$opened;

                if ($depth > 1) {
                    $offenders[$route] = sprintf('%s (%s)', $route, $page['path']);
                }
            }
        }

        $I->assertGreaterThan(100, $opened, 'Only ' . $opened . ' <form> tags seen across every admin screen. The scan is no longer reading the markup it is meant to.');

        $this->assertOnly($I, self::KNOWN_OFFENDERS, $offenders, 'These screens nest a <form> inside another <form>. The browser drops the inner one and its buttons submit the outer form instead:');
    }

    /**
     * A list screen is IN the grid model, or it is named and argued for.
     *
     * ## The loophole this closes
     *
     * Every other rule in this file is asked of `gridScreens()` — the pages with a
     * `.content-frame > .table-card`. So the way to pass all of them was never to have one. That is
     * backwards twice over: the screens most in need of the conventions are the ones that never
     * took the shape, and the CSS that makes a grid fill its page keys off the very class they are
     * missing, so an unconformed list is also a narrow one. PLAIN was an exemption granted for
     * non-conformance.
     *
     * Here a page that reads as a list and carries no `.table-card` is a FINDING. It may still be
     * excused — some pages tabulate rows without being a grid of them — but only by a named entry
     * in `PLAIN_LISTS` with a reason, asserted by `theNamedExclusionsAreStillTrue()` to still be
     * true. The exemption therefore cannot rot: conform the screen and this file fails until the
     * entry goes with it.
     *
     * ## What counts as evidence, and why it is not the markup
     *
     * Two readings of what the page DID, either sufficient, both spelled out on `listEvidence()`:
     * it paged a table, or it rendered an empty state over one. Deliberately nothing about the
     * classes the screen chose — those are what is under test, and a rule that decided "is this a
     * list" from the markup would excuse exactly the screens that skipped it.
     *
     * ## Why EMBEDDED is not swept up with PLAIN
     *
     * A page with `.table-card`s that are not top-level is in the model already: the scroll-region
     * rule, the nested-form rule and the classification guard all reach it, and the dashboard's two
     * recent-activity panels are panels rather than a grid. What this rule is about is the page
     * with no `.table-card` anywhere, which is the one shape nothing in this file could see.
     */
    public function everyListScreenIsInTheGridModel(FunctionalTester $I): void
    {
        $lists = 0;
        $paged = 0;
        $emptyStates = 0;
        $offenders = [];

        foreach ($this->screens($I) as $route => $page) {
            $evidence = $this->listEvidence($page);

            if ($evidence === []) {
                continue;
            }

            ++$lists;
            $page['pagedTables'] === [] ? null : ++$paged;
            $this->emptyStateWidths($this->dom($page)) === [] ? null : ++$emptyStates;

            if ($page['cards'] > 0) {
                continue; // In the table-card model; the rules above reach it.
            }

            $offenders[$route] = sprintf(
                '%s (%s) — %s, so it is a list; but it carries no .table-card, so the CSS that makes'
                . ' a grid fill its page never reaches it and neither does any other rule in this'
                . ' file',
                $route,
                $page['path'],
                implode(' and ', $evidence),
            );
        }

        // #627's lesson applied to a classifier rather than to a number: "this page is not a list"
        // means nothing unless the same walk can be shown to find the lists that ARE there. Both
        // halves of the evidence can stop matching without anything else in this file failing — a
        // query log that comes back empty, an empty-state markup that gets renamed — and either
        // would leave this rule excusing every screen in the application while still passing green.
        // So each half is floored on its own, well under what it finds today (59 screens read as a
        // list, 43 of them by paging and 56 by an empty state) and well over nothing.
        $I->assertGreaterThan(
            40,
            $lists,
            'Only ' . $lists . ' admin screens read as a list at all. The evidence has stopped'
            . ' finding the grids that are there, so "this page is not a list" below is being'
            . ' decided by a walk that matches nothing.',
        );

        $I->assertGreaterThan(
            30,
            $paged,
            'Only ' . $paged . ' screens were seen to count and then page a table. The query log is'
            . ' no longer being read, and half the evidence this rule rests on is gone.',
        );

        $I->assertGreaterThan(
            40,
            $emptyStates,
            'Only ' . $emptyStates . ' screens were seen to render an empty state. The other half of'
            . ' the evidence has stopped matching the markup it is about.',
        );

        $this->assertOnly(
            $I,
            self::PLAIN_LISTS,
            $offenders,
            "These pages list rows from the database and are not in the grid model, so every"
            . " convention in this file passes over them and the full-height clamp on"
            . " .content-frame > .table-card never applies:",
            'PLAIN_LISTS',
        );
    }

    /**
     * Guards the discovery and the classification themselves.
     *
     * Two failure modes a conformance test has to rule out before its other assertions mean
     * anything. A sweep that quietly stops finding screens passes instantly and proves nothing —
     * the same failure `BundlesOffPairingTest` exists to catch. And a page the classification
     * cannot place must fail here rather than fall out of the sweep unnoticed, since falling out is
     * exactly how a screen skips a convention forever.
     */
    public function everyAdminScreenAnswersAndClassifies(FunctionalTester $I): void
    {
        $pages = $this->pages($I);

        $I->assertNotEmpty($pages, 'No admin routes discovered at all — the router or the /admin prefix changed under this test.');

        $broken = [];
        $unclassifiable = [];

        foreach ($pages as $route => $page) {
            if ($page['code'] !== 200) {
                $broken[$route] = sprintf(
                    '%s (%s) answered %s',
                    $route,
                    $page['path'],
                    $page['code'] === 0 ? 'a status this sweep does not recognise' : (string) $page['code'],
                );

                continue;
            }

            if (!str_contains($page['contentType'], 'text/html')) {
                continue; // A CSV template or a JSON endpoint, and it says so itself.
            }

            if ($page['framed']) {
                continue; // GRID, EMBEDDED or PLAIN — the three the assertions above sort out.
            }

            if ($page['cards'] === 0) {
                continue; // UNFRAMED: the login and password pages, which have no screen chrome.
            }

            $unclassifiable[$route] = sprintf(
                '%s (%s) renders grid markup with no .content-frame around it, so neither reading of'
                . ' the page applies to it',
                $route,
                $page['path'],
            );
        }

        $this->assertOnly($I, [], $broken, 'These admin screens do not answer 200, so the conventions below were never asserted against them:');
        $this->assertOnly($I, [], $unclassifiable, 'These pages cannot be classified as a list screen or as anything else:');

        $I->assertGreaterThan(
            30,
            count($this->gridScreens($I)),
            'The sweep found almost no list screens. Either the .content-frame > .table-card model'
            . ' was abandoned wholesale, or the discovery stopped working and every assertion in'
            . ' this file is now passing over nothing.',
        );
    }

    /**
     * The subjects of the four compositional rules below, and the guard on how they were found.
     *
     * Every entity implementing `CommercialDocument` gets exactly one list screen, or is named in
     * `NO_LIST_SCREEN` with a reason. Two documents mapping onto one screen, or one document onto
     * two, is reported rather than silently resolved: the mapping is a discovery, and a discovery
     * that has become ambiguous has stopped being evidence.
     */
    public function everyCommercialDocumentHasOneListScreen(FunctionalTester $I): void
    {
        $documents = $this->documentScreens($I);

        // Nine implementers today. Under eight means the metadata walk or the `is_a` test stopped
        // finding documents, and every rule below is then asserting over a shorter list than the
        // application actually has.
        $I->assertGreaterThan(
            7,
            count($documents),
            'Only ' . count($documents) . ' entities implementing ' . CommercialDocument::class
            . ' were found. The contract, the mapping or this walk over it has changed, and the'
            . ' compositional rules below are now asserted against whatever is left.',
        );

        $unlisted = [];
        $ambiguous = [];

        foreach ($documents as $class => $found) {
            if (count($found['routes']) === 1) {
                continue;
            }

            if ($found['routes'] === []) {
                if (isset(self::NO_LIST_SCREEN[$class])) {
                    continue;
                }

                $unlisted[$class] = sprintf(
                    '%s (%s) — no admin grid runs a paged query over this document',
                    $class,
                    $found['table'],
                );

                continue;
            }

            $ambiguous[$class] = sprintf(
                '%s (%s) — %s each page over it, so which one is "the %s list" is not decidable'
                . ' from the application',
                $class,
                $found['table'],
                implode(' and ', $found['routes']),
                $found['table'],
            );
        }

        // The mapping has to be injective as well as total. Two documents resolving to one screen
        // would leave documentGrids() holding whichever was sorted last, and the other document
        // would drop out of all four rules below without anything saying so.
        $shared = [];

        foreach ($documents as $class => $found) {
            foreach ($found['routes'] as $route) {
                $shared[$route][] = $class;
            }
        }

        foreach ($shared as $route => $classes) {
            if (count($classes) < 2) {
                continue;
            }

            $ambiguous[$route] = sprintf(
                '%s pages over %s, so it is the list screen of no one of them in particular',
                $route,
                implode(' and ', $classes),
            );
        }

        $this->assertOnly(
            $I,
            [],
            $unlisted,
            "These commercial documents have no list screen, and no entry saying so:",
        );
        $this->assertOnly(
            $I,
            [],
            $ambiguous,
            "These commercial documents are paged by more than one admin grid, so the entity-to-screen"
            . " mapping this file is built on is no longer a function:",
        );

        $I->assertGreaterThan(
            6,
            count($this->documentGrids($I)),
            'Only ' . count($this->documentGrids($I)) . ' document list screens were mapped. Eight'
            . ' are expected; below that the four rules in this file are passing over screens they'
            . ' can no longer find.',
        );
    }

    /**
     * A document list opens on a status filter bar.
     *
     * `/admin/order` is the reference: All Orders / Draft / Approved / Partially Invoiced /
     * Invoiced / Closed, rendered as links back to the same screen, each carrying one status in
     * `filters[status]` and the first carrying none. A document's life IS its status — that is the
     * whole difference between a document and a row of master data — so "show me the ones that are
     * still open" is the first question anybody asks of a grid of them, and on a conforming screen
     * it costs one click and no typing.
     *
     * Asserted as behaviour, not as a class name: two or more links that point back at this very
     * screen, sit under one parent, and differ in the status they filter by. A `<select>` in the
     * filter row is not this — it is a per-column filter, which is the next rule — and neither is a
     * lone status link somewhere in the page furniture.
     */
    public function everyDocumentListOpensOnAStatusFilterBar(FunctionalTester $I): void
    {
        $tabs = 0;
        $offenders = $this->statusBarOffenders($I, $tabs);

        // Order, invoice and quote carry five or six each. Under eight across every document list
        // means the href walk has stopped reading the bars that are there, and the failures below
        // would then be reporting screens that are fine.
        $I->assertGreaterThan(
            8,
            $tabs,
            'Only ' . $tabs . ' status filter links were found across every document list. The walk'
            . ' over the links is no longer finding the bars it is about, so this rule is failing'
            . ' screens rather than checking them.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These document lists have no status filter bar, so narrowing the grid to the documents"
            . " that are still live costs a typed filter instead of one click:",
        );
    }

    /**
     * A document grid filters column by column, from a row under its own header.
     *
     * `templates/admin/company/index.html.twig` lines 59-63 and `/admin/order`'s own filter row are
     * the shape: a `<tr>` in the `<thead>` whose cells carry the control that filters the column
     * above each of them. It is not decoration — it is what makes the header honest, because a
     * filter cell that has drifted one column off its header files a serial number under Qty and
     * the page still looks right. `everyGridRowSpansTheWholeTable()` above already holds that row
     * to the header's width; this holds the screen to having one at all.
     *
     * How many columns are filterable is a question about the data, not about the markup, so the
     * rule cannot be "one input per filterable column" and is not written as one. It is: the
     * filters are IN the header, and there are at least two of them. A single search box above the
     * table — which is what the credit note and sales return grids offer — is the thing this
     * distinguishes itself from, and it is also a filter nobody can aim at a column.
     */
    public function everyDocumentGridFiltersColumnByColumn(FunctionalTester $I): void
    {
        $controls = 0;
        $offenders = $this->columnFilterOffenders($I, $controls);

        $I->assertGreaterThan(
            15,
            $controls,
            'Only ' . $controls . ' per-column filter controls were found across every document'
            . ' grid header. The selector has stopped matching the filter rows that exist, so the'
            . ' failures below are about the walk and not about the screens.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These document grids have no per-column filter row, so every column they render is a"
            . " column nobody can narrow:",
        );
    }

    /**
     * A document row offers one control in its Actions cell, not a row of flat buttons.
     *
     * `templates/admin/company/_list_rows.html.twig` lines 21-32 is the shape: one
     * `button.table-action.row-action-toggle` opening a `div.row-action-dropdown` that holds every
     * action the row offers. `/admin/order` and `/admin/invoice` do the same. The purchase order
     * and vendor bill grids instead render View, Edit and Cancel (or Void) as three flat
     * `.table-action` controls in the cell itself, which is how an Actions column grows without
     * anybody deciding it should: each new action is one more `<a>` and the column is one more
     * inch wide.
     *
     * So: a cell carrying a `row-action-toggle` conforms, whatever is inside the dropdown, and a
     * cell without one may hold at most one control. One plain link is not a row of buttons and is
     * left alone deliberately — the quote, credit note, sales return and debit memo grids each
     * offer exactly one — but the second action is where the dropdown has to appear.
     *
     * ## Why this reads the template and not the response
     *
     * Because an Actions cell needs a row, and these screens are fetched against an empty database
     * (see the class docblock: seeding a document per grid would make this a fixture suite
     * pretending to be a conformance test). What is asserted is therefore the markup the screen
     * WOULD render for a row, taken from the templates that route actually rendered — discovered
     * from the profiler's Twig collector, not guessed from the route name — with the Twig
     * statements stripped so that every branch of an `{% if %}` is counted, which is the right
     * reading: a cell that renders a third control only for Draft documents still renders three.
     *
     * What it costs is that this sees the template rather than the DOM, so a conditional that can
     * never be true counts anyway. That is the cheaper error of the two.
     */
    public function everyDocumentRowOffersOneActionsControl(FunctionalTester $I): void
    {
        $cells = 0;
        $dropdowns = 0;
        $offenders = $this->rowActionOffenders($I, $cells, $dropdowns);

        $I->assertGreaterThan(
            5,
            $cells,
            'Only ' . $cells . ' Actions cells were found in the templates the document lists'
            . ' render. Either the data-label="Actions" convention has been abandoned or the'
            . ' template discovery has stopped working, and this rule is now checking nothing.',
        );

        // #627: the absence half of this rule — "no cell holds two flat controls" — is worth
        // nothing unless the same walk can be shown to find the control it is looking for.
        $I->assertGreaterThan(
            0,
            $dropdowns,
            'Not one document list was found to use a row-action dropdown, so "this cell has no'
            . ' dropdown" below is being decided by a selector that never matches anything.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These document rows spread their actions across flat controls instead of collecting them"
            . " into one row-action dropdown:",
        );
    }

    /**
     * A document list carries a create button, in the header, wherever the document can be created
     * at all.
     *
     * ## The rule, and why it is not a hardcoded exception
     *
     * Not every document is raised blank. An invoice today can only be raised from an approved
     * sales order, so `InvoiceController` publishes no create screen at all — only
     * `/admin/invoice/create (order_id)`, which needs the order. A credit note and a sales return DO
     * publish a parameterless `/new`, and both refuse it: opened with no customer they flash "A
     * credit note needs a customer" / "A return is raised against a customer" and redirect back to
     * the list. Putting a Create button on any of those three would offer an act the application
     * declines to perform.
     *
     * So the rule is asked of the application rather than written down here: **a document list
     * needs a create button exactly when the application publishes a screen that raises that
     * document from nothing** — a parameterless GET route on the list's own controller whose last
     * path segment is `new` or `create`, and which answers 200 on its own URL rather than
     * bouncing off to somewhere else. Nothing in this file names a document as exempt; the
     * exemption is a fact about the routes, re-read on every run. The day somebody gives invoices a
     * standalone create screen, this test requires the invoice list to link to it, without anybody
     * remembering to come back here.
     *
     * The button is held to the header panel's `.panel-actions`, not merely to existing somewhere:
     * that group is where `/admin/order` and `/admin/company` put it, and it is what "top right"
     * means in this application's markup. The purchase order, vendor bill and debit memo lists all
     * have the link and all put it in a bare `.panel` of its own below the heading.
     */
    public function everyDocumentListLinksItsOwnCreateScreen(FunctionalTester $I): void
    {
        $creatable = 0;
        $linked = 0;
        $offenders = $this->createButtonOffenders($I, $creatable, $linked);

        $I->assertGreaterThan(
            3,
            $creatable,
            'Only ' . $creatable . ' document lists were found to have a create screen behind them.'
            . ' Five do. Below that the route walk has stopped recognising them and this rule is'
            . ' excusing screens rather than checking them.',
        );

        // #627 again: "no link to the create screen in .panel-actions" means nothing unless that
        // selector is shown to find the link where it IS in the right place.
        $I->assertGreaterThan(
            0,
            $linked,
            'Not one document list was found linking its create screen from the header panel\'s'
            . ' .panel-actions, so the failures below are a selector that matches nothing rather'
            . ' than screens that are missing a button.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These document lists do not offer their own create screen from the header, though the"
            . " application publishes one:",
        );
    }

    /**
     * A document list shows the document's STATUS as a column of its own.
     *
     * The fifth compositional rule, and the one queue item 41 is about: the owner ruled on
     * 2026-09-11 that "status shoudl be in tables and on the detail page, big and loud". A document
     * IS its status — that is the same sentence
     * `everyDocumentListOpensOnAStatusFilterBar()` above is built on — so a grid of documents that
     * does not put it in a column is asking the reader to open each row to find out which of them
     * are still live. The filter bar rule does not cover this: a bar narrows the grid to one status
     * and says nothing about what the rows that come back are in.
     *
     * Two halves, because a grid renders against an empty database here (see the class docblock):
     *
     *  - the HEADER, read off the response: exactly one `<th>` in a top-level `.table-card` whose
     *    text is the word Status. Matched exactly rather than by `str_contains`, or the order and
     *    invoice grids' "Payment Status" column would answer for a missing Status one;
     *  - the CELL, read off the templates the route rendered — the same source, and for the same
     *    reason, as `everyDocumentRowOffersOneActionsControl()`: a `<td data-label="Status">`. A
     *    header with no cell under it is precisely the drift this file exists for, and no row
     *    renders here to see it on.
     */
    public function everyDocumentListShowsStatusAsAColumn(FunctionalTester $I): void
    {
        $headers = 0;
        $cells = 0;
        $offenders = $this->statusColumnOffenders($I, $headers, $cells);

        // Eight document grids are mapped, so eight of each is the expected count. Below six and
        // the walk has stopped reading the columns that are there, which would make the list below
        // a broken selector rather than a set of screens.
        $I->assertGreaterThan(
            5,
            $headers,
            'Only ' . $headers . ' Status column headers were found across every document list. The'
            . ' header walk is no longer reading the grids, so this rule is failing screens rather'
            . ' than checking them.',
        );

        $I->assertGreaterThan(
            5,
            $cells,
            'Only ' . $cells . ' Status cells were found in the templates the document lists render.'
            . ' Either the data-label="Status" convention has been abandoned or the template'
            . ' discovery has stopped working, and this rule is now checking nothing.',
        );

        $this->assertOnly(
            $I,
            self::KNOWN_OFFENDERS,
            $offenders,
            "These document lists do not show the document's status as a column, so which of the rows"
            . " are still live cannot be read off the grid at all:",
        );
    }

    /**
     * An exclusion that stopped being true is worse than a missing one: it reads as a considered
     * decision while describing something that is no longer the case.
     *
     * `NOT_FETCHABLE` names a route, so it fails when that route is renamed away. `KNOWN_OFFENDERS`
     * names a screen that is broken today, so it fails when somebody fixes the screen — which is
     * the point. #621 was not allowed to fix them, and the entries are how the next person is told
     * to delete the entry along with the defect. `PLAIN_LISTS` names a list that is not in the grid
     * model, so it fails the moment the screen grows a `.table-card` — bringing a screen in and
     * leaving its exemption behind is not a thing that can be done quietly.
     */
    public function theNamedExclusionsAreStillTrue(FunctionalTester $I): void
    {
        $router = $I->grabService(RouterInterface::class);
        $pages = $this->pages($I);

        foreach (self::NOT_FETCHABLE as $route => $why) {
            $I->assertNotNull(
                $router->getRouteCollection()->get($route),
                sprintf('%s is excluded from the sweep because it %s, but there is no such route any more. Remove the entry.', $route, $why),
            );
        }

        foreach (self::KNOWN_OFFENDERS as $route => $what) {
            $I->assertArrayHasKey(
                $route,
                $pages,
                sprintf('%s is recorded as breaking a list-screen convention (%s), but it is not a route this sweep reaches any more. Remove the entry.', $route, $what),
            );
        }

        foreach (self::PLAIN_LISTS as $route => $why) {
            $I->assertArrayHasKey(
                $route,
                $pages,
                sprintf('%s is recorded as a list outside the grid model (it %s), but it is not a route this sweep reaches any more. Remove the entry.', $route, $why),
            );

            // The content of the exemption: this screen has no .table-card on it. Adopting one is
            // conforming it, and the entry has to go in the same commit — which is what makes this
            // a finding with an argument rather than an allowlist that rots.
            $I->assertSame(0, $pages[$route]['cards'], sprintf(
                '%s is recorded as a list that is NOT in the grid model — it %s — but it now carries'
                . ' a .table-card. Remove the entry from PLAIN_LISTS, so the screen is held to the'
                . ' list-screen conventions from here on.',
                $route,
                $why,
            ));

            // And it still has to read as a list, or the entry is arguing about nothing.
            $I->assertNotSame([], $this->listEvidence($pages[$route]), sprintf(
                '%s is recorded as a list outside the grid model — it %s — but nothing about the'
                . ' page says it lists anything any more: it neither pages a table nor renders an'
                . ' empty state. Either the screen changed or the evidence stopped finding it;'
                . ' check which, and remove the entry if the page is no longer a list.',
                $route,
                $why,
            ));
        }

        $documents = $this->documentScreens($I);

        foreach (self::NO_LIST_SCREEN as $class => $why) {
            // An optional bundle can be deleted outright, and an entry for a document that is
            // no longer mapped describes nothing rather than describing something stale.
            if (!isset($documents[$class])) {
                continue;
            }

            $I->assertSame([], $documents[$class]['routes'], sprintf(
                '%s is recorded as having no list screen of its own — it %s — but %s now pages'
                . ' over it. Remove the entry, so the screen is held to the document list'
                . ' conventions from here on.',
                $class,
                $why,
                implode(' and ', $documents[$class]['routes']),
            ));
        }

        // The assertions above report their offenders against KNOWN_OFFENDERS, so a screen that has
        // been fixed disappears from their output rather than failing them. This is where that is
        // caught: every named offender has to still be found by at least one of them.
        $stillBroken = array_merge(
            $this->pagerOffenders($I),
            $this->columnOffenders($I),
            array_keys($this->statusBarOffenders($I)),
            array_keys($this->statusColumnOffenders($I)),
            array_keys($this->columnFilterOffenders($I)),
            array_keys($this->rowActionOffenders($I)),
            array_keys($this->createButtonOffenders($I)),
        );

        foreach (self::KNOWN_OFFENDERS as $route => $what) {
            $I->assertContains(
                $route,
                $stillBroken,
                sprintf(
                    "%s is listed as a known offender — %s — but nothing in this file finds it broken"
                    . " any more. If it was fixed, delete its entry from KNOWN_OFFENDERS so the"
                    . " screen is held to the convention from now on.",
                    $route,
                    $what,
                ),
            );
        }
    }

    // ── Discovery ───────────────────────────────────────────────────────────────────────────────

    /**
     * Every parameterless admin GET route, fetched once as a logged-in admin.
     *
     * Cached for the lifetime of the process: the sweep is 150-odd requests and every test method
     * in this file asks the same question of the same responses. The cache holds parsed pages, not
     * database state, so it is unaffected by the per-test rollback that empties the fixtures
     * between methods.
     *
     * @return array<string, array{path: string, code: int, contentType: string, html: string, framed: bool, cards: int, topCards: int, url: string, pagedTables: list<string>, templates: list<string>}>
     */
    private function pages(FunctionalTester $I): array
    {
        if (self::$pages !== null) {
            return self::$pages;
        }

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('list-screen-conventions@example.test');
        // Every role the admin firewall knows about, so a screen is never classified as "not there"
        // when the truth is that this user could not see it.
        $admin->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_SUPERADMIN', 'ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $pages = [];
        $queryLog = $this->queryLog($I);

        foreach ($I->grabService(RouterInterface::class)->getRouteCollection() as $route => $definition) {
            $path = $definition->getPath();

            if (!str_starts_with($path, '/admin')) {
                continue;
            }

            // A route with a parameter needs a fixture to fill it; see the class docblock.
            if (str_contains($path, '{')) {
                continue;
            }

            $methods = $definition->getMethods();

            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }

            if (isset(self::NOT_FETCHABLE[$route])) {
                continue;
            }

            // Doctrine's query log is per-process and never cleared by anything else in this
            // suite, so it is emptied here and read back below: what is left is exactly what
            // this one request ran. See documentScreens(), which is the only reader.
            $queryLog?->reset();

            $I->amOnPage($path);

            // The module asserts a status code but will not hand one over, and one route answering
            // 500 must not abort the sweep before the other hundred and fifty are looked at. So the
            // code is narrowed down by asking, and a 0 means "none of these", which is reported as
            // loudly as a 500 would be.
            $code = 0;
            foreach ([200, 301, 302, 400, 403, 404, 422, 500] as $candidate) {
                try {
                    $I->seeResponseCodeIs($candidate);
                    $code = $candidate;

                    break;
                } catch (\Throwable) {
                    // Not this one; keep asking.
                }
            }

            $html = $I->grabPageSource();
            $contentType = (string) $I->grabResponseHeader('Content-Type');
            // Where the browser ended up, which is not always where it was sent: a create
            // screen that refuses to open blank redirects, and the module follows it. See
            // createScreenFor(), which is the one rule that turns on the difference.
            $landedOn = (string) $I->grabFromCurrentUrl();
            $pagedTables = $this->pagedTablesFrom($queryLog);
            $templates = $this->renderedTemplates($I);
            $crawler = new Crawler($html);
            $html5 = str_contains($contentType, 'text/html');

            // The classification counts are taken here and the parsed document is then dropped. A
            // hundred and fifty retained DOMDocuments would sit in memory for the rest of the run,
            // and the Functional suite is one PHP process whose ceiling is already a stated
            // problem (see codeception.yml). The markup is kept as a string and re-parsed by
            // whichever assertion needs it, which costs milliseconds and no residency.
            $pages[$route] = [
                'path' => $path,
                'code' => $code,
                'contentType' => $contentType,
                'html' => $html,
                'framed' => $html5 && $crawler->filter('.content-frame')->count() > 0,
                'cards' => $html5 ? $crawler->filter('.table-card, .table-scroll-region')->count() : 0,
                'topCards' => $html5 ? $crawler->filter('.content-frame > .table-card')->count() : 0,
                'url' => $landedOn,
                'pagedTables' => $pagedTables,
                'templates' => $templates,
            ];
        }

        return self::$pages = $pages;
    }

    /**
     * @param array{html: string, ...} $page
     */
    private function dom(array $page): Crawler
    {
        return new Crawler($page['html']);
    }

    /**
     * The pages that render admin chrome at all — everything the conventions could apply to.
     *
     * @return array<string, array{path: string, code: int, contentType: string, html: string, framed: bool, cards: int, topCards: int, url: string, pagedTables: list<string>, templates: list<string>}>
     */
    private function screens(FunctionalTester $I): array
    {
        return array_filter(
            $this->pages($I),
            static fn (array $page): bool => $page['code'] === 200 && $page['framed'],
        );
    }

    /**
     * The list screens: pages whose grid IS the page, by the application's own CSS definition.
     *
     * @return array<string, array{path: string, code: int, contentType: string, html: string, framed: bool, cards: int, topCards: int, url: string, pagedTables: list<string>, templates: list<string>}>
     */
    private function gridScreens(FunctionalTester $I): array
    {
        return array_filter(
            $this->screens($I),
            static fn (array $page): bool => $page['topCards'] > 0,
        );
    }

    /**
     * What says this page is a list of rows out of the database, in the page's own words.
     *
     * Two independent readings, and the returned list is the ones that fired — empty means "not a
     * list", and whatever is in it is quoted back in the failure so that a person can check the
     * claim rather than take it.
     *
     *  1. **It paged a table.** `pagedTablesFrom()` already watched this request ask how many rows
     *     there are and then fetch one page of them; see that method for why both halves are
     *     needed. Nothing here is new, it is simply being asked of every screen rather than only of
     *     the ones that list a commercial document.
     *  2. **It rendered an empty state.** A `<table>` whose header declares two or more columns and
     *     whose body holds one row of one cell spanning all of them. The sweep runs against an
     *     empty database, so that is the `{% else %}` arm of a loop over rows that were not there —
     *     which no layout table, form grid or key/value sheet produces. Two columns minimum,
     *     because a one-column table with one cell in it is not evidence of anything.
     *
     * The single case this cannot tell apart is a table looping over something that is not a
     * database row at all — the receiving scan screen's tally lives in the URL — and that is said
     * out loud in that screen's `PLAIN_LISTS` entry rather than patched around here.
     *
     * @param array{html: string, pagedTables: list<string>, ...} $page
     * @return list<string>
     */
    private function listEvidence(array $page): array
    {
        $evidence = [];

        if ($page['pagedTables'] !== []) {
            $evidence[] = 'it counted and then paged ' . implode(' and ', $page['pagedTables']);
        }

        $widths = $this->emptyStateWidths($this->dom($page));

        if ($widths !== []) {
            $evidence[] = sprintf(
                'it rendered an empty state across %s columns, which is a loop over rows the'
                . ' database did not have',
                implode(' and ', array_map('strval', $widths)),
            );
        }

        return $evidence;
    }

    /**
     * The header width of every table on the page that rendered an empty state.
     *
     * Walked over direct children for the reason `rowWidths()` is: a selector would count a nested
     * table's cells into the outer table's arithmetic. `colspan` is compared against the widest
     * header row, so a grouped header does not make its own empty state look short.
     *
     * @return list<int>
     */
    private function emptyStateWidths(Crawler $crawler): array
    {
        $widths = [];

        $crawler->filter('table')->each(function (Crawler $table) use (&$widths): void {
            $headerRows = $this->rowWidths($table, 'thead');

            if ($headerRows === []) {
                return; // Nothing declares how wide this table is meant to be.
            }

            $columns = max(array_column($headerRows, 'width'));

            if ($columns < 2) {
                return;
            }

            foreach ($this->childElements($table->getNode(0), 'tbody') as $tbody) {
                foreach ($this->childElements($tbody, 'tr') as $row) {
                    $cells = $this->childElements($row, 'td', 'th');

                    if (count($cells) !== 1) {
                        continue;
                    }

                    if (max(1, (int) ($cells[0]->getAttribute('colspan') ?: 1)) === $columns) {
                        $widths[] = $columns;
                    }
                }
            }
        });

        return $widths;
    }

    private function topLevelCards(Crawler $crawler): Crawler
    {
        return $crawler->filter('.content-frame > .table-card');
    }

    // ── Structure ───────────────────────────────────────────────────────────────────────────────

    /**
     * The element names between a `.table-scroll-region` and the top-level `.table-card` holding
     * it, or null when no such card is above it.
     *
     * @return list<string>|null
     */
    private function chainToTopLevelCard(Crawler $region): ?array
    {
        $chain = [];
        $node = $region->getNode(0)?->parentNode;

        while ($node instanceof \DOMElement) {
            $classes = ' ' . (string) $node->getAttribute('class') . ' ';

            if (str_contains($classes, ' table-card ')) {
                $parent = $node->parentNode;
                $parentClasses = $parent instanceof \DOMElement ? ' ' . $parent->getAttribute('class') . ' ' : '';

                return str_contains($parentClasses, ' content-frame ') ? $chain : null;
            }

            array_unshift($chain, $node->nodeName);
            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * The width of every row in one section of a table, counting `colspan` and carrying `rowspan`
     * down into the rows below exactly as a browser lays the grid out.
     *
     * Without the carry, a grouped header reads narrower on its second row than on its first — the
     * ID and Name columns of the price grid are declared once with `rowspan="2"` and would
     * otherwise look like missing columns.
     *
     * Walked over the DOM directly rather than with a selector, because every selector available
     * here matches descendants: a table nested inside one cell would have its own rows and cells
     * counted into the outer table's arithmetic, and — worse — a selector that matches nothing
     * makes every row zero columns wide, which is not a failure but a silent agreement.
     * `everyGridRowSpansTheWholeTable()` asserts the widths it measured are real for that reason.
     *
     * @return list<array{section: string, class: string, width: int}>
     */
    private function rowWidths(Crawler $table, string $section): array
    {
        $rows = [];
        $carried = [];
        $index = 0;

        foreach ($this->childElements($table->getNode(0), $section) as $sectionNode) {
            foreach ($this->childElements($sectionNode, 'tr') as $tr) {
                $width = $carried[$index] ?? 0;

                foreach ($this->childElements($tr, 'th', 'td') as $cell) {
                    $colspan = max(1, (int) ($cell->getAttribute('colspan') ?: 1));
                    $rowspan = max(1, (int) ($cell->getAttribute('rowspan') ?: 1));
                    $width += $colspan;

                    for ($below = 1; $below < $rowspan; ++$below) {
                        $carried[$index + $below] = ($carried[$index + $below] ?? 0) + $colspan;
                    }
                }

                $rows[] = ['section' => $section, 'class' => $tr->getAttribute('class'), 'width' => $width];
                ++$index;
            }
        }

        return $rows;
    }

    /**
     * @return list<\DOMElement>
     */
    private function childElements(?\DOMNode $node, string ...$names): array
    {
        if (!$node instanceof \DOMNode) {
            return [];
        }

        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->nodeName), $names, true)) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Counts the controls that would submit with this form, including the ones sitting outside it
     * and pointing back by `form="…"` id.
     */
    private function controlCount(Crawler $form, Crawler $page, string $kind): int
    {
        // The types HTML calls text fields — the ones that make implicit submission conditional on
        // a submit button existing.
        $text = 'input:not([type]), input[type="text"], input[type="search"], input[type="email"],'
            . ' input[type="tel"], input[type="url"], input[type="password"], input[type="number"],'
            . ' input[type="date"], input[type="month"], input[type="week"], input[type="time"],'
            . ' input[type="datetime-local"]';
        $submit = 'button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]';

        $selector = $kind === 'text' ? $text : $submit;

        // Descendants that have not been reassigned to a different form by their own form attribute.
        $count = $form->filter($selector)->reduce(static fn (Crawler $node): bool => $node->attr('form') === null)->count();

        $id = (string) $form->attr('id');

        if ($id !== '') {
            $count += $page->filter($selector)->reduce(static fn (Crawler $node): bool => $node->attr('form') === $id)->count();
        }

        return $count;
    }

    private function hasClass(Crawler $node, string $class): bool
    {
        return str_contains(' ' . (string) $node->attr('class') . ' ', ' ' . $class . ' ');
    }

    /**
     * @return list<string>
     */
    private function matchAll(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);

        return $matches[0];
    }

    // ── Reporting ───────────────────────────────────────────────────────────────────────────────

    /**
     * Fails naming every screen that broke the rule, minus the ones already named as broken.
     *
     * `$register` is the constant a deliberate exception belongs in, named in the failure so that
     * the way out is the one this file actually asserts on — `PLAIN_LISTS` for a list outside the
     * grid model, `KNOWN_OFFENDERS` for everything else. A message pointing at the wrong list is an
     * invitation to add an entry nothing checks.
     *
     * @param array<string, string> $known
     * @param array<string, string> $offenders
     */
    private function assertOnly(FunctionalTester $I, array $known, array $offenders, string $why, string $register = 'KNOWN_OFFENDERS'): void
    {
        $unexpected = array_values(array_diff_key($offenders, $known));

        $I->assertSame([], $unexpected, sprintf(
            "%s\n  %s\n\nEither fix the screen, or — if it is deliberate — add it to %s"
            . " with a sentence saying what it does instead. An exclusion with no reason beside it is"
            . " the silence this test exists to break.",
            $why,
            implode("\n  ", $unexpected),
            $register,
        ));
    }

    /**
     * @return list<string>
     */
    private function pagerOffenders(FunctionalTester $I): array
    {
        $offenders = [];

        foreach ($this->gridScreens($I) as $route => $page) {
            $this->topLevelCards($this->dom($page))->each(function (Crawler $card) use (&$offenders, $route): void {
                if ($card->filter('table')->count() === 0) {
                    return;
                }

                if (strtolower((string) $card->attr('data-link-controls')) === 'true') {
                    return;
                }

                if (!$this->hasClass($card, 'no-paginate')) {
                    $offenders[] = $route;
                }
            });
        }

        return $offenders;
    }

    /**
     * @return list<string>
     */
    private function columnOffenders(FunctionalTester $I): array
    {
        $offenders = [];

        foreach ($this->gridScreens($I) as $route => $page) {
            $this->topLevelCards($this->dom($page))->each(function (Crawler $card) use (&$offenders, $route): void {
                $card->filter('table')->each(function (Crawler $table) use (&$offenders, $route): void {
                    $headerRows = $this->rowWidths($table, 'thead');

                    if ($headerRows === []) {
                        return;
                    }

                    $columns = max(array_column($headerRows, 'width'));

                    foreach ([...$headerRows, ...$this->rowWidths($table, 'tbody')] as $row) {
                        if ($row['width'] !== $columns) {
                            $offenders[] = $route;
                        }
                    }
                });
            });
        }

        return $offenders;
    }

    // ── Composition: finding the subjects ───────────────────────────────────────────────────────

    /**
     * Every commercial document, the table it is stored in, and the list screens that page over it.
     *
     * The documents come out of Doctrine's metadata, the same way
     * `EveryDocumentDeclaresItsContractTest` finds its subjects — ask the mapping, never type a
     * class name — filtered to the ones implementing the contract. The mapping from a document to
     * its SCREEN is the part that has no declaration to read, and it is answered by watching what
     * each screen did rather than by matching names: a list screen pages its rows, so the grid that
     * runs a counted or limited query rooted at `purchase_order` IS the purchase order list.
     * Names were the obvious alternative and they do not survive contact with this application —
     * `SalesOrder`'s list is `OrderController::orders`, and `RfqVendorReply` has no controller at
     * all.
     *
     * Only a route that ANSWERED ON ITS OWN PATH can be a document's list screen. The sweep follows
     * redirects, so a route that bounces somewhere else renders the destination's markup and runs
     * the destination's queries: `/admin/credit-memo/new` refuses to open without a customer and
     * redirects to `/admin/credit-memo/index`, which pages `credit_memo` — and crediting the
     * `/new` route with that made two screens page the same document and knocked `CreditMemo` and
     * `SalesReturn` out of the mapping altogether. What a redirect proves is where it went, not
     * what it lists.
     *
     * @return array<class-string, array{table: string, routes: list<string>}>
     */
    private function documentScreens(FunctionalTester $I): array
    {
        if (self::$documentScreens !== null) {
            return self::$documentScreens;
        }

        /** @var EntityManagerInterface $em */
        $em = $I->grabService(EntityManagerInterface::class);

        $documents = [];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass) {
                continue;
            }

            if (!is_a($metadata->getName(), CommercialDocument::class, true)) {
                continue;
            }

            $documents[$metadata->getName()] = $metadata->getTableName();
        }

        ksort($documents);

        $screens = [];

        foreach ($documents as $class => $table) {
            $routes = [];

            foreach ($this->gridScreens($I) as $route => $page) {
                if ($this->pathOf($page['url']) !== $page['path']) {
                    continue; // Bounced somewhere else; the queries are the destination's. See above.
                }

                if (in_array($table, $page['pagedTables'], true)) {
                    $routes[] = $route;
                }
            }

            $screens[$class] = ['table' => $table, 'routes' => $routes];
        }

        return self::$documentScreens = $screens;
    }

    /**
     * The document list screens themselves: one route per document, with the page it rendered.
     *
     * A document the mapping could not resolve to exactly one screen is left out here and reported
     * by `everyCommercialDocumentHasOneListScreen()` instead, so an ambiguity fails once, by name,
     * rather than being asserted against twice or silently dropped from every rule.
     *
     * @return array<string, array{class: class-string, path: string, code: int, contentType: string, html: string, framed: bool, cards: int, topCards: int, url: string, pagedTables: list<string>, templates: list<string>}>
     */
    private function documentGrids(FunctionalTester $I): array
    {
        $pages = $this->pages($I);
        $grids = [];

        foreach ($this->documentScreens($I) as $class => $found) {
            if (count($found['routes']) !== 1) {
                continue;
            }

            $grids[$found['routes'][0]] = ['class' => $class] + $pages[$found['routes'][0]];
        }

        ksort($grids);

        return $grids;
    }

    /**
     * Doctrine's own query log, or null on a build that does not keep one.
     *
     * `config/packages/doctrine.yaml` leaves `profiling` at its default, which is `%kernel.debug%`
     * and therefore on under APP_ENV=test — `debug:config doctrine dbal --env=test` says
     * `profiling: true` — so every statement the application runs is already being recorded by
     * `Doctrine\Bundle\DoctrineBundle\Middleware\DebugMiddleware`. Nothing in the suite read it
     * until now; `config/packages/test/doctrine.yaml` turns off the BACKTRACE half of it for
     * memory, and says in as many words that the collection itself is pure overhead here.
     *
     * Read from the holder rather than from the profiler's `db` collector, which comes back empty
     * in a functional test: `DoctrineDataCollector` reads the same holder, from the container that
     * handled the request — and that is the container this method deliberately does NOT ask. Asked
     * for its query count after a grid renders, the saved profile says 0.
     *
     * ## Why the holder is taken off the connection and not out of the container
     *
     * `$I->grabService('doctrine.debug_data_holder')` returns the WRONG INSTANCE, and returns it
     * silently — an empty log reads exactly like a request that ran no queries. Codeception's
     * Symfony connector reboots the kernel between requests and carries a short list of persistent
     * services across the reboot, the entity manager among them
     * (`Codeception\Lib\Connector\Symfony::rebootKernel()`). So the Connection — and the
     * middleware-wrapped driver built around whichever holder existed when it was created — lives
     * on, while `doctrine.debug_data_holder` is rebuilt fresh with every generation of the
     * container. Every statement goes to the holder the driver closed over; the container hands
     * out one that nothing writes to.
     *
     * The one the application is actually filling is therefore found where it is attached: by
     * walking the live connection's driver decorators down to Symfony's debug driver
     * (`AbstractDriverMiddleware` → `IdleConnection\Driver` → `Debug\Driver` → the PDO driver) and
     * reading the holder it was constructed with. That reaches through a private property of a
     * Symfony class, which is worth saying plainly — but it is the only handle on the object, the
     * alternative is a log that is empty for a reason no failure message would explain, and an
     * upgrade that moves it fails loudly below rather than quietly mapping nothing.
     *
     * Null rather than a failure when there is no such driver in the chain, because the assertion
     * that matters — "every document resolves to exactly one list screen" — then fails on its own
     * and says so in one place instead of a hundred and fifty.
     */
    private function queryLog(FunctionalTester $I): ?DebugDataHolder
    {
        try {
            $driver = $I->grabService(EntityManagerInterface::class)->getConnection()->getDriver();
        } catch (\Throwable) {
            return null;
        }

        // The chain is four deep today. The bound stops a decorator that holds itself from
        // spinning here rather than failing.
        for ($depth = 0; $driver !== null && $depth < 16; ++$depth) {
            if ($driver instanceof DebugDriver) {
                $holder = (new \ReflectionProperty(DebugDriver::class, 'debugDataHolder'))->getValue($driver);

                return $holder instanceof DebugDataHolder ? $holder : null;
            }

            $driver = $this->wrappedDriver($driver);
        }

        return null;
    }

    /**
     * The driver one decorator down, or null at the bottom of the chain.
     *
     * Every DBAL driver middleware holds its inner driver in a property of its own naming —
     * `AbstractDriverMiddleware::$wrappedDriver` is private, so it is only visible on the class
     * that declares it and not on the anonymous subclasses Doctrine builds — so the property is
     * found by TYPE rather than by name, walking up the declaring classes.
     */
    private function wrappedDriver(Driver $driver): ?Driver
    {
        for ($class = new \ReflectionObject($driver); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($driver)) {
                    continue;
                }

                $value = $property->getValue($driver);

                if ($value instanceof Driver) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The tables the request just made PAGED OVER.
     *
     * A grid asks how many rows there are and then fetches one page of them, so a table is counted
     * as paged over when the SAME request ran both a counting query and a limiting query rooted at
     * it. Both halves are load-bearing, and each one alone gets this wrong on a real screen here:
     *
     *  - LIMIT alone would make `/admin/bundles/procurement/exceptions` the purchase order list AND
     *    the vendor bill list, because the three-way match caps each of its two feeds at 200 rows.
     *    It never counts either, because it has no pager — it is a worklist, not a list screen.
     *  - COUNT alone would match a screen that reports how many of something exist without
     *    listing them.
     *
     * A lookup filling a filter dropdown does neither, and a rollup joined onto the rows is not the
     * root of its own query, so neither is mistaken for "this screen lists that".
     *
     * @return list<string>
     */
    private function pagedTablesFrom(?DebugDataHolder $queryLog): array
    {
        if ($queryLog === null) {
            return [];
        }

        $counted = [];
        $limited = [];

        foreach ($queryLog->getData() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) preg_replace('/\s+/', ' ', (string) ($query['sql'] ?? ''));
                $table = $this->rootTableOf($sql);

                if ($table === null) {
                    continue;
                }

                if (preg_match('/\bCOUNT\s*\(/i', $sql)) {
                    $counted[$table] = true;
                }

                if (preg_match('/\bLIMIT\b/i', $sql)) {
                    $limited[$table] = true;
                }
            }
        }

        return array_values(array_intersect(array_keys($counted), array_keys($limited)));
    }

    /**
     * Every Twig template the request just made rendered, partials and layout included.
     *
     * From Symfony's profiler, which Codeception's connector enables on every request in this suite
     * (`vendor/codeception/module-symfony/src/Codeception/Lib/Connector/Symfony.php:67`), so this
     * reads what was recorded anyway and changes nothing about how the application runs. It is what
     * ties a route to the markup it would render for a row — see
     * `everyDocumentRowOffersOneActionsControl()`, which is the one rule that cannot be asserted
     * against a grid with no rows in it.
     *
     * @return list<string>
     */
    private function renderedTemplates(FunctionalTester $I): array
    {
        $token = $I->grabResponseHeader('X-Debug-Token');

        if ($token === '') {
            return [];
        }

        try {
            $profiler = $I->grabService('profiler');
        } catch (\Throwable) {
            return [];
        }

        $profile = $profiler instanceof Profiler ? $profiler->loadProfile($token) : null;

        if ($profile === null || !$profile->hasCollector('twig')) {
            return [];
        }

        $twig = $profile->getCollector('twig');

        return $twig instanceof TwigDataCollector ? array_map('strval', array_keys($twig->getTemplates())) : [];
    }

    /**
     * The table a query is rooted at: the table named after the OUTERMOST `FROM`.
     *
     * Outermost, not first. `/admin/order` is why, and it is the reference screen for every rule in
     * this file, so getting it wrong emptied the mapping rather than bending it:
     * `OrderPaymentRollup` puts a correlated subquery in the select list, so the statement that
     * fetches a page of orders reads
     *
     *     SELECT …, (SELECT AVG(…) FROM invoice i1_ WHERE i1_.sales_order_id = s0_.id) AS sclr_21,
     *            … FROM sales_order s0_ INNER JOIN company …  LIMIT 100
     *
     * and the FIRST `FROM` in it names `invoice`. Taking that one made the order list a screen
     * that limits invoices and counts sales orders — paging neither — so `SalesOrder` mapped to no
     * screen at all, and each of the four rules below quietly stopped being asked of the very
     * screen they were read off.
     *
     * So the candidates are ranked by bracket depth and the shallowest wins. That also keeps what
     * the first-match reading got right: when Doctrine's paginator wraps the whole statement in
     * `SELECT COUNT(*) FROM (…) dctrn_table`, the depth-0 `FROM` is followed by `(` and names no
     * identifier at all, so the only candidates are the real tables inside, and the shallowest of
     * those is still the root.
     *
     * String literals are blanked first, so an apostrophe or a bracket inside a quoted status name
     * cannot throw the depth count off.
     *
     * This is where the entity-to-screen mapping is at its most fragile, and it is worth saying
     * plainly: it reads SQL with a regular expression. What it cannot do is quietly get the wrong
     * answer for long — a screen that stops paging, or a second grid that starts paging the same
     * table, leaves a document with nought or two list screens, and
     * `everyCommercialDocumentHasOneListScreen()` says so by name rather than letting a rule fall
     * silently off a screen.
     */
    private function rootTableOf(string $sql): ?string
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);

        if (!preg_match_all('/[()]|\bFROM\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i', $sql, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $depth = 0;
        $root = null;
        $rootDepth = PHP_INT_MAX;

        foreach ($matches as $match) {
            if ($match[0] === '(') {
                ++$depth;

                continue;
            }

            if ($match[0] === ')') {
                --$depth;

                continue;
            }

            if ($depth < $rootDepth) {
                $rootDepth = $depth;
                $root = $match[1];
            }
        }

        return $root;
    }

    // ── Composition: the rules ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function statusColumnOffenders(FunctionalTester $I, ?int &$headers = null, ?int &$cells = null): array
    {
        $headers = 0;
        $cells = 0;
        $offenders = [];

        /** @var \Twig\Environment $twig */
        $twig = $I->grabService('twig');
        $loader = $twig->getLoader();

        foreach ($this->documentGrids($I) as $route => $page) {
            $headed = 0;

            $this->topLevelCards($this->dom($page))->filter('thead th')->each(function (Crawler $cell) use (&$headed): void {
                // `Payment<br>Status` collapses to "PaymentStatus", which is not this column and
                // must not answer for it — hence an exact comparison on the squeezed text.
                if (strtolower((string) preg_replace('/\s+/', '', $cell->text(''))) === 'status') {
                    ++$headed;
                }
            });

            $headers += $headed;

            $celled = 0;

            foreach ($page['templates'] as $template) {
                if (!$loader->exists($template)) {
                    continue;
                }

                $celled += substr_count($loader->getSourceContext($template)->getCode(), 'data-label="Status"');
            }

            $cells += $celled;

            if ($headed >= 1 && $celled >= 1) {
                continue;
            }

            $offenders[$route] = sprintf(
                '%s (%s) — %s',
                $route,
                $page['path'],
                $headed === 0
                    ? 'no column header on the grid reads Status'
                    : 'a Status header with no <td data-label="Status"> under it in any template it renders',
            );
        }

        return $offenders;
    }

    /**
     * @return array<string, string>
     */
    private function statusBarOffenders(FunctionalTester $I, ?int &$tabs = null): array
    {
        $tabs = 0;
        $offenders = [];

        foreach ($this->documentGrids($I) as $route => $page) {
            /** @var array<string, array<string, true>> $byParent */
            $byParent = [];

            $this->dom($page)->filter('a[href]')->each(function (Crawler $link) use (&$byParent, $page): void {
                $href = (string) $link->attr('href');

                if ($this->pathOf($href) !== $page['path']) {
                    return; // Not a link back to this screen, so not one of its own tabs.
                }

                $status = $this->statusIn($this->queryOf($href));
                $parent = $link->getNode(0)?->parentNode;

                if ($status === null || !$parent instanceof \DOMElement) {
                    return;
                }

                $byParent[(string) $parent->getNodePath()][$status] = true;
            });

            $widest = 0;

            foreach ($byParent as $statuses) {
                $tabs += count($statuses);
                $widest = max($widest, count($statuses));
            }

            if ($widest >= 2) {
                continue;
            }

            $offenders[$route] = sprintf(
                '%s (%s) — %s',
                $route,
                $page['path'],
                $byParent === []
                    ? 'no link on the page narrows the grid to one status'
                    : 'the status links on the page do not sit together, so there is no bar to click along',
            );
        }

        return $offenders;
    }

    /**
     * @return array<string, string>
     */
    private function columnFilterOffenders(FunctionalTester $I, ?int &$controls = null): array
    {
        $controls = 0;
        $offenders = [];

        foreach ($this->documentGrids($I) as $route => $page) {
            $filtered = 0;

            $this->topLevelCards($this->dom($page))->filter('thead th')->each(function (Crawler $cell) use (&$filtered): void {
                if ($cell->filter('input:not([type="hidden"]), select, textarea')->count() > 0) {
                    ++$filtered;
                }
            });

            $controls += $filtered;

            if ($filtered >= 2) {
                continue;
            }

            $offenders[$route] = sprintf(
                '%s (%s) — %d of its column headers carry a filter control, so its columns cannot be'
                . ' narrowed one at a time',
                $route,
                $page['path'],
                $filtered,
            );
        }

        return $offenders;
    }

    /**
     * @return array<string, string>
     */
    private function rowActionOffenders(FunctionalTester $I, ?int &$cells = null, ?int &$dropdowns = null): array
    {
        $cells = 0;
        $dropdowns = 0;
        $offenders = [];

        /** @var \Twig\Environment $twig */
        $twig = $I->grabService('twig');
        $loader = $twig->getLoader();

        foreach ($this->documentGrids($I) as $route => $page) {
            foreach ($page['templates'] as $template) {
                if (!$loader->exists($template)) {
                    continue;
                }

                foreach ($this->actionsCells($loader->getSourceContext($template)->getCode()) as $cell) {
                    ++$cells;

                    if (str_contains($cell, 'row-action-toggle')) {
                        ++$dropdowns;

                        continue;
                    }

                    $flat = preg_match_all('#<(?:a|button)\b#i', $cell);

                    if ($flat <= 1) {
                        continue; // One plain link is not a row of buttons. The second one is.
                    }

                    $offenders[$route] = sprintf(
                        '%s (%s) — %s puts %d controls in one Actions cell with no .row-action-toggle'
                        . ' to collect them behind',
                        $route,
                        $page['path'],
                        $template,
                        $flat,
                    );
                }
            }
        }

        return $offenders;
    }

    /**
     * Every Actions cell a template would render, with its Twig taken out.
     *
     * `{% … %}` is removed rather than evaluated, so both arms of a conditional action are counted:
     * a cell that grows a third control only for Draft documents still renders three, and the
     * convention is about the cell, not about today's data. `{{ … }}` becomes a placeholder rather
     * than being deleted, so that `href="{{ path(…) }}"` stays a well-formed attribute instead of
     * an empty one.
     *
     * @return list<string>
     */
    private function actionsCells(string $twig): array
    {
        $markup = (string) preg_replace(
            ['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s'],
            ['', '', 'x'],
            $twig,
        );

        $cells = [];
        $from = 0;

        while (($at = strpos($markup, 'data-label="Actions"', $from)) !== false) {
            $open = strrpos(substr($markup, 0, $at), '<td');
            $close = strpos($markup, '</td>', $at);

            if ($open === false || $close === false) {
                $from = $at + 1;

                continue;
            }

            $cells[] = substr($markup, $open, $close - $open);
            $from = $close;
        }

        return $cells;
    }

    /**
     * @return array<string, string>
     */
    private function createButtonOffenders(FunctionalTester $I, ?int &$creatable = null, ?int &$linked = null): array
    {
        $creatable = 0;
        $linked = 0;
        $offenders = [];

        foreach ($this->documentGrids($I) as $route => $page) {
            $create = $this->createScreenFor($I, $route);

            if ($create === null) {
                continue; // The application will not raise this document from nothing. See the rule.
            }

            ++$creatable;

            if ($this->linksTo($this->dom($page)->filter('.panel-actions'), $create) > 0) {
                ++$linked;

                continue;
            }

            $offenders[$route] = sprintf(
                '%s (%s) — %s raises one of these from nothing, and this list %s',
                $route,
                $page['path'],
                $create,
                $this->linksTo($this->dom($page), $create) > 0
                    ? 'links to it from outside the header panel\'s .panel-actions, which is where this application puts a create button'
                    : 'does not link to it at all',
            );
        }

        return $offenders;
    }

    /**
     * The screen that raises this list's document from nothing, or null when there is none.
     *
     * A parameterless GET route on the list's own controller whose last path segment is `new` or
     * `create`, which answered 200 **on its own URL**. That last clause is the whole judgement:
     * `/admin/credit-memo/new` and `/admin/sales-return/new` both exist and both refuse to open
     * without a customer, flashing a message and redirecting back to the list — so the application
     * is saying those documents are never raised blank, and neither list is asked for a button that
     * would offer it. `InvoiceController` publishes no such route at all today; the day it does,
     * the invoice list is held to linking it, with nothing in this file to edit.
     */
    private function createScreenFor(FunctionalTester $I, string $listRoute): ?string
    {
        $collection = $I->grabService(RouterInterface::class)->getRouteCollection();
        $pages = $this->pages($I);
        $owner = $this->controllerClassOf($collection->get($listRoute)?->getDefault('_controller'));

        foreach ($collection as $name => $definition) {
            if ($name === $listRoute || !isset($pages[$name])) {
                continue; // Not fetched: parameterised, not a GET, or named in NOT_FETCHABLE.
            }

            if ($this->controllerClassOf($definition->getDefault('_controller')) !== $owner) {
                continue;
            }

            $path = $definition->getPath();

            if (!in_array(substr($path, (int) strrpos($path, '/') + 1), ['new', 'create'], true)) {
                continue;
            }

            if ($pages[$name]['code'] !== 200 || $this->pathOf($pages[$name]['url']) !== $path) {
                continue; // It bounced somewhere else rather than opening a blank document.
            }

            return $path;
        }

        return null;
    }

    private function controllerClassOf(mixed $controller): string
    {
        $controller = is_string($controller) ? $controller : '';

        return strstr($controller, '::', true) ?: $controller;
    }

    private function linksTo(Crawler $within, string $path): int
    {
        if ($within->count() === 0) {
            return 0;
        }

        return $within->filter('a[href]')
            ->reduce(fn (Crawler $link): bool => $this->pathOf((string) $link->attr('href')) === $path)
            ->count();
    }

    private function pathOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOf(string $url): array
    {
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);

        return $query;
    }

    /**
     * The status a link filters by, at whatever depth the screen nests its filters.
     *
     * `/admin/order` writes `filters[status]`, and nothing says the next grid will. What every one
     * of them shares is a parameter called `status` carrying a value, so that is what is looked
     * for — an empty one is the All tab and is not a status.
     *
     * @param array<string, mixed> $query
     */
    private function statusIn(array $query): ?string
    {
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $nested = $this->statusIn($value);

                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (strtolower((string) $key) === 'status' && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
