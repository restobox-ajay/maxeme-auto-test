<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Security\AccountStatusResolver;
use App\Security\CustomerUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use App\Tests\Support\PutsRawEntityStatus;

final class CustomerUserCheckerTest extends TestCase
{
    use PutsRawEntityStatus;

    private CustomerUserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new CustomerUserChecker(new AccountStatusResolver());
    }

    public function testCheckPreAuthAllowsFullyActiveCustomer(): void
    {
        $company = (new Company());
        $customer = (new CustomerUser())->setCompany($company);

        $this->checker->checkPreAuth($customer);

        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthBlocksCustomerWithoutCompany(): void
    {
        $customer = new CustomerUser();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account is not assigned to a company. Please contact an administrator.');

        $this->checker->checkPreAuth($customer);
    }

    public function testCheckPreAuthBlocksCustomerWithDisabledCompany(): void
    {
        $company = $this->putRawStatus(new Company(), 'Suspended');
        $customer = (new CustomerUser())->setCompany($company);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your company account is disabled.');

        $this->checker->checkPreAuth($customer);
    }

    public function testCheckPreAuthBlocksInactiveCustomerInActiveCompany(): void
    {
        $company = (new Company());
        $customer = $this->putRawStatus((new CustomerUser())->setCompany($company), 'Disabled');

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account is disabled.');

        $this->checker->checkPreAuth($customer);
    }

    public function testCheckPreAuthIgnoresNonCustomerUsers(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');

        $this->checker->checkPreAuth($admin);

        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthIsNoOp(): void
    {
        $customer = new CustomerUser();

        $this->checker->checkPostAuth($customer);

        $this->addToAssertionCount(1);
    }
}
