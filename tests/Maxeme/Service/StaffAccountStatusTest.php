<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;
use App\Repository\AdminUserRepository;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use App\Status\CoreStatusVocabularyProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Manage Admins' De-activate / Activate / Delete buttons and the Status filter. */
final class StaffAccountStatusTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    public function testDeactivateActivateAndDelete(): void
    {
        $actor = $this->user(1, 'boss', StaffRole::SuperAdmin);
        $tech = $this->user(2, 'tech', StaffRole::Technician);
        $service = $this->service($actor, $tech);

        $service->deactivate($tech, $actor);
        self::assertSame(AdminUserStatus::Inactive->value, $tech->getStatus());

        $service->activate($tech, $actor);
        self::assertSame(AdminUserStatus::Active->value, $tech->getStatus());

        $service->delete($tech, $actor);
        self::assertSame(AdminUserStatus::Deleted->value, $tech->getStatus());
    }

    public function testCannotDeleteOwnAccount(): void
    {
        $actor = $this->user(1, 'boss', StaffRole::SuperAdmin);

        $this->expectExceptionMessage('You cannot delete your own account.');
        $this->service($actor)->delete($actor, $actor);
    }

    public function testCannotDeleteTheOnlyActiveSuperAdmin(): void
    {
        $actor = $this->user(1, 'admin', StaffRole::Admin);
        $boss = $this->user(2, 'boss', StaffRole::SuperAdmin);

        $this->expectExceptionMessage('This is the only active Super Admin.');
        $this->service($actor, $boss)->delete($boss, $actor);
    }

    public function testAnInactiveSuperAdminCanBeDeletedEvenWhenItIsTheOnlyOne(): void
    {
        $actor = $this->user(1, 'admin', StaffRole::Admin);
        $boss = $this->user(2, 'boss', StaffRole::SuperAdmin);
        $boss->setStatus(AdminUserStatus::Inactive->value, \App\Service\DocumentActor::system());

        $this->service($actor)->delete($boss, $actor);
        self::assertSame(AdminUserStatus::Deleted->value, $boss->getStatus());
    }

    public function testTheListShowsActiveAndInactiveAndFiltersByStatus(): void
    {
        $repository = $this->createStub(AdminUserRepository::class);
        $active = $this->user(1, 'on', StaffRole::Technician);
        $inactive = $this->user(2, 'off', StaffRole::Technician);
        $inactive->setStatus(AdminUserStatus::Inactive->value, \App\Service\DocumentActor::system());
        $repository->method('findBy')->willReturnCallback(static function (array $criteria) use ($active, $inactive): array {
            self::assertSame(['status' => ['Active', 'Inactive']], $criteria);

            return [$active, $inactive];
        });
        $service = new StaffAccountService($repository, $this->createStub(EntityManagerInterface::class), $this->createStub(UserPasswordHasherInterface::class), $this->createStub(ValidatorInterface::class));

        self::assertSame([$active, $inactive], $service->search([]));
        self::assertSame([$inactive], $service->search(['status' => 'Inactive']));
    }

    private function service(AdminUser ...$activeUsers): StaffAccountService
    {
        $repository = $this->createStub(AdminUserRepository::class);
        $repository->method('findBy')->willReturn($activeUsers);

        return new StaffAccountService(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(ValidatorInterface::class),
        );
    }

    private function user(int $id, string $username, StaffRole $role): AdminUser
    {
        $user = (new AdminUser())->setEmail($username . '@example.invalid')->setUsername($username)->setRoles([$role->value]);
        (new \ReflectionProperty(AdminUser::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
