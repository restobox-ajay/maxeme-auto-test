<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a customer login, and the company it has to belong to.
 *
 * The counterpart of app:create-admin, and the piece a freshly provisioned e2e instance was
 * missing. app:test-instance:provision gave an instance schema, reference data and a staff
 * login — but every storefront persona still failed at loginAs(), because a CustomerUser cannot
 * exist without a Company and nothing created either.
 *
 * Deliberately not left to seed_demo.py. That script drives the admin UI over HTTP and creates
 * plenty of customers, but it needs to log IN first and it invents its own emails; the `user`
 * persona needs one specific address (ken+customer_{instance}@…) to match the generated
 * personas.json. A login the test harness depends on should not be a side effect of a demo-data
 * script — it is part of provisioning the instance.
 *
 * Idempotent: re-running updates the password rather than failing, so a re-provision is safe.
 */
#[AsCommand(
    name: 'app:create-customer',
    description: 'Create or update a customer user (and its company).',
)]
final class CreateCustomerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Customer email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password')
            ->addOption('company', null, InputOption::VALUE_REQUIRED,
                'Company name — created if it does not exist', 'E2E Seed Co')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'First name', 'E2E')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Last name', 'Customer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getOption('email'));
        $password = (string) $input->getOption('password');

        if ($email === '' || $password === '') {
            $io->error('--email and --password are both required.');

            return Command::FAILURE;
        }

        $companyName = (string) $input->getOption('company');
        $companyRepo = $this->em->getRepository(Company::class);
        $company = $companyRepo->findOneBy(['name' => $companyName]);
        if (!$company instanceof Company) {
            $company = new Company();
            $company->setName($companyName);
            // Only what the schema demands. A richer fixture belongs in seed_demo.py; the job
            // here is a login that works, and every extra field guessed at is a field that
            // silently diverges from what the app's own create form would have produced.
            // No 'setStatus' entry here: 'Active' is the entity's own constructor default, and
            // HasStatus::setStatus() takes a required DocumentActor as its second argument now,
            // which this generic {$setter}($value) single-argument dispatch cannot supply.
            foreach (['setTradeName' => $companyName, 'setPrimaryEmail' => $email,
                      'setAccountType' => 'Business'] as $setter => $value) {
                if (method_exists($company, $setter)) {
                    $company->{$setter}($value);
                }
            }
            $this->em->persist($company);
            $this->em->flush();
            $io->text(sprintf('created company "%s" (id %d)', $companyName, $company->getId()));
        }

        $repo = $this->em->getRepository(CustomerUser::class);
        $user = $repo->findOneBy(['email' => $email]);
        $isNew = !$user instanceof CustomerUser;
        if ($isNew) {
            $user = new CustomerUser();
            $user->setEmail($email);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setCompany($company);
        $user->setStatus('Active', DocumentActor::system());
        if (method_exists($user, 'setRoles')) {
            $user->setRoles(['ROLE_USER']);
        }
        foreach (['setFirstName' => (string) $input->getOption('first-name'),
                  'setLastName' => (string) $input->getOption('last-name')] as $setter => $value) {
            if (method_exists($user, $setter)) {
                $user->{$setter}($value);
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('%s customer %s (company: %s)',
            $isNew ? 'Created' : 'Updated', $email, $companyName));

        return Command::SUCCESS;
    }
}
