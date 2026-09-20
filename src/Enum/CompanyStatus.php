<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A company's state. The frozen core contract for the `company` vocabulary
 * (`CoreStatusVocabularyProvider`) — see {@see \App\Tests\Status\ShippedVocabulariesMatchTheirEnumsTest}
 * for why this exists at all: the vocabulary is what everything at runtime reads, this just pins
 * the three slugs a deliberate, reviewed change to the core contract may ever touch.
 */
enum CompanyStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';

    /** The interim state a self-registered company sits in until an admin approves it. */
    case Review = 'Review';
}
