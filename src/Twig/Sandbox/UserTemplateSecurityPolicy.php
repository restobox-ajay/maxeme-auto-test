<?php

declare(strict_types=1);

namespace App\Twig\Sandbox;

use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;

/**
 * Security policy for Twig sources that did NOT come from the repository — i.e. the
 * EmailTemplate rows and TemplateOverride sources that admins author through the panel
 * and that {@see \App\Twig\SandboxedTemplateRenderer} compiles at runtime.
 *
 * Without a sandbox those sources are ordinary Twig, which means arbitrary PHP callables
 * (`{{ ['id']|map('system') }}` and friends) and therefore OS command execution for anyone
 * who can reach the template editor. Since AdminUser::getRoles() appends ROLE_ADMIN to every
 * admin row, "anyone who can reach the editor" is every admin tier — so this policy is the
 * boundary that stops a low-tier admin account from becoming host compromise.
 *
 * The allowlists are deliberately narrow: they cover what templates/emails/layout.html.twig,
 * templates/emails/_quote_summary.html.twig and the 24 shipped EmailTemplate rows actually
 * use, plus obvious neighbours. Widen them only with a specific template in hand, and never
 * add a filter that accepts a callable (map/filter/reduce/sort) or a function that resolves
 * code by name (constant/template_from_string/source/include/attribute) — those re-open the
 * exact hole this class exists to close.
 */
final class UserTemplateSecurityPolicy implements SecurityPolicyInterface
{
    /**
     * Note the omissions: `autoescape` (would let a template disable output escaping),
     * `do` (statement-position method calls), `use`/`embed` (horizontal reuse we do not need),
     * and `sandbox` itself.
     */
    public const ALLOWED_TAGS = [
        'apply', 'block', 'extends', 'for', 'from', 'if', 'import', 'include', 'macro', 'set', 'verbatim', 'with',
    ];

    /**
     * `map`, `filter`, `reduce` and `sort` are absent on purpose: each accepts an arrow function
     * or a callable string, which Twig resolves to a PHP callable. They are the standard sandbox
     * escape and the reason this policy exists.
     *
     * `raw` is present because an admin authoring the template body can already emit literal
     * HTML, so it grants no capability the editor does not already have.
     *
     * `qty`, `price` and `amount` are {@see \App\Twig\DisplayNumberExtension}'s three formatters,
     * and they are here for a concrete reason rather than for completeness. A body stored in
     * `email_template` is rendered THROUGH this policy, and a body that is only a paragraph is
     * wrapped in `emails/layout.html.twig` — which pulls in `emails/_order_summary.html.twig` and
     * `_quote_summary.html.twig`, the partials that print the lines and totals of the document
     * being sent. Those partials now format with the filters, so without these three entries the
     * order confirmation email fails to render at all. They also grant nothing: each takes a value
     * and returns a string, resolves no callable and walks no object, which is strictly less than
     * `number_format`, `round` and `format` sitting beside them already do.
     */
    public const ALLOWED_FILTERS = [
        'abs', 'amount', 'capitalize', 'date', 'date_modify', 'default', 'escape', 'e', 'first',
        'format', 'join', 'json_encode', 'keys', 'last', 'length', 'lower', 'merge', 'nl2br',
        'number_format', 'price', 'qty', 'raw', 'replace', 'reverse', 'round', 'slice', 'split',
        'striptags', 'title', 'trim', 'upper', 'url_encode',
    ];

    /**
     * `app_setting` is required by templates/emails/layout.html.twig (footer copyright/address);
     * `site_name` by the same layout and other email templates for the store name (issue #118).
     * Deliberately absent: `constant`, `template_from_string`, `source`, `include`, `dump`,
     * `attribute` — each is a direct route back to arbitrary code or filesystem reads.
     */
    public const ALLOWED_FUNCTIONS = [
        'app_setting', 'site_name', 'date', 'max', 'min', 'range', 'path', 'url', 'asset',
    ];

