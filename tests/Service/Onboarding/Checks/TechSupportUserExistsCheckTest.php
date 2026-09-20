<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\AdminUser;
use App\Service\DocumentActor;
use App\Service\Onboarding\Checks\TechSupportUserExistsCheck;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * #426: "check at least 1 tech support user - dont show detail sjust yes / no". The sharpest
 * requirement here is what the message must NOT contain, not just pass/fail correctness.
 */
final class TechSupportUserExistsCheckTest extends DoctrineIntegrationTestCase
{
    private function makeAdmin(array $roles, string $status, string $email): AdminUser
    {
        $admin = (new AdminUser())
            ->setEmail($email)
            ->setPassword('irrelevant-hash')
            ->setRoles($roles);
        $admin->setStatus($status, DocumentActor::system());
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    public function testNoTechSupportUserFails(): void
    {
        $this->makeAdmin(['ROLE_SUPER_ADMIN'], 'Active', 'super@example.test');

        self::assertFalse((new TechSupportUserExistsCheck($this->em))->run()->passed);
    }

    public function testActiveTechSupportUserPasses(): void
    {
        $this->makeAdmin(['ROLE_TECH_SUPPORT'], 'Active', 'sensitive-name-detail@example.test');

        self::assertTrue((new TechSupportUserExistsCheck($this->em))->run()->passed);
    }

    public function testInactiveTechSupportUserDoesNotCount(): void
    {
        $this->makeAdmin(['ROLE_TECH_SUPPORT'], 'Inactive', 'inactive-tech@example.test');

        self::assertFalse((new TechSupportUserExistsCheck($this->em))->run()->passed);
    }

    /**
     * The issue is explicit: no identifying detail, yes/no only. This asserts the pass-path
     * message never contains the matching user's own email — the most likely accidental leak if
     * a future edit tried to "helpfully" name who the Tech Support user is.
     */
    public function testPassingMessageDoesNotLeakTheMatchingUsersEmail(): void
    {
        $email = 'do-not-print-me@example.test';
        $this->makeAdmin(['ROLE_TECH_SUPPORT'], 'Active', $email);

        $result = (new TechSupportUserExistsCheck($this->em))->run();

        self::assertTrue($result->passed);
        self::assertStringNotContainsString($email, $result->message);
        self::assertStringNotContainsString('do-not-print-me', $result->message);
    }

    public function testFailingMessageAlsoNamesNoOne(): void
    {
        $this->makeAdmin(['ROLE_SUPER_ADMIN'], 'Active', 'other-admin@example.test');

        $result = (new TechSupportUserExistsCheck($this->em))->run();

        self::assertStringNotContainsString('other-admin', $result->message);
    }
}
