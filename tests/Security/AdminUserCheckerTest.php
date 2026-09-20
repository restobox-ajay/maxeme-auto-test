<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use App\Security\AccountStatusResolver;
use App\Security\AdminUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use App\Tests\Support\PutsRawEntityStatus;

final class AdminUserCheckerTest extends TestCase
{
    use PutsRawEntityStatus;

    private AdminUserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new AdminUserChecker(new AccountStatusResolver());
    }

    public function testCheckPreAuthAllowsActiveAdmin(): void
    {
        $admin = (new AdminUser());

        $this->checker->checkPreAuth($admin);

        $this->addToAssertionCount(1);
    }

    public function testCheckPreAuthBlocksInactiveAdmin(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your admin account is disabled.');

        $this->checker->checkPreAuth($admin);
    }

    public function testCheckPreAuthIgnoresNonAdminUsers(): void
    {
        $customer = new CustomerUser();

        $this->checker->checkPreAuth($customer);

        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthIsNoOp(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');

        $this->checker->checkPostAuth($admin);

        $this->addToAssertionCount(1);
    }
}
