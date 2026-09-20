<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Security\AccountStatusResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Tests\Support\PutsRawEntityStatus;

final class AccountStatusResolverTest extends TestCase
{
    use PutsRawEntityStatus;

    private AccountStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AccountStatusResolver();
    }

    public function testActiveAdminUserIsNotBlocked(): void
    {
        $admin = (new AdminUser());

        self::assertNull($this->resolver->getBlockMessage($admin));
    }

    public function testInactiveAdminUserIsBlocked(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');

        self::assertSame(
            'Your admin account is disabled.',
            $this->resolver->getBlockMessage($admin)
        );
    }

    public function testCustomerUserWithoutCompanyIsBlocked(): void
    {
        $customer = new CustomerUser();

        self::assertSame(
            'Your account is not assigned to a company. Please contact an administrator.',
            $this->resolver->getBlockMessage($customer)
        );
    }

    #[DataProvider('pendingCompanyStatusProvider')]
    public function testCustomerWithPendingCompanyIsBlocked(string $status): void
    {
        $company = $this->putRawStatus(new Company(), $status);
        $customer = (new CustomerUser())->setCompany($company);

        self::assertSame(
            'Your company registration is pending approval. Please wait for an administrator to review it.',
            $this->resolver->getBlockMessage($customer)
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function pendingCompanyStatusProvider(): array
    {
        return [
            ['Review'],
            [' pending '],
            ['Pending Approval'],
            ['AWAITING APPROVAL'],
        ];
    }

    public function testCustomerWithDisabledCompanyIsBlocked(): void
    {
        $company = $this->putRawStatus(new Company(), 'Suspended');
        $customer = (new CustomerUser())->setCompany($company);

        self::assertSame(
            'Your company account is disabled.',
            $this->resolver->getBlockMessage($customer)
        );
    }

    public function testCustomerWithActiveCompanyButInactiveUserIsBlocked(): void
    {
        $company = (new Company());
        $customer = $this->putRawStatus((new CustomerUser())->setCompany($company), 'Disabled');

        self::assertSame(
            'Your account is disabled.',
            $this->resolver->getBlockMessage($customer)
        );
    }

    public function testFullyActiveCustomerIsNotBlocked(): void
    {
        $company = (new Company());
        $customer = (new CustomerUser())->setCompany($company);

        self::assertNull($this->resolver->getBlockMessage($customer));
    }

    public function testCompanyStatusComparisonIsCaseAndWhitespaceInsensitive(): void
    {
        $company = $this->putRawStatus(new Company(), '  ACTIVE  ');
        $customer = $this->putRawStatus((new CustomerUser())->setCompany($company), '  active  ');

        self::assertNull($this->resolver->getBlockMessage($customer));
    }

    public function testUnrelatedUserImplementationIsNotBlocked(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'anonymous';
            }
        };

        self::assertNull($this->resolver->getBlockMessage($user));
    }
}
