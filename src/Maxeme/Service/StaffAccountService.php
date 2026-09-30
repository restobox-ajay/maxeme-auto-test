<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Maxeme\Dto\AccountIdentityRequest;
use App\Maxeme\Dto\ProfileUpdateRequest;
use App\Maxeme\Dto\StaffAccountRequest;
use App\Maxeme\Security\StaffRole;
use App\Repository\AdminUserRepository;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Creates, edits and deactivates staff accounts (Manage Admins, My Profile). */
final class StaffAccountService
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** @return list<AdminUser> active accounts, by name */
    public function activeAccounts(): array
    {
        return $this->users->findBy(['status' => AdminUserStatus::Active->value], ['firstName' => 'ASC', 'lastName' => 'ASC', 'email' => 'ASC']);
    }

    /**
     * Constraint violations plus email/username uniqueness, keyed by property path.
     *
     * @return array<string, string>
     */
    public function validate(AccountIdentityRequest $request, ?AdminUser $existing = null): array
    {
        $errors = FieldErrors::from($this->validator->validate($request));

        if (!isset($errors['email']) && $this->users->isEmailTaken($request->email, $existing)) {
            $errors['email'] = 'This email is already used by another account.';
        }
        if (!isset($errors['username']) && $this->users->isUsernameTaken($request->username, $existing)) {
            $errors['username'] = 'This username is already used by another account.';
        }

        return $errors;
    }

    /** @param StaffAccountRequest $request already passed validate() */
    public function create(StaffAccountRequest $request): AdminUser
    {
        $user = (new AdminUser())
            ->setEmail($request->email)
            ->setUsername($request->username)
            ->setFirstName($request->firstName)
            ->setLastName($request->lastName)
            ->setRoles([$request->role->value]);
        $this->setPassword($user, $request->password->password);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** @param ProfileUpdateRequest $request already passed validate() */
    public function updateIdentity(AdminUser $user, ProfileUpdateRequest $request): void
    {
        $user->setEmail($request->email)->setUsername($request->username);
        $this->entityManager->flush();
    }

    public function changePassword(AdminUser $user, string $plainPassword): void
    {
        $this->setPassword($user, $plainPassword);
        $this->entityManager->flush();
    }

    /**
     * Stops the account signing in (core's AdminUserChecker refuses non-Active accounts).
     *
     * @throws \DomainException with a user-facing reason when the account must stay active
     */
    public function deactivate(AdminUser $user, AdminUser $actor): void
    {
        if ($user->getId() === $actor->getId()) {
            throw new \DomainException('You cannot de-activate your own account.');
        }
        if (StaffRole::of($user) === StaffRole::SuperAdmin && $this->activeSuperAdminCount() <= 1) {
            throw new \DomainException('This is the only active Super Admin. Make another account Super Admin first.');
        }

        $user->setStatus(AdminUserStatus::Inactive->value, DocumentActor::forAdmin($actor));
        $this->entityManager->flush();
    }

    private function setPassword(AdminUser $user, string $plainPassword): void
    {
        $user->setLegacySalt(null);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
    }

    private function activeSuperAdminCount(): int
    {
        return count(array_filter(
            $this->activeAccounts(),
            static fn (AdminUser $account): bool => StaffRole::of($account) === StaffRole::SuperAdmin,
        ));
    }
}
