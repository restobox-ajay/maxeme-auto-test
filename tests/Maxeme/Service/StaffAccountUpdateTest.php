<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Maxeme\Dto\StaffAccountUpdateRequest;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;
use App\Repository\AdminUserRepository;
use App\Validation\Dto\NewPasswordRequest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Manage Admins › Edit. */
final class StaffAccountUpdateTest extends TestCase
{
    public function testUpdatesIdentityAndRoleAndKeepsPasswordWhenBlank(): void
    {
        $user = $this->user('Ben', 'Asher', 'ben@example.invalid', 'benny', StaffRole::Technician);
        $user->setPassword('old-hash');
        $request = StaffAccountUpdateRequest::fromRequest(new Request(request: [
            'email' => ' bennett@example.invalid ', 'username' => 'bennett', 'first_name' => 'Bennett', 'last_name' => 'Ash',
            'role' => StaffRole::Receptionist->value, 'password' => '', 'password_confirm' => '',
        ]), $user);

        self::assertNull($request->password);
        $this->service($user)->update($user, $request);

        self::assertSame('bennett@example.invalid', $user->getEmail());
        self::assertSame('bennett', $user->getUsername());
        self::assertSame('Bennett Ash', $user->getFirstName() . ' ' . $user->getLastName());
        self::assertSame(StaffRole::Receptionist, StaffRole::of($user));
        self::assertNotContains(StaffRole::Technician->value, $user->getRoles());
        self::assertSame('old-hash', $user->getPassword());
    }

    public function testSetsANewPasswordWhenGiven(): void
    {
        $user = $this->user('Cara', 'Lee', 'cara@example.invalid', 'cara', StaffRole::Technician);
        $request = StaffAccountUpdateRequest::fromUser($user);
        $request->password = new NewPasswordRequest('n3w-Secret!', 'n3w-Secret!');

        $this->service($user)->update($user, $request);

        self::assertSame('hashed:n3w-Secret!', $user->getPassword());
    }

    public function testTechSupportKeepsItsRoleAndNobodyIsMadeTechSupport(): void
    {
        $tech = $this->user('Tia', 'Tech', 'tia@example.invalid', 'tia', StaffRole::TechSupport);
        $other = $this->user('Ola', 'Oak', 'ola@example.invalid', 'ola', StaffRole::Admin);
        $post = ['role' => StaffRole::Admin->value];

        self::assertSame(StaffRole::TechSupport, StaffAccountUpdateRequest::fromRequest(new Request(request: $post), $tech)->role);
        self::assertNull(StaffAccountUpdateRequest::fromRequest(new Request(request: ['role' => StaffRole::TechSupport->value]), $other)->role);
    }

    public function testTheOnlySuperAdminCannotBeDemoted(): void
    {
        $boss = $this->user('Sam', 'Boss', 'sam@example.invalid', 'sam', StaffRole::SuperAdmin);
        $request = StaffAccountUpdateRequest::fromUser($boss);
        $request->role = StaffRole::Admin;

        $this->expectException(\DomainException::class);
        $this->service($boss)->update($boss, $request);
    }

    private function service(AdminUser ...$users): StaffAccountService
    {
        $repository = $this->createStub(AdminUserRepository::class);
        $repository->method('findBy')->willReturn($users);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (AdminUser $user, string $plain): string => 'hashed:' . $plain);

        return new StaffAccountService(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            $hasher,
            $this->createStub(ValidatorInterface::class),
        );
    }

    private function user(string $first, string $last, string $email, string $username, StaffRole $role): AdminUser
    {
        return (new AdminUser())->setFirstName($first)->setLastName($last)->setEmail($email)->setUsername($username)->setRoles([$role->value]);
    }
}
