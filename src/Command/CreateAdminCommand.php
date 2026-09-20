<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create or update an admin user')]
class CreateAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 12;

    private const DEFAULT_ROLE = 'ROLE_SUPER_ADMIN';

    /**
     * The roles the admin user screen can express, and the only ones this command will set.
     *
     * ROLE_TECH_SUPPORT is here because nothing else can create one. UserController deliberately
     * strips it from the role options offered to anyone who is not already Tech Support
     * (`array_diff(self::STAFF_ROLE_OPTIONS, [self::ROLE_TECH_SUPPORT])`) and hides existing Tech
     * Support rows from them — a store's own super admin must not be able to grant itself the
     * database console. Correct, but it left the role unreachable on a fresh install: this command
     * only ever minted ROLE_SUPER_ADMIN, so the one account an installation starts with was
     * precisely the account that cannot create the role.
     *
     * Granting it from the CLI is not an escalation. Running bin/console already means shell access
     * on the host, and anyone with that can read var/data/*.sqlite directly.
     */
    private const ALLOWED_ROLES = [
        'ROLE_SUPER_ADMIN',
        'ROLE_TECH_SUPPORT',
        'ROLE_ADMIN',
        'ROLE_PLANT_STAFF',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // No defaults: InputOption::VALUE_REQUIRED only means "if you pass --password, it must
            // carry a value" — it does not make the option mandatory. Supplying a default here meant
            // a bare `php bin/console app:create-admin` silently provisioned a known-password
            // ROLE_SUPER_ADMIN account, and the credentials sat in version control besides.
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password (omit to be prompted without echo)')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update password if the user already exists')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, sprintf(
                'Role to assign (%s). Defaults to %s, including on --update, which promotes.',
                implode(', ', self::ALLOWED_ROLES),
                self::DEFAULT_ROLE,
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(AdminUser::class);

        $email = trim((string) $input->getOption('email'));
        $plainPassword = (string) $input->getOption('password');
        $update = (bool) $input->getOption('update');

        // null means the option was absent, which --update treats as "leave the role alone".
        $requestedRole = $input->getOption('role');
        $requestedRole = $requestedRole === null ? null : strtoupper(trim((string) $requestedRole));

        if ($requestedRole !== null && !in_array($requestedRole, self::ALLOWED_ROLES, true)) {
            $output->writeln(sprintf(
                '<error>Unknown role "%s". Valid roles: %s.</error>',
                $requestedRole,
                implode(', ', self::ALLOWED_ROLES),
            ));

            return Command::FAILURE;
        }

        if ($email === '') {
            $output->writeln('<error>--email is required.</error>');
            return Command::FAILURE;
        }

        // Prompt rather than accept an empty password, and hide the input so the credential does
        // not end up in the operator's shell history or in CI logs. A non-interactive run with no
        // --password fails loudly instead of provisioning a super admin nobody chose a password for.
        if ($plainPassword === '') {
            if (!$input->isInteractive()) {
                $output->writeln('<error>--password is required when running non-interactively.</error>');
                return Command::FAILURE;
            }

            $question = (new Question('Admin password: '))
                ->setHidden(true)
                ->setHiddenFallback(false);

            $plainPassword = (string) $this->getHelper('question')->ask($input, $output, $question);
        }

        if (\strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            $output->writeln(\sprintf(
                '<error>Password must be at least %d characters.</error>',
                self::MIN_PASSWORD_LENGTH,
            ));

            return Command::FAILURE;
        }

        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing instanceof AdminUser) {
            if (!$update) {
                $output->writeln(sprintf('<comment>Admin user already exists: %s</comment>', $email));
                return Command::SUCCESS;
            }

            $existing->setPassword($this->hasher->hashPassword($existing, $plainPassword));
            $existing->setStatus('Active', DocumentActor::system());
            // --update is the break-glass "promote this account and reset its password" path, so
            // with no --role it still forces ROLE_SUPER_ADMIN, as it always has. Pass --role to say
            // otherwise - notably when resetting a Tech Support password, which would otherwise
            // come back as a super admin.
            $existing->setRoles([$requestedRole ?? self::DEFAULT_ROLE]);
            $this->em->flush();

            $output->writeln('<info>Admin user updated successfully.</info>');
            $output->writeln(sprintf('<info>  Email:    %s</info>', $email));
            $output->writeln(sprintf('<info>  Role:     %s</info>', implode(', ', $existing->getRoles())));

            return Command::SUCCESS;
        }

        $user = new AdminUser();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        // No setStatus() call: 'Active' is the entity's own constructor default, and this is a
        // fresh, not-yet-persisted row.
        $user->setRoles([$requestedRole ?? self::DEFAULT_ROLE]);

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('<info>Admin user created successfully.</info>');
        $output->writeln(sprintf('<info>  Email:    %s</info>', $email));
        $output->writeln(sprintf('<info>  Role:     %s</info>', $requestedRole ?? self::DEFAULT_ROLE));

        return Command::SUCCESS;
    }
}
