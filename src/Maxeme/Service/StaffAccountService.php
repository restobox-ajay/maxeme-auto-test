<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Maxeme\Dto\AccountIdentityRequest;
use App\Maxeme\Dto\ProfileUpdateRequest;
use App\Maxeme\Dto\StaffAccountRequest;
use App\Maxeme\Dto\StaffAccountUpdateRequest;
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

    /** Manage Admins' column search boxes: filters[field] => text, except role (a StaffRole value). */
    public const SEARCH_FIELDS = ['first_name', 'last_name', 'email', 'username', 'role'];

    /**
     * The active accounts matching every non-empty column filter: the text columns contain the
     * typed text (any case), the role is the account's own role.
     *
     * @param array<string, string> $filters
     *
     * @return list<AdminUser>
     */
    public function search(array $filters): array
    {
        $filters = array_filter(
            array_map('trim', array_intersect_key($filters, array_flip(self::SEARCH_FIELDS))),
            static fn (string $value): bool => $value !== '',
        );

        return array_values(array_filter($this->activeAccounts(), static function (AdminUser $user) use ($filters): bool {
            foreach ($filters as $field => $value) {
                $matches = match ($field) {
                    'role' => StaffRole::of($user)?->value === $value,
                    'first_name' => mb_stripos((string) $user->getFirstName(), $value) !== false,
                    'last_name' => mb_stripos((string) $user->getLastName(), $value) !== false,
                    'email' => mb_stripos((string) $user->getEmail(), $value) !== false,
                    'username' => mb_stripos((string) $user->getUsername(), $value) !== false,
                };
                if (!$matches) {
                    return false;
                }
            }

            return true;
        }));
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

    /**
     * Manage Admins › Edit: name, email, username, role and (when given) a new password.
     *
     * @param StaffAccountUpdateRequest $request already passed validate()
     *
     * @throws \DomainException when the change would leave no active Super Admin
     */
    public function update(AdminUser $user, StaffAccountUpdateRequest $request): void
    {
        $current = StaffRole::of($user);
        if ($current === StaffRole::SuperAdmin && $request->role !== StaffRole::SuperAdmin && $this->activeSuperAdminCount() <= 1) {
            throw new \DomainException('This is the only active Super Admin. Make another account Super Admin first.');
        }

        $user->setEmail($request->email)
            ->setUsername($request->username)
            ->setFirstName($request->firstName)
            ->setLastName($request->lastName);

        if ($request->role !== null && $request->role !== $current) {
            $staffRoles = array_map(static fn (StaffRole $role): string => $role->value, StaffRole::cases());
            $otherRoles = array_values(array_diff($user->getRoles(), $staffRoles, ['ROLE_USER']));
            $user->setRoles([...$otherRoles, $request->role->value]);
        }
        if ($request->password !== null) {
            $this->setPassword($user, $request->password->password);
        }

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
