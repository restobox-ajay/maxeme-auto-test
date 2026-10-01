<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Contract\Document\DocumentPrefix;
use App\Contract\Document\DocumentPrefixProviderInterface;

/**
 * The shop's own document prefixes, registered through core's seam (DocumentPrefixCatalogue) next
 * to core's invoice and quote prefixes, which the shop reuses. Set on Config › Settings › Doc Prefixes.
 */
final class MaxemeDocumentPrefixProvider implements DocumentPrefixProviderInterface
{
    public const REPAIR_ORDER = 'repair_order_number_prefix';
    public const WORK_ORDER = 'work_order_number_prefix';

    /** @return list<DocumentPrefix> */
    public function documentPrefixes(): array
    {
        return [
            new DocumentPrefix(
                key: self::REPAIR_ORDER,
                label: 'Repair Order Prefix',
                defaultPrefix: 'RO-',
                documentPlural: 'repair orders',
                settingName: 'Repair Order Number Prefix',
                settingDescription: 'Prefix for repair order numbers (e.g. RO-123).',
            ),
            new DocumentPrefix(
                key: self::WORK_ORDER,
                label: 'Work Order Prefix',
                defaultPrefix: 'WO-',
                documentPlural: 'work orders',
                settingName: 'Work Order Number Prefix',
                settingDescription: 'Prefix for work order numbers (e.g. WO-00001482): shown before the invoice id on the work order.',
            ),
        ];
    }
}
