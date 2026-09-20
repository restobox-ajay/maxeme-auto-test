<?php

declare(strict_types=1);

namespace App\Contract\Document;

/**
 * One configurable document-number prefix, as declared by whoever owns the document (#615).
 *
 * This is a description of a setting, not the setting itself. The value still lives in an
 * `app_setting` row keyed by {@see $key} and is still read by the number generator that owns it;
 * all this carries is enough for the Document Prefixes screen to render a field for it and write
 * the row back in the shape that screen has always written.
 *
 * `settingName` and `settingDescription` exist separately from `label` because the `app_setting`
 * row already has both, with wording that predates this class. They are carried verbatim rather
 * than derived so that re-saving an existing prefix rewrites the row byte-for-byte as it was — the
 * screen must not quietly reword rows an admin has been reading for a year.
 */
final class DocumentPrefix
{
    /**
     * @param string $key                the `app_setting` key, e.g. `order_number_prefix`
     * @param string $label              the form label, e.g. "Sales Order Prefix"
     * @param string $defaultPrefix      what the generator uses when no row exists, e.g. "SO-"
     * @param string $documentPlural     for the field hint: "New {documentPlural} are numbered SO-1, SO-2, …"
     * @param string $settingName        `app_setting.name`, e.g. "Order Number Prefix"
     * @param string $settingDescription `app_setting.description`
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $defaultPrefix,
        public readonly string $documentPlural,
        public readonly string $settingName,
        public readonly string $settingDescription,
    ) {
    }
}
