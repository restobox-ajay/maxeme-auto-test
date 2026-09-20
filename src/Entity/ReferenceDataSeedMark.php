<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReferenceDataSeedMarkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "This seeder has run." One row per {@see \App\Contract\ReferenceData\ReferenceDataSeederInterface}
 * key, and the only thing standing between a customer's deletions and a seeder that would put them
 * back.
 *
 * ## The question this table exists to answer
 *
 * A reference list is empty. Two completely different things can have caused that, and they need
 * opposite responses:
 *
 *  - **Never seeded.** Nobody has set the system up. The rows should be created.
 *  - **Seeded, then emptied on purpose.** A customer deleted them. The rows must stay gone —
 *    `docs/QUEUE.md`: *"Never write to existing data"*, and re-creating a row somebody deleted is
 *    the same disrespect for their decision as overwriting one they edited.
 *
 * Looking at the list cannot tell those apart: both are zero rows. Only a record of the SEEDING
 * EVENT can, which is what this row is. Mark present ⇒ it has been done ⇒ whatever the list looks
 * like now is what the customer wants it to look like, and the seeder never runs again.
 *
 * ## Why per key rather than one global flag
 *
 * A bundle installed in year two has no mark of its own, so it seeds on the next admin login even
 * though the installation has obviously had a first login long ago. A single global "we have
 * seeded" boolean would silently skip it — the same failure the whole change exists to fix, a
 * missing row read as "nothing required". See {@see ReferenceDataSeederInterface::getKey()} for why
 * a bundle's SECOND batch of reference data gets its own key rather than a version bump here: a new
 * key is a new row, and a version bump would be an UPDATE to existing data.
 *
 * ## `seeder_key` is UNIQUE, and that is load-bearing
 *
 * Two admins logging in at the same instant both reach the seeder. The unique index is what makes
 * the second one lose — {@see \App\Service\ReferenceData\ReferenceDataSeeder} claims the key by
 * INSERTing this row inside the same transaction as the rows themselves, so the loser's INSERT is
 * refused by the database and its whole transaction rolls back, rows included. A `findOneBy()`
 * followed by a `persist()` would let both sides pass the check and both write.
 */
#[ORM\Entity(repositoryClass: ReferenceDataSeedMarkRepository::class)]
#[ORM\Table(name: 'reference_data_seed_mark')]
#[ORM\UniqueConstraint(name: 'uniq_reference_data_seed_mark_key', fields: ['seederKey'])]
class ReferenceDataSeedMark
{
    /** Matches the column width; a key longer than this is refused rather than silently truncated. */
    public const KEY_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'seeder_key', length: self::KEY_MAX_LENGTH, unique: true)]
    private string $seederKey = '';

    /**
     * How many rows that one seeding run created.
     *
     * Diagnostic only, and 0 is a perfectly ordinary value: an installation upgraded from the old
     * config-page lazy seeding already holds every row, so its first real run finds them all and
     * creates none. Nothing reads this to make a decision — the MARK is the decision.
     */
    #[ORM\Column(name: 'rows_created', options: ['default' => 0])]
    private int $rowsCreated = 0;

    #[ORM\Column(name: 'seeded_at')]
    private \DateTimeImmutable $seededAt;

    public function __construct()
    {
        $this->seededAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSeederKey(): string { return $this->seederKey; }
    public function setSeederKey(string $seederKey): static { $this->seederKey = $seederKey; return $this; }

    public function getRowsCreated(): int { return $this->rowsCreated; }
    public function setRowsCreated(int $rowsCreated): static { $this->rowsCreated = $rowsCreated; return $this; }

    public function getSeededAt(): \DateTimeImmutable { return $this->seededAt; }
    public function setSeededAt(\DateTimeImmutable $seededAt): static { $this->seededAt = $seededAt; return $this; }
}
