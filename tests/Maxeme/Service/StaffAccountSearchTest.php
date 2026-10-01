<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Manage Admins' column search boxes. */
final class StaffAccountSearchTest extends TestCase
{
    public function testEveryFilterMustMatch(): void
    {
        $service = $this->service(
            $this->user('Asha', 'Patel', 'asha@example.invalid', 'asha', StaffRole::Admin),
            $this->user('Ben', 'Asher', 'ben@example.invalid', 'benny', StaffRole::Technician),
            $this->user('Cara', 'Lee', 'cara@example.invalid', 'cara', StaffRole::Technician),
        );

        self::assertSame(['asha', 'benny', 'cara'], $this->usernames($service->search([])));
        self::assertSame(['asha', 'benny', 'cara'], $this->usernames($service->search(['email' => '  ', 'role' => ''])));
        self::assertSame(['asha'], $this->usernames($service->search(['first_name' => 'ASH'])));
        self::assertSame(['benny'], $this->usernames($service->search(['last_name' => 'ash'])));
        self::assertSame(['benny', 'cara'], $this->usernames($service->search(['role' => StaffRole::Technician->value])));
        self::assertSame(['cara'], $this->usernames($service->search(['role' => StaffRole::Technician->value, 'email' => 'cara@'])));
        self::assertSame([], $this->usernames($service->search(['username' => 'zz'])));
        self::assertSame(['asha', 'benny', 'cara'], $this->usernames($service->search(['unknown' => 'x'])));
    }

    private function service(AdminUser ...$users): StaffAccountService
    {
        $repository = $this->createStub(AdminUserRepository::class);
        $repository->method('findBy')->willReturn($users);

        return new StaffAccountService(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(ValidatorInterface::class),
        );
    }

    private function user(string $first, string $last, string $email, string $username, StaffRole $role): AdminUser
    {
        return (new AdminUser())->setFirstName($first)->setLastName($last)->setEmail($email)->setUsername($username)->setRoles([$role->value]);
    }

    /**
     * @param list<AdminUser> $users
     *
     * @return list<string|null>
     */
    private function usernames(array $users): array
    {
        return array_map(static fn (AdminUser $user): ?string => $user->getUsername(), $users);
    }
}