    /**
     * Objects the templates are allowed to read. Anything not matching is refused, which is what
     * keeps a template from walking off a context value into the service container or a
     * Request/kernel object.
     */
    private const ALLOWED_CLASSES = [
        \DateTimeInterface::class,
        \UnitEnum::class,
        \Doctrine\Common\Collections\Collection::class,
        // Read-only snapshot/DTO value objects, not Entities, so the \Entity\ namespace check
        // below misses them. _quote_summary.html.twig reads estimate.companyIdentity.tradeName,
        // taxLine.label/.amount and, for any document with an actual shipping line,
        // shippingLine.label/.amount (estimate.shippingLines returns FeeLine objects directly,
        // unlike the pre-decoded fee_lines context array) — all three refused outright by the
        // sandbox, silently, since EmailNotifier::send() swallows every Throwable. That is why
        // quote_provided/quote_request_received/quote_request_admin/quote_declined_admin/
        // quote_rejected_customer/quote_approved_admin sent zero emails and left zero email_log
        // rows for exactly the documents that happened to exercise the missing branch (#379, #381).
        \App\Model\CompanyIdentity::class,
        \App\Contract\Tax\TaxLine::class,
        \App\Contract\Fee\FeeLine::class,
    ];

    /**
     * @param string[] $allowedTags
     * @param string[] $allowedFilters
     * @param string[] $allowedFunctions
     */
    public function __construct(
        private readonly array $allowedTags = self::ALLOWED_TAGS,
        private readonly array $allowedFilters = self::ALLOWED_FILTERS,
        private readonly array $allowedFunctions = self::ALLOWED_FUNCTIONS,
    ) {
    }

    /**
     * @param string[] $tags
     * @param string[] $filters
     * @param string[] $functions
     * @param string[] $tests
     */
    public function checkSecurity($tags, $filters, $functions/* , array $tests = [] */): void
    {
        foreach ($tags as $tag) {
            if (!\in_array($tag, $this->allowedTags, true)) {
                throw new SecurityNotAllowedTagError(\sprintf('Tag "%s" is not allowed in an admin-authored template.', $tag), $tag);
            }
        }

        foreach ($filters as $filter) {
            if (!\in_array($filter, $this->allowedFilters, true)) {
                throw new SecurityNotAllowedFilterError(\sprintf('Filter "%s" is not allowed in an admin-authored template.', $filter), $filter);
            }
        }

        foreach ($functions as $function) {
            if (!\in_array($function, $this->allowedFunctions, true)) {
                throw new SecurityNotAllowedFunctionError(\sprintf('Function "%s" is not allowed in an admin-authored template.', $function), $function);
            }
        }

        // Tests (null, empty, defined, iterable, ...) are pure predicates with no callable
        // resolution, so they are all permitted.
    }

    public function checkMethodAllowed($obj, $method): void
    {
        if ($this->isReadable($obj)) {
            return;
        }

        throw new SecurityNotAllowedMethodError(
            \sprintf('Calling "%s" on an object of type "%s" is not allowed in an admin-authored template.', $method, get_debug_type($obj)),
            get_debug_type($obj),
            $method,
        );
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        if ($this->isReadable($obj)) {
            return;
        }

        throw new SecurityNotAllowedPropertyError(
            \sprintf('Reading "%s" on an object of type "%s" is not allowed in an admin-authored template.', $property, get_debug_type($obj)),
            get_debug_type($obj),
            $property,
        );
    }

    /**
     * Domain objects are readable; infrastructure objects are not.
     *
     * Entities are matched by namespace rather than enumerated because the 35 in-tree module
     * bundles each ship their own (NewsBundle\Entity\..., CartHoldBundle\Entity\..., ...), and a
     * per-getter allowlist would be stale the day it was written. Everything outside that shape —
     * the container, Request, kernel, repositories, Twig's own runtime — is refused.
     */
    private function isReadable(object $obj): bool
    {
        foreach (self::ALLOWED_CLASSES as $allowed) {
            if ($obj instanceof $allowed) {
                return true;
            }
        }

        return str_contains($obj::class, '\\Entity\\');
    }
}
