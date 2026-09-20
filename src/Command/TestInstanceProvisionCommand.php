<?php

declare(strict_types=1);

namespace App\Command;

use App\Doctrine\TestInstanceConnectionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Creates and seeds one throwaway database so an e2e agent has somewhere isolated to work.
 *
 * The header mechanism (App\Doctrine\TestInstanceConnectionFactory) will happily connect to
 * var/db_{name}.sqlite and SQLite will create the file — but an empty file has no tables, so the
 * first request dies. Something has to build the schema and put a usable admin and customer in
 * it, and it has to do so the same way every time or agents will write tests against subtly
 * different starting data.
 *
 * Composes the app's OWN commands rather than reimplementing them:
 *
 *     doctrine:migrations:migrate   the schema, by replaying the migration chain
 *     app:seed-regions              the country/province reference rows
 *     app:create-admin              a staff login
 *     app:create-customer           a storefront login, plus the company it needs
 *
 * NOT doctrine:schema:create, though this docblock said so long after the code stopped doing it.
 * schema:create builds from entity metadata and cannot know about tables that exist only in the
 * migration chain — credit_memo_type, payment_term and shipping_zone have no entity class. See
 * the comment on the steps array; that is the authority, not this summary.
 *
 * Each runs as a sub-process with TEST_INSTANCE set, which the connection factory honours on the
 * CLI exactly as it honours X-Test-DB over HTTP. Sub-processes because those commands resolve
 * their connection at boot; one shared mechanism because two would drift.
 *
 * The first version passed DATABASE_URL instead and it silently did not work — PHP's default
 * variables_order is "GPCS", so $_ENV is empty, Dotenv finds no existing value and replaces the
 * inherited one with .env.local's. doctrine:schema:create then ran against the REAL dev
 * database and only stopped because a table already existed.
 *
 * Idempotent: run it again on the same instance and it re-seeds without complaint. Agents retry.
 */
