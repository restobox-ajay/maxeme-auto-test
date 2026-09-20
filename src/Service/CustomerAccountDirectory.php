<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Repository\CustomerUserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Find-or-create a CustomerUser by email — extracted from
 * Number1CustomerImportBundle\Service\CustomerImportService for the same reason CompanyDirectory
 * was: a second importer (WooCommerceBundle, #739) needs the identical "match by email, or mint an
 * account nobody is meant to log into with the password it gets" logic, and a second hand-rolled
 * copy of the create() call is exactly the thing to avoid.
 *
 * Lookup itself was already shared (CustomerUserRepository::findOneByEmailInsensitive()) before this
 * class existed; findByEmail() here is a thin pass-through kept only for symmetry with
 * CompanyDirectory::findByName().
 */
final class CustomerAccountDirectory
{
    public function __construct(
        private readonly CustomerUserRepository $customerUsers,
    ) {
    }

    public function findByEmail(string $email): ?CustomerUser
    {
        return $this->customerUsers->findOneByEmailInsensitive($email);
    }

    /**
     * The first account on a company becomes its Owner; every one after that is Staff. Password is
     * a random, never-communicated value — nobody is meant to log in with it directly, an invite
     * (where the caller wants one) is a separate step.
     *
     * Does not flush — caller owns the transaction, same convention OrderInvoicingService uses.
     */
    public function create(EntityManagerInterface $entityManager, string $email, Company $company, string $status = 'Active'): CustomerUser
    {
        $user = (new CustomerUser())
            ->setEmail($email)
            ->setCompany($company)
            ->setRoles([$this->hasOwner($company) ? 'ROLE_COMPANY_STAFF' : 'ROLE_COMPANY_OWNER'])
            ->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
        $user->setStatus($status, DocumentActor::system());

        $entityManager->persist($user);

        return $user;
    }

    public function hasOwner(Company $company): bool
    {
        if ($company->getId() === null) {
            return false;
        }

        foreach ($this->customerUsers->findBy(['company' => $company]) as $companyUser) {
            if (in_array('ROLE_COMPANY_OWNER', $companyUser->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }
}
