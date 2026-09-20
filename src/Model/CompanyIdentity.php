<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Company;

/**
 * The customer's identity as it stood when a document was created.
 *
 * An invoice records a transaction between two named parties. Documents used to read the buyer's name,
 * phone and email straight through the live company foreign key, so a rebrand, an acquisition or a
 * corrected phone number silently rewrote every historical invoice — the same defect the address
 * snapshot fixed for addresses.
 *
 * Deliberately not included:
 *  - id — route generation needs the live company, and an identifier does not drift.
 *  - firstName / lastName — the address snapshot already carries the contact's name per address.
 *    Duplicating it here would recreate the two-sources-of-truth problem the snapshot removed.
 *  - pstNumber — was here, moved off Company/core entirely (#124): BC PST # is now
 *    TaxBCBundle-owned custom field data, snapshotted onto the order via its own
 *    TaxOrderSnapshotProviderInterface hook instead of this class. See
 *    TaxBCBundle\Tax\BCTaxCalculator::applyOrderSnapshot().
 */
final class CompanyIdentity
{
    private function __construct(
        public readonly string $name,
        public readonly ?string $tradeName,
        public readonly ?string $email,
        public readonly ?string $phone,
    ) {
    }

    public static function fromCompany(Company $company): self
    {
        // Blanks normalise to null on this path too, not just fromArray(): templates gate these
        // fields with {% if %}, so '' and null must not render differently depending on whether the
        // identity came from a live company or from a stored snapshot.
        return new self(
            trim($company->getName()),
            self::str($company->getTradeName()),
            self::str($company->getPrimaryEmail()),
            self::str($company->getPhoneNumber()),
        );
    }

    /**
     * Rebuild from a stored snapshot. Every field is read defensively: the column is nullable and
     * hand-written by a migration, so a row may predate a field being added.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::str($data['name'] ?? '') ?? '',
            self::str($data['tradeName'] ?? null),
            self::str($data['email'] ?? null),
            self::str($data['phone'] ?? null),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tradeName' => $this->tradeName,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    /** Twig-friendly aliases, so templates read the snapshot the same way they read the company. */
    public function getName(): string { return $this->name; }
    public function getTradeName(): ?string { return $this->tradeName; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }

    /** What a human should see: the trading name if there is one, otherwise the registered name. */
    public function getDisplayName(): string
    {
        return $this->tradeName !== null && $this->tradeName !== '' ? $this->tradeName : $this->name;
    }

    private static function str(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
