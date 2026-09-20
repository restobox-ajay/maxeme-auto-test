<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AdminUser;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends DoctrineIntegrationTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $command = $application->find('app:create-admin');
        $this->tester = new CommandTester($command);
    }

    public function testCreatesAdminWhenNoneExists(): void
    {
        $exitCode = $this->tester->execute(['--email' => 'new-admin@example.com', '--password' => 'S3cret!Passphrase']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Admin user created successfully.', $this->tester->getDisplay());

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'new-admin@example.com']);
        self::assertInstanceOf(AdminUser::class, $user);
        self::assertSame('Active', $user->getStatus());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $user->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'S3cret!Passphrase'));
    }

    /**
     * The command used to carry a real email and password as option defaults, so this bare
     * invocation silently provisioned a known-credential ROLE_SUPER_ADMIN. It must now refuse.
     */
    public function testNonInteractiveRunWithoutCredentialsCreatesNothing(): void
    {
        $exitCode = $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--email is required.', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(AdminUser::class)->findAll());
    }

    public function testNonInteractiveRunWithoutPasswordCreatesNothing(): void
    {
        $exitCode = $this->tester->execute(['--email' => 'new-admin@example.com'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--password is required when running non-interactively.', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(AdminUser::class)->findAll());
    }

    public function testShortPasswordIsRejected(): void
    {
        $exitCode = $this->tester->execute(['--email' => 'new-admin@example.com', '--password' => 'short']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('at least 12 characters', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(AdminUser::class)->findAll());
    }

    public function testPasswordIsPromptedWithoutEchoWhenOmittedInteractively(): void
    {
        $this->tester->setInputs(['Prompted!Passphrase']);

        $exitCode = $this->tester->execute(['--email' => 'prompted@example.com']);

        self::assertSame(Command::SUCCESS, $exitCode);

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'prompted@example.com']);
        self::assertInstanceOf(AdminUser::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'Prompted!Passphrase'));
    }

    public function testBlankEmailFails(): void
    {
        $exitCode = $this->tester->execute(['--email' => '  ', '--password' => 'irrelevant']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--email is required.', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(AdminUser::class)->findAll());
    }

    public function testExistingUserWithoutUpdateFlagIsLeftUntouched(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $existing = new AdminUser();
        $existing->setEmail('existing@example.com');
        $existing->setRoles(['ROLE_ADMIN']);
        $existing->setPassword($hasher->hashPassword($existing, 'OriginalPass1!Long'));
        $this->em->persist($existing);
        // First flush before the transition below: it's what primes StatusVocabularyRegistry via
        // preFlush (StatusVocabularyRegistrySubscriber) for a fixture built with no request or
        // console command yet in flight.
        $this->em->flush();
        $existing->setStatus('Inactive', DocumentActor::system());
        $this->em->flush();

        $exitCode = $this->tester->execute(['--email' => 'existing@example.com', '--password' => 'NewPass1!Longer']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Admin user already exists: existing@example.com', $this->tester->getDisplay());

        $this->em->refresh($existing);
        self::assertSame('Inactive', $existing->getStatus());
        self::assertSame(['ROLE_ADMIN'], $existing->getRoles());
        self::assertTrue($hasher->isPasswordValid($existing, 'OriginalPass1!Long'));
    }

    public function testExistingUserWithUpdateFlagIsPromotedAndPasswordReset(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $existing = new AdminUser();
        $existing->setEmail('existing@example.com');
        $existing->setRoles(['ROLE_ADMIN']);
        $existing->setPassword($hasher->hashPassword($existing, 'OriginalPass1!Long'));
        $this->em->persist($existing);
        // First flush before the transition below: it's what primes StatusVocabularyRegistry via
        // preFlush (StatusVocabularyRegistrySubscriber) for a fixture built with no request or
        // console command yet in flight.
        $this->em->flush();
        $existing->setStatus('Inactive', DocumentActor::system());
        $this->em->flush();

        $exitCode = $this->tester->execute([
            '--email' => 'existing@example.com',
            '--password' => 'NewPass1!Longer',
            '--update' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Admin user updated successfully.', $this->tester->getDisplay());

        $this->em->refresh($existing);
        self::assertSame('Active', $existing->getStatus());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $existing->getRoles());
        self::assertTrue($hasher->isPasswordValid($existing, 'NewPass1!Longer'));
    }

    /**
     * The whole point of --role. UserController strips ROLE_TECH_SUPPORT from the options offered to
     * anyone who is not already Tech Support, so on a fresh install - one ROLE_SUPER_ADMIN and
     * nothing else - the role was unreachable: the only account that could create one was the one
     * account that could not be created.
     */
    public function testCreatesATechSupportAdminWhenAskedTo(): void
    {
        $exitCode = $this->tester->execute([
            '--email' => 'ops@example.com',
            '--password' => 'S3cret!Passphrase',
            '--role' => 'ROLE_TECH_SUPPORT',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'ops@example.com']);
        self::assertInstanceOf(AdminUser::class, $user);
        self::assertContains('ROLE_TECH_SUPPORT', $user->getRoles());
    }

    public function testRefusesARoleItDoesNotRecognise(): void
    {
        $exitCode = $this->tester->execute([
            '--email' => 'nope@example.com',
            '--password' => 'S3cret!Passphrase',
            '--role' => 'ROLE_ROOT',
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown role "ROLE_ROOT"', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(AdminUser::class)->findAll());
    }

    /**
     * --update promotes to ROLE_SUPER_ADMIN by design (see
     * testExistingUserWithUpdateFlagIsPromotedAndPasswordReset), which is a trap for the role this
     * option exists to create: resetting a Tech Support password without --role hands back a super
     * admin. Passing it keeps the account what it was.
     */
    public function testUpdateKeepsTechSupportWhenTheRoleIsRestated(): void
    {
        $this->tester->execute([
            '--email' => 'ops@example.com',
            '--password' => 'S3cret!Passphrase',
            '--role' => 'ROLE_TECH_SUPPORT',
        ]);

        $exitCode = $this->tester->execute([
            '--email' => 'ops@example.com',
            '--password' => 'An0ther!Passphrase',
            '--update' => true,
            '--role' => 'ROLE_TECH_SUPPORT',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $this->em->clear();
        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'ops@example.com']);
        self::assertContains('ROLE_TECH_SUPPORT', $user->getRoles());
        self::assertNotContains('ROLE_SUPER_ADMIN', $user->getRoles());
    }
}
