<?php

declare(strict_types=1);

namespace ProcurementBundle\Document;

use App\Contract\Document\DocumentPrefix;
use App\Contract\Document\DocumentPrefixProviderInterface;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;

/**
 * Puts this bundle's document prefixes on core's Document Prefixes screen (#615).
 *
 * ## Why this reverses SettingsController's stated reason
 *
 * That controller argued the prefixes were deliberately NOT on core's screen, because putting them
 * there would mean core's configuration UI knew this bundle existed and showed three dead fields
 * once it was removed. The argument was correct about the cost and correct about the constraint at
 * the time: core's screen named its prefixes in three variables, so the only way onto it was for
 * core to hardcode ours.
 *
 * #615 removed the constraint rather than accepting the cost. Core's screen now renders whatever
 * registered, so core still knows nothing about this bundle — and the fields disappear when the
 * bundle is removed or switched Inactive, which was the whole objection. The layering rule is
 * intact; only the mechanism changed.
 *
 * ## Why the bundle keeps a settings screen anyway
 *
 * Tolerances and receiving rules are not `app_setting` prefixes and stay where they are. The
 * prefix fields are gone from it and it links here instead, so there is one screen that answers
 * "what are this instance's document prefixes" — which was the practical complaint: the PO prefix
 * is the very next field somebody looks for after the sales order prefix, and it was three clicks
 * and a different section away.
 *
 * Defaults come from PurchaseDocumentNumberGenerator rather than being repeated, so the screen can
 * never advertise a prefix the next allocation would not use.
 */
final class ProcurementDocumentPrefixProvider implements DocumentPrefixProviderInterface
{
    /** @var array<string, array{label: string, plural: string, name: string}> */
    private const LABELS = [
        PurchaseDocumentNumberGenerator::KIND_PURCHASE_ORDER => [
            'label' => 'Purchase Order Prefix',
            'plural' => 'purchase orders',
            'name' => 'Purchase Order Number Prefix',
        ],
        PurchaseDocumentNumberGenerator::KIND_RECEIPT => [
            'label' => 'Purchase Receipt Prefix',
            'plural' => 'purchase receipts',
            'name' => 'Purchase Receipt Number Prefix',
        ],
        PurchaseDocumentNumberGenerator::KIND_BILL => [
            'label' => 'Vendor Bill Prefix',
            'plural' => 'vendor bills',
            'name' => 'Vendor Bill Number Prefix',
        ],
        // #637/#638. These four never had a row written by SettingsController::save(), because the
        // documents did not exist when it was the thing writing them — so unlike the three above,
        // their `name` is not constrained to that controller's ucwords-over-the-key output and is
        // spelled the way a person would. "RFQ Number Prefix", not "Rfq Number Prefix".
        PurchaseDocumentNumberGenerator::KIND_RFQ => [
            'label' => 'RFQ Prefix',
            'plural' => 'RFQs',
            'name' => 'RFQ Number Prefix',
        ],
        PurchaseDocumentNumberGenerator::KIND_RFQ_REPLY => [
            'label' => 'RFQ Reply Prefix',
            'plural' => 'vendor replies to RFQs',
            'name' => 'RFQ Vendor Reply Number Prefix',
        ],
        PurchaseDocumentNumberGenerator::KIND_VENDOR_RETURN => [
            'label' => 'Vendor Return Prefix',
            'plural' => 'vendor returns',
            'name' => 'Vendor Return Number Prefix',
        ],
        PurchaseDocumentNumberGenerator::KIND_DEBIT_MEMO => [
            'label' => 'Debit Memo Prefix',
            'plural' => 'debit memos',
            'name' => 'Debit Memo Number Prefix',
        ],
    ];

    public function __construct(private readonly PurchaseDocumentNumberGenerator $numbers)
    {
    }

    /** @return list<DocumentPrefix> */
    public function documentPrefixes(): array
    {
        $prefixes = [];

        foreach (PurchaseDocumentNumberGenerator::kinds() as $kind) {
            // Driven off kinds() rather than off LABELS, so a kind the generator can allocate but
            // this screen cannot name is a failure rather than a prefix silently missing from the
            // one screen that answers "what are this instance's document prefixes".
            //
            // Said out loud because the bare lookup this replaces failed as `Undefined array key
            // "rfq"` several frames deep in the catalogue — which names the symptom and not one
            // word about the fix. #637/#638 added four kinds at once and hit exactly that.
            $labels = self::LABELS[$kind] ?? throw new \LogicException(sprintf(
                'PurchaseDocumentNumberGenerator can allocate "%s" numbers, but %s has no label for'
                . ' that kind, so its prefix would be missing from the Document Prefixes screen.'
                . ' Add an entry to self::LABELS.',
                $kind,
                self::class,
            ));

            $prefixes[] = new DocumentPrefix(
                key: $this->numbers->settingKeyFor($kind),
                label: $labels['label'],
                defaultPrefix: $this->numbers->defaultFor($kind),
                documentPlural: $labels['plural'],
                // The name SettingsController::save() wrote — ucwords over the key — carried over
                // verbatim so a row it already created is rewritten identically, not renamed.
                settingName: $labels['name'],
                settingDescription: 'Not retroactive — documents already numbered keep the prefix they were given.',
            );
        }

        return $prefixes;
    }
}
