<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * Lets whoever owns a document type put its number prefix on the Document Prefixes screen (#615).
 *
 * Before this, `ConfigController::documentPrefixes()` named three prefixes in three variables and
 * rendered three hard-coded fields. The consequence was not theoretical: credit memos (#586) and
 * sales returns (#596) both shipped with a `*_number_prefix` setting their generator reads, and
 * neither could be configured without editing PHP and redeploying — and ProcurementBundle, unable
 * to add a field to a core screen at all, grew a second prefix screen of its own. Eight prefixes
 * lived in three places, one of which was nowhere.
 *
 * The fifteenth collection of a shape core already uses fourteen times
 * (`app.injection_point`, `app.template_override`, `app.shipped_email_template_provider`,
 * `app.catalog_facet_provider`, …). It is deliberately modelled on the email-template one, which
 * solves the same problem — a bundle needing a row on a core settings screen — and is the closest
 * working precedent in the repo.
 *
 * Providers are gated on their owning bundle being Active, so a bundle switched off takes its
 * prefixes off the screen exactly as it takes its email templates off theirs. Core registers its
 * own prefixes through this interface rather than keeping them special, which is what makes the
 * screen's "whatever registered" behaviour testable: there is no hard-coded case left to hide
 * behind.
 *
 * A provider MUST NOT contribute a key another provider already owns. Core wins on collision and
 * the duplicate is dropped — silently taking over the field that writes a core prefix would be a
 * very quiet way to renumber a customer's invoices.
 */
interface DocumentPrefixProviderInterface
{
    /**
     * The prefixes this provider owns, in the order they should appear on the screen.
     *
     * @return list<DocumentPrefix>
     */
    public function documentPrefixes(): array;
}
