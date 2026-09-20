<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Derives a company code from the company name, avoiding one already in use.
 *
 * ## Why the read-then-write below is left as it is (#332)
 *
 * #332 flags this loop as the same non-atomic shape as the duplicate-email pre-check in
 * Customer/AuthController::register() and asks for it to be fixed alongside, on the grounds that
 * concurrent registrations with similar company names collide on a unique index over company.code
 * and land in the same generic "Registration failed" catch.
 *
 * Uniqueness on this column is no longer intended. company.code holds an External ID assigned by
 * the *customer's* ERP or accounting system, so whether two companies share one is not this
 * application's call to make; the mapping became a plain #[ORM\Index] (Company.php) and
 * Admin\CompanyController stopped rejecting duplicates. A database built from the entity mapping —
 * which is every test database, since tests/_bootstrap.php runs doctrine:schema:create — therefore
 * has no unique index here and nothing for a concurrent write to violate.
 *
 * A *migrated* database is a different story, and not deliberately: the migration that dropped
 * uniq_company_code was lost to a bad merge (it lived at Version20260730170000, whose number was
 * then reassigned to the buyer-identity snapshot in 9badd90), and Version20260731160000's table
 * rebuild for #124 recreates `CREATE UNIQUE INDEX uniq_company_code` outright. So a migrated
 * database still enforces a constraint the code no longer believes in. That is a schema-drift bug
 * of its own — the fix is to restore the lost migration, not to bend this generator around it, and
 * it is deliberately out of scope here rather than smuggled into a registration fix.
 *
 * Either way this loop stays as it is. Where the index is gone there is nothing to serialise; where
 * it is still (wrongly) present, register()'s UniqueConstraintViolationException handler now tells
 * the two constraints apart instead of blaming the email field for a code collision.
 *
 * It is also NOT a candidate for DocumentNumberAllocator's counter-table treatment. That exists for
 * order and quote numbers (#304), where the value is a sequence, uniqueness IS wanted, and a
 * duplicate is a hard failure at flush. Here the value is name-derived rather than sequential and
 * uniqueness is explicitly not wanted, so a serialising counter would buy nothing while taking a
 * write lock on every registration to protect an invariant the schema is not supposed to have.
 */
final class CompanyCodeGenerator
{
    public function generate(EntityManagerInterface $entityManager, string $companyName): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $companyName) ?? '');
        $base = substr($base !== '' ? $base : 'COMP', 0, 12);
        $companyRepository = $entityManager->getRepository(Company::class);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = $attempt === 0 ? '' : (string) random_int(1000, 9999);
            $candidate = substr($base . $suffix, 0, 80);

            if ($companyRepository->findOneBy(['code' => $candidate]) === null) {
                return $candidate;
            }
        }

        return substr($base . strtoupper(bin2hex(random_bytes(3))), 0, 80);
    }
}
