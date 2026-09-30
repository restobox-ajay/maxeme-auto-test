<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Security;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffAccountVoter;
use App\Maxeme\Security\StaffRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/** The real decision manager, role hierarchy and permission files: who may change whose account. */
final class StaffAccountVoterTest extends KernelTestCase
{
    /** @return iterable<string, array{StaffRole, StaffRole, bool}> */
    public static function cases(): iterable
    {
        yield 'Super Admin changes a Super Admin' => [StaffRole::SuperAdmin, StaffRole::SuperAdmin, true];
        yield 'Tech Support changes a Super Admin' => [StaffRole::TechSupport, StaffRole::SuperAdmin, true];
        yield 'Admin cannot change a Super Admin' => [StaffRole::Admin, StaffRole::SuperAdmin, false];
        yield 'Admin cannot change Tech Support' => [StaffRole::Admin, StaffRole::TechSupport, false];
        yield 'Admin changes an Admin' => [StaffRole::Admin, StaffRole::Admin, true];
        yield 'Admin changes a Technician' => [StaffRole::Admin, StaffRole::Technician, true];
        yield 'Secretary I cannot change anyone' => [StaffRole::SecretaryOne, StaffRole::Technician, false];
    }

    #[DataProvider('cases')]
    public function testWhoMayChangeWhom(StaffRole $actor, StaffRole $target, bool $expected): void
    {
        self::bootKernel();
        $user = (new AdminUser())->setEmail('actor@example.invalid')->setRoles([$actor->value]);
        $token = new UsernamePasswordToken($user, 'admin', $user->getRoles());
        $account = (new AdminUser())->setEmail('target@example.invalid')->setRoles([$target->value]);

        $decisions = self::getContainer()->get(AccessDecisionManagerInterface::class);

        self::assertSame($expected, $decisions->decide($token, [StaffAccountVoter::MANAGE], $account));
    }
}
