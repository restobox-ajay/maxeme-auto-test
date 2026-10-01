<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * An admin staff account's state. The frozen core contract for the `admin_user` vocabulary
 * (`CoreStatusVocabularyProvider`) — see {@see \App\Tests\Status\ShippedVocabulariesMatchTheirEnumsTest}
 * for why this exists at all: the vocabulary is what everything at runtime reads, this just pins
 * the slugs a deliberate, reviewed change to the core contract may ever touch.
 */
enum AdminUserStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';
    /** Removed from Manage Admins; kept (not erased) so the records that name the account still resolve. */
    case Deleted = 'Deleted';
}