#[AsCommand(
    name: 'app:test-instance:provision',
    description: 'Create + seed an isolated e2e database (var/db_{name}.sqlite).',
)]
final class TestInstanceProvisionCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        // Nullable for the same reason the connection factory's copy is: %env(default::…)%
        // resolves to NULL when the variable is absent, and typing it `string` would turn an
        // unset key into a container-build fatal for everyone, including people who never touch
        // this feature.
        private readonly ?string $instanceKey = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instance', null, InputOption::VALUE_REQUIRED,
                'Instance name — the same value the agent sends as X-Test-DB')
            ->addOption('email-prefix', null, InputOption::VALUE_REQUIRED,
                'Local-part prefix for seeded logins', 'ken')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED,
                'Domain for seeded logins', 'restobox.com')
            ->addOption('password', null, InputOption::VALUE_REQUIRED,
                'Password for every seeded login', 'E2ePassw0rd!')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Re-seed even if the database file already exists');
    }

    /**
     * Read-only, so it does not bring another empty database into existence while checking for
     * one. Shares its meaning with TestInstanceConnectionFactory::instanceIsUsable().
     */
    private function hasTables(string $path): bool
    {
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $tables = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' "
                . "AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn();

            return (int) $tables > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Refuses outside dev for the same reason the connection factory does. This command
        // creates databases and writes known-password logins into them; that belongs nowhere
        // near production, and "the operator will be careful" is not a control.
        if ($this->environment !== 'dev') {
            $io->error(sprintf('Refusing to run in "%s" — dev only.', $this->environment));

            return Command::FAILURE;
        }

        // Refuses BEFORE any work when the key that switches the mechanism on is missing, because
        // without it every sub-process below silently targets the shared dev database. The
        // connection factory ignores TEST_INSTANCE unless the key is usable — that is lock 3, and
        // it is the correct behaviour — but this command had no opinion on the key at all, so with
        // TEST_INSTANCE_KEY empty it replayed migrations into var/data_dev.db, seeded
        // ken+admin_{name}@… , ken+customer_{name}@… and an "E2E Seed Co" company into everybody's
        // dev data, created no instance file whatsoever, and still printed
        // `[OK] Instance "{name}" ready: …/var/db_{name}.sqlite`. Every word of that was wrong and
        // nothing in the output said so.
        //
        // Checked before --instance is even read: the failure is in the environment, not in what
        // was typed, and reporting a name problem first would send the operator looking in the
        // wrong place.
        if (!TestInstanceConnectionFactory::isUsableKey($this->instanceKey)) {
            $io->error([
                'Refusing to provision: the per-instance database mechanism is switched off.',
                TestInstanceConnectionFactory::describeKeyRule() . '.',
                'Nothing has been created or seeded. With the key unset, every step below would '
                . 'have run against the shared dev database instead.',
            ]);

            return Command::FAILURE;
        }

        $name = (string) $input->getOption('instance');
        // The same rule the factory applies, deliberately shared rather than re-typed: a name
        // this command accepts but the factory rejects would produce a seeded database no
        // request can ever reach.
        if (!TestInstanceConnectionFactory::isValidInstanceName($name)) {
            $io->error('--instance must match ' . TestInstanceConnectionFactory::describeNameRule());

            return Command::FAILURE;
        }

        $path = $this->projectDir . '/var/db_' . $name . '.sqlite';
        $existed = is_file($path);

        // A file with no tables is not an instance, it is debris. SQLite creates one the moment
        // anything opens that path, so a single request to an unprovisioned instance used to
        // leave one behind — and then this command saw is_file() and refused, telling the
        // operator to use --force to "re-seed" something that had never been seeded. One
        // request bricked the instance and the way out was buried in a warning.
        //
        // The connection factory now refuses to serve an unprovisioned instance over HTTP, so
        // this should stop happening. Handled here as well because debris from an older build,
        // an interrupted run, or a stray sqlite3 invocation is still debris, and the recovery
        // should not need a flag whose name implies it is about to destroy real work.
        $emptyShell = $existed && !$this->hasTables($path);
        if ($emptyShell) {
            $io->text('found an empty database file with no tables — treating it as unprovisioned');
        }

        if ($existed && !$emptyShell && !$input->getOption('force')) {
            $io->warning(sprintf('%s already exists — nothing done. Use --force to re-seed.', $path));

            return Command::SUCCESS;
        }
        if ($existed) {
            // Deleted rather than dropped table by table: the whole point is a known starting
            // state, and a partially-migrated leftover is exactly what that is not.
            unlink($path);
            $io->text($emptyShell ? 'removed the empty file' : 'removed the existing file (--force)');
        }

        $prefix = (string) $input->getOption('email-prefix');
        $domain = (string) $input->getOption('domain');
        $password = (string) $input->getOption('password');
        $adminEmail = sprintf('%s+admin_%s@%s', $prefix, $name, $domain);
        $customerEmail = sprintf('%s+customer_%s@%s', $prefix, $name, $domain);

        $steps = [
            // MIGRATIONS, not doctrine:schema:create. schema:create builds from entity metadata
            // and therefore cannot know about tables that only exist in the migration chain:
            // credit_memo_type, payment_term and shipping_zone have no entity class, so a
            // schema:create instance was missing all three and any test touching them would
            // fail for a reason that looks nothing like the cause. Replaying migrations also
            // matches how the dev database itself was built, which is the point — an agent
            // should be testing this app, not a near-copy of it.
            ['replaying migrations', ['doctrine:migrations:migrate', '--no-interaction',
                '--allow-no-migration']],
            ['seeding regions', ['app:seed-regions', '--no-interaction']],
            ['creating the admin', ['app:create-admin', '--email=' . $adminEmail,
                '--password=' . $password, '--update', '--no-interaction']],
            // The storefront half. Without it every `user` persona cest died at loginAs(): a
            // CustomerUser cannot exist without a Company, and provisioning created neither, so
            // an isolated instance could only ever be tested as staff.
            ['creating the customer', ['app:create-customer', '--email=' . $customerEmail,
                '--password=' . $password, '--company=E2E Seed Co', '--no-interaction']],
        ];

        foreach ($steps as [$label, $args]) {
            $io->text($label . '…');
            $process = new Process(
                array_merge([PHP_BINARY, 'bin/console'], $args, ['--env=dev']),
                $this->projectDir,
                // TEST_INSTANCE, not DATABASE_URL. Overriding DATABASE_URL silently does not
                // work: PHP's variables_order is "GPCS" by default, so $_ENV is empty, Dotenv
                // sees no existing value and replaces the inherited one with .env.local's — and
                // the sub-process then targets the REAL dev database. TEST_INSTANCE appears in
                // no .env file, so nothing overrides it, and the connection factory reads it
                // with getenv() which variables_order does not affect.
                ['TEST_INSTANCE' => $name],
                null,
                600.0
            );
            $process->run();
            if (!$process->isSuccessful()) {
                // The file is left in place on purpose. A half-built instance you can inspect
                // beats a clean slate that discards the reason it failed.
                $io->error($label . ' failed:');
                $io->writeln(trim($process->getErrorOutput() ?: $process->getOutput()));

                return Command::FAILURE;
            }
        }

        $io->success(sprintf('Instance "%s" ready: %s', $name, $path));
        $io->definitionList(
            ['X-Test-DB' => $name],
            ['admin' => $adminEmail],
            ['customer' => $customerEmail],
            ['password' => $password],
        );
        $io->note('Send X-Test-Key (TEST_INSTANCE_KEY) and X-Test-DB with every request, or the '
            . 'normal dev database is served instead.');

        return Command::SUCCESS;
    }
}
