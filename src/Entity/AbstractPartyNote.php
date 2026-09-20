<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One internal note against a party (#635) — admin-only, never shown to the customer or the supplier.
 *
 * `company_note` and `vendor_note` were already the same three columns under the same three names
 * (`user_name`, `text`, `created_at`), because #605 copied CompanyNote's shape rather than inventing
 * one. So unlike the address pair, this superclass is pure extraction: NEITHER table needs a
 * migration, and the schema dump before and after this change is byte-identical for both.
 *
 * ## Why a note is a row
 *
 * Both sides started life as a single packed text column — `company.notes` until #358, `vendor.notes`
 * until #605 — one entry per line as "timestamp|text", with the controller splitting the string and
 * addressing entries by their position in the resulting array. Every bug that produced came from the
 * encoding rather than the logic:
 *
 *   - Display and delete split the string differently, so a whitespace-only line shifted every
 *     position after it and deleting the entry an admin clicked removed a different one.
 *   - A newline typed inside one note silently became two notes.
 *   - "timestamp|text" has no escaping, so a note containing a pipe was ambiguous on read.
 *   - Nothing could bound one note's length, because one note and the whole list were one value.
 *
 * None of those are expressible once a note is a row. Deletion addresses an id rather than a
 * position, the text is just text, and a note is bounded independently of how many exist.
 *
 * Neither legacy CLOB is migrated into rows and neither is dropped: splitting free text means
 * guessing where one note ends and attributing each guess to an author and a date nobody recorded.
 *
 * What stays on the children is only the owning FK — `company_id` or `vendor_id` — because that is
 * the one thing a note about a customer and a note about a supplier genuinely do not share.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPartyNote
{
    /** Long enough for any real note; bounded so one entry cannot bloat the party's detail page. */
    public const MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    /**
     * Who wrote it. The packed formats had nowhere to put this, so until the extraction nobody knew
     * who left a note — SalesOrderLog has carried the equivalent all along.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $userName = null;

    #[ORM\Column(type: 'text')]
    protected string $text = '';

    /**
     * A real timestamp, not a pre-formatted display string. The packed format stored "M j, Y g:i A"
     * and the display path sorted by running strtotime() back over it, so an entry whose date failed
     * to parse sorted as the epoch.
     */
    #[ORM\Column]
    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUserName(): ?string { return $this->userName; }
    public function setUserName(?string $userName): static { $this->userName = $userName; return $this; }

    public function getText(): string { return $this->text; }
    public function setText(string $text): static { $this->text = $text; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
