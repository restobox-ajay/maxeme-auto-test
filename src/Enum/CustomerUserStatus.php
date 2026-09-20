<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A customer account's state. The frozen core contract for the `customer_user` vocabulary
 * (`CoreStatusVocabularyProvider`) — see {@see \App\Tests\Status\ShippedVocabulariesMatchTheirEnumsTest}
 * for why this exists at all: the vocabulary is what everything at runtime reads, this just pins
 * the two slugs a deliberate, reviewed change to the core contract may ever touch.
 *
 * Kept as its own enum rather than reusing AdminUserStatus, same reason the vocabularies are kept
 * separate: a customer account is also gated by its company's status, which an admin account never
 * is, and the two are likely to diverge in rules even while their slugs happen to match today.
 */
enum CustomerUserStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';
}
