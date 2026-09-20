<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The admin UI calls the buyer a **Customer**, never a **Company** — and every place it still says
 * "Company" is named here, with the reason it means something other than the buyer.
 *
 * ## What went wrong without this
 *
 * The Company → Customer relabel was done by hand across ~150 strings in two passes. Half-finished
 * is its worst state: the run before this test landed left `config/fulfillment_regions.html.twig`
 * rendering the column header "Default On For New Customer" over a row partial still carrying
 * `data-label="Default On For New Company"` — one screen, two names for one thing, and nothing in a
 * green suite said so. The relabel also has no mechanical definition of done, so the next screen
 * somebody adds is free to say "Company" again and no test will disagree.
 *
 * That is the drift this file exists to stop. It is a source scan, not a page fetch: a screen is
 * covered the moment its template exists, before anybody wires it to a route.
 *
 * ## What counts as user-facing
 *
 * Only text a person reads off the screen or hears from a screen reader:
 *
 *   - text nodes, after Twig comments (`{# #}`), expressions (`{{ }}`) and tags (`{% %}`) are
 *     removed — so variable names, route names, entity names and the reasoning in comments are out
 *     of scope, which is exactly the line the relabel itself was ruled to;
 *   - the values of the attributes a user actually perceives: `aria-label`, `title`, `placeholder`,
 *     `data-label` (the responsive tables print it as the row's column heading), `data-placeholder`,
 *     `alt`, and `value` on anything but a hidden input — a disabled text input is how one of these
 *     screens renders a sentence;
 *   - and the quoted value of a screen-text key inside a Twig tag or expression — see below, that
 *     one is not obvious.
 *
 * Everything else in a tag — `class`, `name`, `id`, `href`, `data-sort-field`, `data-filter-field`
 * — is a symbol, and the ruling on this work was that symbols do not get renamed. `<script>` and
 * `<style>` bodies are skipped for the same reason: their content is code. The one user-facing
 * string that lives inside a `<script>` in this tree (`product/form.html.twig`'s
 * `$trigger.text('Select customers...')`) is therefore NOT guarded here — it is guarded by the
 * matching `Select customers...` in the markup right beside it, which is.
 *
 * ## Labels inside `{% %}` and `{{ }}` ARE scanned
 *
 * Dropping the Twig tags wholesale was right while every label was a text node, and it stopped
 * being right the night the sell-side screens were componentised. `sales_detail_address_panel`,
 * `sales_detail_line_table`, `sales_line_head`/`sales_line_row` and the forms that call them now
 * declare their columns once, as a list of maps, and print them in a loop:
 *
 *     {% set rows = [
 *         {label: 'Company Name', key: 'companyName'},
 *         ...
 *     ] %}
 *
 * Six hand-written `Company Name:` labels became that one `label:` entry — and because the label
 * had moved out of a text node and into a tag, this file stopped being able to see it. The guard
 * went quiet on exactly the screens that had just been rewritten, and said nothing while it did.
 * A quoted string a person reads is user-facing wherever it is written; the delimiters around it
 * are an implementation detail of the template, not a statement about who the words are for.
 *
 * So inside a tag or an expression, the value of a key a person READS is scanned:
 * {@see HUMAN_TWIG_KEYS} names them and {@see HUMAN_TWIG_KEY_ENDINGS} covers the camelCase family,
 * so `submitLabel`, `namePlaceholder`, `quantityColumnLabel`, `emptyMessage` — and a `fooLabel`
 * somebody adds tomorrow — are in without anyone having to remember to extend a list. `{{ }}`
 * counts as well as `{% %}`: `sales_add_line_bar` and `product_field` are handed their button text
 * and their placeholder through `{{ include(..., {submitLabel: '…'}) }}`, and a rule that looked
 * only at `{% %}` would have left that half of the same hole open.
 *
 * The narrowness is the point, and it is keyed on the KEY — never on "a string literal in a tag",
 * which would drag in every route name and break the ruling this file was built to. `key:`,
 * `class:`, `col:`, `field:`, `name:`, `sort:`, `routeName:` and the rest stay symbols, so a
 * fourteen-row `{% set rows = [...] %}` contributes fourteen labels and not one entity field.
 * Two things it still does not see, both on purpose: a bare string in a list
 * (`user/form.html.twig`'s `['Owner', 'Company Staff']` — a persisted role value the controllers
 * validate against, printed as its own `<option>` text, so it is a symbol that happens to be
 * visible), and a `{# #}` comment body, which is stripped before any of this runs because the
 * reasoning about a screen is allowed to go on using the old word.
 *
 * A match must be a whole word, not glued to `-` or `_`: `company_id`, `%company-detail%`,
 * `ss-private-companies` and `you@yourcompany.com` are identifiers and addresses, not labels.
 *
 * ## Why the exceptions carry counts
 *
 * `ALLOWED` is the {@see \Tests\Functional\AdminListScreenConventionsCest} `KNOWN_OFFENDERS`
 * habit: an exclusion that has stopped being true is worse than a missing one, because it reads as
 * a considered decision while describing something that is no longer the case. So the list is
 * asserted from BOTH sides — nothing unlisted may appear, and every listed phrase must still be
 * there, the stated number of times. Adding a second, buyer-meaning "Company Name" to a file that
 * already has an address-meaning one therefore fails, instead of hiding behind its neighbour.
 */
final class AdminTemplatesCallTheBuyerACustomerTest extends TestCase
{
    /** Attributes whose value a person reads. Everything else in a tag is a symbol. */
    private const HUMAN_ATTRIBUTES = [
        'aria-label',
        'title',
        'placeholder',
        'data-label',
        'data-placeholder',
        'alt',
        'value',
    ];

    /**
     * Map keys inside a Twig tag or expression whose quoted value is printed to the screen —
     * `{label: 'Company Name', key: 'companyName'}` is one label and one symbol, not two strings.
     *
     * @var list<string>
     */
    private const HUMAN_TWIG_KEYS = [
        'label',
        'title',
        'heading',
        'header',
        'placeholder',
        'message',
        'text',
        'alt',
        'aria-label',
        'data-label',
    ];

    /**
     * ...and the endings that make one. A key is screen text if it IS one of the names above or
     * ENDS in one of these, which is what carries `submitLabel`, `namePlaceholder`,
     * `pricePlaceholder`, `taxCodeBlankLabel`, `quantityColumnLabel` and `emptyMessage` without
     * naming them, and what will carry the next one nobody thinks to add here.
     *
     * @var list<string>
     */
    private const HUMAN_TWIG_KEY_ENDINGS = [
        'label',
        'title',
        'heading',
        'placeholder',
        'message',
    ];

    /**
     * A `key: 'value'` pair in Twig code: a bare identifier key or a quoted one, then a single- or
     * double-quoted string. The value must be a LITERAL — `label: line.name` and
     * `label: someVariable` do not match, because a string this file cannot read is a string it
     * cannot rule on, and pretending otherwise would put an unfalsifiable entry in ALLOWED.
     */
    private const TWIG_KEYED_STRING =
        '/(?<![\w.$-])(?:([\'"])(?P<quoted>[A-Za-z][\w-]*)\1|(?P<bare>[A-Za-z_]\w*))\s*:\s*'
        . '(?:\'(?P<single>(?:[^\'\\\\]|\\\\.)*)\'|"(?P<double>(?:[^"\\\\]|\\\\.)*)")/';

    /** Whole-word "company"/"companies", not `company_id`, `%company-detail%` or `yourcompany.com`. */
    private const WORD = '/(?<![\w-])compan(?:y|ies)(?![\w-])/i';

    /**
     * Every user-facing "Company" left in templates/admin, why it is not the buyer, and how many
     * times that exact phrase occurs in that file.
     *
     * Two kinds of thing are in here, and they are different:
     *
     *   1. **The seller.** `config/company_info.html.twig` and `config/branding.html.twig` describe
     *      THIS business — the one running the software. Relabelling those would make the app lie
     *      about whose details it is showing, which is worse than leaving a screen unconverted.
     *   2. **Not the buyer's account.** An address's company line, the name of a storefront screen,
     *      and a quoted API error body. Each is spelled out below.
     *
     * @var array<string, array<string, array{int, string}>>
     */
    private const ALLOWED = [
        // ── The seller's own identity ───────────────────────────────────────────────────────────
        'config/company_info.html.twig' => [
            'Company Information | Admin' => [1, "The seller's own name, address and tax numbers. This screen is about this business, not a buyer."],
            'Company Information' => [1, "Heading of the seller's own details screen. Named as a destination elsewhere in the UI ('Settings → Company Information')."],
            'Company Name' => [1, "The seller's registered name, the value printed on every invoice as the issuer."],
        ],
        'config/branding.html.twig' => [
            'Company Name' => [1, "The seller's name in the site header and on outbound documents."],
            'e.g. Your Company Ltd.' => [1, "Placeholder addressed to the admin about their OWN business."],
        ],

        // ── An address's company line, which is not the customer account ────────────────────────
        //
        // This is the organisation line of a postal address. It sits beside First Name and Last
        // Name, the storefront's own address form
        // (templates/customer/company_address/form.html.twig) labels the identical field
        // "Company Name", and on a SHIPPING address it can name a third-party consignee — a
        // receiving depot, a job site — rather than the customer. "Customer Name" there would
        // assert something the data does not carry.
        'company/address_form.html.twig' => [
            'Company Name' => [1, 'Address field: the organisation line of the address being typed.'],
        ],
        'company/_address_card_body.html.twig' => [
            'Company Name' => [1, 'Address field, on the address card.'],
        ],
        'company/detail.html.twig' => [
            'Company Name' => [2, "Address-book table: the column header and its rows' data-label, for the address's own company line."],
        ],
        // One entry where order/detail, invoice/detail and estimate/detail each used to carry
        // "Company Name:" twice. The six read-only address cards became one partial and the twelve
        // hand-written labels became the single `label:` in its row list, so the count is 1 — this
        // is a source scan, and the partial is one source. It is the ORGANISATION LINE of the
        // address the document FROZE at the time it was raised, printed beside First Name and Last
        // Name, and on a shipping snapshot it can name a consignee who is not the buyer at all. It
        // is not the buying account, which this screen calls the Customer in its header.
        '_partials/sales_detail_address_panel.html.twig' => [
            'Company Name' => [1, "Row label in the shared read-only Billing/Shipping Detail card: the company line of the document's frozen address snapshot, not the buying account."],
        ],
        // One entry, not two: the order's and the quote's address editors were byte-identical and
        // became a single partial. The field is a line of a postal ADDRESS — the company the goods
        // are billed or shipped to — which is a different thing from the buying account the rest of
        // the admin calls a Customer, so it keeps the word.
        '_partials/sales_address_cards.html.twig' => [
            'Company name' => [1, 'Field in the shared billing/shipping address editor, beside First name and Last name.'],
        ],
        'order/index.html.twig' => [
            'Company' => [1, 'Second half of the "Shipping Company" column header — the ship-to address\'s company line, not the buying account, which is the "Customer" column beside it.'],
            'Filter shipping company' => [1, 'Filter for that same shipping-address column.'],
        ],
        'order/_list_rows.html.twig' => [
            'Shipping Company Name' => [1, 'Row data-label matching the "Shipping Company" header.'],
        ],

        // ── Names of things that still carry the word ───────────────────────────────────────────
        'help/index.html.twig' => [
            'My Company User' => [1, 'The name of a real storefront screen (templates/customer/company_user/index.html.twig). The buyer-facing site still calls their own organisation "my company"; renaming it here would point admins at a screen that does not exist.'],
            'My Company User › Manage API Access' => [1, 'The same storefront screen, given as a path.'],
            '"Your company\'s API access has been disabled"' => [1, 'Quotes ApiKeyAuthenticator::COMPANY_DISABLED_MESSAGE verbatim so an admin can match a support ticket against it. It can only change when that production constant does.'],
        ],
    ];

    public function testNoAdminTemplateCallsTheBuyerACompany(): void
    {
        $found = $this->scan();
        $unexpected = [];

        foreach ($found as $file => $phrases) {
            foreach ($phrases as $phrase => $count) {
                $allowed = self::ALLOWED[$file][$phrase][0] ?? 0;

                if ($count > $allowed) {
                    $unexpected[] = sprintf(
                        '%s — %s (%d occurrence%s, %d allowed)',
                        $file,
                        $phrase,
                        $count,
                        $count === 1 ? '' : 's',
                        $allowed,
                    );
                }
            }
        }

        sort($unexpected);

        self::assertSame([], $unexpected, sprintf(
            "These admin templates show the word \"Company\" to a user:\n  %s\n\n"
            . "The admin UI calls the buyer a Customer. Relabel the string — text only: no route,"
            . " entity, class, variable, column, app-setting key, CSS class, form field name or"
            . " data-sort-field gets renamed with it.\n\n"
            . "If it does NOT mean the buyer — the seller's own details, an address's company line,"
            . " the name of a storefront screen, a quoted production message — add it to ALLOWED"
            . " with a sentence saying which of those it is. An exclusion with no reason beside it"
            . " is the silence this test exists to break.",
            implode("\n  ", $unexpected),
        ));
    }

    /**
     * The other half: an allowed phrase that is no longer on the screen has to be deleted from the
     * list, or the list slowly becomes a description of a tree that no longer exists.
     */
    public function testEveryAllowedCompanyStringIsStillThere(): void
    {
        $found = $this->scan();
        $stale = [];

        foreach (self::ALLOWED as $file => $phrases) {
            self::assertFileExists(
                $this->adminTemplates() . '/' . $file,
                sprintf('%s is named in ALLOWED but no longer exists. Remove its entry.', $file),
            );

            foreach ($phrases as $phrase => [$count, $why]) {
                self::assertNotSame('', $why, sprintf('%s — %s is allowed with no reason given.', $file, $phrase));

                $actual = $found[$file][$phrase] ?? 0;

                if ($actual !== $count) {
                    $stale[] = sprintf('%s — %s (allowed %d, found %d)', $file, $phrase, $count, $actual);
                }
            }
        }

        sort($stale);

        self::assertSame([], $stale, sprintf(
            "These entries in ALLOWED no longer describe the templates:\n  %s\n\n"
            . "If the string was relabelled or removed, delete its entry so the screen is held to"
            . " \"the buyer is a Customer\" from here on. If it was duplicated, check the new one"
            . " means what the old one means before raising the count.",
            implode("\n  ", $stale),
        ));
    }

    // ── Scanning ────────────────────────────────────────────────────────────────────────────────

    /**
     * Every user-facing phrase containing "company"/"companies", by template and by count.
     *
     * @return array<string, array<string, int>>
     */
    private function scan(): array
    {
        $root = $this->adminTemplates();
        $found = [];

        foreach ($this->templates($root) as $path) {
            $file = substr($path, strlen($root) + 1);

            foreach ($this->userFacingText((string) file_get_contents($path)) as $phrase) {
                $found[$file][$phrase] = ($found[$file][$phrase] ?? 0) + 1;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function templates(string $root): array
    {
        $paths = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The phrases in one template that a person reads, filtered to those naming a company.
     *
     * @return list<string>
     */
    private function userFacingText(string $twig): array
    {
        // Comments go first, everywhere: a `{# #}` block is somebody reasoning about the screen,
        // and the reasoning is allowed to keep saying "company". `<script>`/`<style>` bodies go
        // second — earlier than they used to, so the Twig-code scan below cannot reach into them.
        $source = (string) preg_replace('/\{#.*?#\}/s', ' ', $twig);
        $source = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $source);

        // The labels that live in the code rather than the markup, read BEFORE the code is thrown
        // away. Everything after this line sees a template with no Twig left in it.
        $phrases = $this->labelsInTwigCode($source);

        $source = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $source);
        $source = (string) preg_replace('/\{%.*?%\}/s', ' ', $source);

        preg_match_all('/<[^>]*>/s', $source, $tags);

        foreach ($tags[0] as $tag) {
            $hidden = (bool) preg_match('/type\s*=\s*"hidden"/i', $tag);

            foreach (self::HUMAN_ATTRIBUTES as $attribute) {
                if ($attribute === 'value' && $hidden) {
                    continue;
                }

                $pattern = '/(?<![\w-])' . preg_quote($attribute, '/') . '\s*=\s*"([^"]*)"/i';

                if (preg_match_all($pattern, $tag, $values)) {
                    foreach ($values[1] as $value) {
                        $phrases[] = $value;
                    }
                }
            }
        }

        foreach (preg_split('/<[^>]*>/s', $source) ?: [] as $node) {
            $phrases[] = $node;
        }

        $named = [];

        foreach ($phrases as $phrase) {
            $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));

            if ($phrase !== '' && preg_match(self::WORD, $phrase)) {
                $named[] = $phrase;
            }
        }

        return $named;
    }

    /**
     * The screen text carried inside `{% %}` tags and `{{ }}` expressions: the quoted value of
     * every key a person reads. A column list is a list of labels and a list of symbols sitting in
     * the same braces, and only the labels come back.
     *
     * @return list<string>
     */
    private function labelsInTwigCode(string $source): array
    {
        $labels = [];

        preg_match_all('/\{%.*?%\}|\{\{.*?\}\}/s', $source, $code);

        foreach ($code[0] as $chunk) {
            if (!preg_match_all(self::TWIG_KEYED_STRING, $chunk, $pairs, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($pairs as $pair) {
                $key = ($pair['quoted'] ?? '') !== '' ? $pair['quoted'] : ($pair['bare'] ?? '');

                if (!$this->isHumanKey($key)) {
                    continue;
                }

                $value = ($pair['double'] ?? '') !== '' ? $pair['double'] : ($pair['single'] ?? '');

                // Twig's own escaping, undone: `'It\'s'` is read off the screen as "It's".
                $labels[] = (string) preg_replace('/\\\\(.)/', '$1', $value);
            }
        }

        return $labels;
    }

    /** Is this the name of something a person reads, rather than a symbol? */
    private function isHumanKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::HUMAN_TWIG_KEYS, true)) {
            return true;
        }

        foreach (self::HUMAN_TWIG_KEY_ENDINGS as $ending) {
            if ($key !== $ending && str_ends_with($key, $ending)) {
                return true;
            }
        }

        return false;
    }

    private function adminTemplates(): string
    {
        return dirname(__DIR__, 2) . '/templates/admin';
    }
}
