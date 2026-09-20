<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\AbstractPartyNote;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\VendorNoteRepository;

/**
 * One internal note against a vendor (#605) — admin-only, never shown to the supplier.
 *
 * The buy-side mirror of `App\Entity\CompanyNote`, and since #635 literally the same class
 * underneath: author, text, timestamp and the reasoning behind them all live on
 * `App\Entity\AbstractPartyNote`, which both notes extend. #605 had already copied CompanyNote's
 * column names rather than inventing new ones, so `user_name` / `text` / `created_at` matched on the
 * day the superclass was extracted and neither table needed a migration for it.
 *
 * What is left here is the FK: this note is about a vendor.
 *
 * ## `vendor.notes` was migrated into this table and then dropped (item 43)
 *
 * This docblock used to say the opposite, in capitals, and the reasoning it gave still holds for the
 * half it was actually about: splitting a free-text blob into SEVERAL rows means guessing where one
 * note ends and the next begins, and attributing each guess to an author and a date nobody recorded.
 * So `Version20260913090000` does not split anything. Each vendor's CLOB becomes exactly ONE row,
 * carrying its text byte for byte, authored 'Migrated from the old notes field' and dated when the
 * migration ran — no boundaries guessed, no authorship invented, and the text kept.
 *
 * What changed is the other half. Keeping the column meant the vendor screen carried two Notes
 * sections where the customer screen carries one, and `Company` has no plain notes column to mirror:
 * the sell side has only threaded `company_note` rows. The owner ruled that "notes, just like the
 * customer side" means one threaded list. A column read by nothing and shown nowhere is not a
 * record of anything, so it goes — after its contents are here, never instead.
 *
 * `App\Entity\AbstractPartyNote` still carries the old claim for BOTH sides in its own docblock
 * and is core, so it was left alone; it is now correct about `company.notes` only.
 */
#[ORM\Entity(repositoryClass: VendorNoteRepository::class)]
#[ORM\Table(name: 'vendor_note')]
#[ORM\Index(name: 'idx_vendor_note_vendor', fields: ['vendor'])]
class VendorNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'noteEntries')]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    public function getVendor(): Vendor { return $this->vendor; }

    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }
}
