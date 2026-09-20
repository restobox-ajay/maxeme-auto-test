<?php

declare(strict_types=1);

namespace App\Contract\Email;

/**
 * Lets a bundle contribute templates to the shipped email catalogue (#563).
 *
 * Before this, `ShippedEmailTemplates` was a core class with a hardcoded array and there was no
 * seam at all. The consequence was live rather than theoretical: ProcurementBundle's purchase-order
 * email shipped as a fallback Twig view instead of a catalogue entry, so it did not appear in
 * Settings → Email Templates until an admin created a `purchase_order_vendor` row by hand. The send
 * worked and an admin-authored row overrode it correctly — it was only invisible until then.
 *
 * The alternative was for core to hardcode an optional bundle's template, which is the layering
 * violation that caused the problem in the first place.
 *
 * Follows the dozen provider interfaces already in `src/Contract/` — CatalogFacetProviderInterface,
 * PaymentMethodProviderInterface, DimensionalInventoryProviderInterface and the rest. Implementers
 * are tagged automatically by their bundle and gated on that bundle being Active, so a deactivated
 * bundle's templates disappear from the panel rather than lingering as entries nothing can send.
 *
 * A provider MUST NOT contribute a code that core already ships. Core wins on collision and the
 * duplicate is ignored — silently overriding a core template from a bundle would be a very quiet
 * way to change what a customer receives.
 */
interface ShippedEmailTemplateProviderInterface
{
    /**
     * The template codes this bundle ships, in the order they should appear in the panel.
     *
     * @return list<string>
     */
    public function codes(): array;

    /**
     * The shipped definition for one of this provider's codes, or null if it does not own it.
     *
     * Same shape as {@see \App\Service\Email\ShippedEmailTemplates::get()}, so the resolver can lay
     * an `email_template` row over it field by field without caring where the definition came from.
     * `body` is the rendered Twig source, and — like core's — must NOT carry a trailing newline;
     * the files on disk have one and the loader strips it.
     *
     * @return array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}|null
     */
    public function get(string $code): ?array;
}
