<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Controller\Admin\ImportLogController;
use App\Entity\AdminUser;
use App\Entity\ImportRun;
use App\Entity\ImportRunRow;
use App\Enum\ImportRunStatus;
use App\Service\Import\ImportLock;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Adversarial coverage of the two mechanisms `import:process`/ImportLock exist for — one queue
 * drained by exactly one process at a time, and an operator's ability to break a jam — against REAL
 * separate OS processes and real, actually-committed rows.
 *
 * Deliberately NOT a Codeception Cest, despite covering an admin action: tests/Functional.suite.yml
 * wraps every Cest in a transaction rolled back after the test, so a row `haveInRepository()` writes
 * is invisible to a genuinely separate process reading the same SQLite file — exactly what a spawned
 * `import:process` child is. DoctrineIntegrationTestCase persists for real (no rollback wrapper),
 * which is the whole reason it exists — see its own docblock — and is what makes this test able to
 * spawn a real child and watch it actually pick the row up. AdminImportLogCest covers everything
 * about this screen that doesn't need a second process: every UI element, filters, the role gate.
 */
final class ImportProcessCommandTest extends DoctrineIntegrationTestCase
{
    private ImportLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink('/tmp/wholesale-imports.lock');
        $this->lock = new ImportLock();
    }

    protected function tearDown(): void
    {
        @unlink('/tmp/wholesale-imports.lock');
        parent::tearDown();
    }

    private function projectDir(): string
    {
        return (string) self::getContainer()->getParameter('kernel.project_dir');
    }

    private function importProcessCommand(): Process
    {
        return new Process(
            ['php', $this->projectDir() . '/bin/console', 'import:process', '--env=test', '--no-interaction'],
            $this->projectDir(),
        );
    }

    private function loginAsTechSupport(): void
    {
        $admin = (new AdminUser())
            ->setEmail('import-adversarial-test@example.test')
            ->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword('unused-hash');
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($admin, 'admin', $admin->getRoles()),
        );

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function queueFixtureRun(string $sku, ?float $sleepSeconds = null): ImportRun
    {
        $run = new ImportRun('test_fixture_import', 'TestFixtureEntity');
        $run->setDescription('Adversarial test run: ' . $sku);
        $run->setValidationOnly(false);
        $run->setSource('cli');
        $run->setStatus(ImportRunStatus::Queued);
        $run->setQueuedAt(new \DateTimeImmutable());

        $mapped = ['sku' => $sku];
        if ($sleepSeconds !== null) {
            $mapped['sleep_seconds'] = (string) $sleepSeconds;
        }
        $run->addRow(new ImportRunRow($run, 1, $mapped, $mapped));
        $run->setRowCount(1);

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    public function testFlockBlocksASecondInvocationAndLeavesTheQueuedRunUntouched(): void
    {
        $run = $this->queueFixtureRun('FIXTURE-FLOCK');

        self::assertTrue($this->lock->tryAcquire(), 'the test needs to hold the lock itself first');

        $blocked = $this->importProcessCommand();
        $blocked->run();

        self::assertStringContainsString('Another import is already running', $blocked->getOutput());

        $this->em->clear();
        $stillQueued = $this->em->find(ImportRun::class, $run->getId());
        self::assertSame(
            ImportRunStatus::Queued,
            $stillQueued->getStatus(),
            'a blocked invocation must never touch the run a different holder is protecting',
        );

        $this->lock->release();

        $real = $this->importProcessCommand();
        $real->run();

        $this->em->clear();
        $finished = $this->em->find(ImportRun::class, $run->getId());
        self::assertSame(ImportRunStatus::Completed, $finished->getStatus());
        self::assertSame(1, $finished->getExecutedCount());
        self::assertSame(0, $finished->getErrorCount());
    }

    public function testKillTerminatesTheRealHolderProcessAndLeavesAnUnrelatedQueuedRunUntouched(): void
    {
        $targetRun = $this->queueFixtureRun('FIXTURE-SLEEP', 5.0);
        $controlRun = $this->queueFixtureRun('FIXTURE-CONTROL');

        $child = $this->importProcessCommand();
        $child->start();

        $deadline = microtime(true) + 5.0;
        $started = null;
        do {
            $this->em->clear();
            $reloaded = $this->em->find(ImportRun::class, $targetRun->getId());
            if ($reloaded->getStatus() === ImportRunStatus::Started) {
                $started = $reloaded;
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::assertNotNull($started, 'the spawned import:process child never picked the run up in time');
        self::assertTrue($this->lock->isLocked());
        $holder = $this->lock->getHolderInfo();
        self::assertNotNull($holder);
        self::assertTrue($child->isRunning(), 'the process the lock claims to hold must actually be alive');

        $this->loginAsTechSupport();
        $response = self::getContainer()->get(ImportLogController::class)->kill($this->em, $this->lock);
        self::assertSame(302, $response->getStatusCode());

        $deadline = microtime(true) + 3.0;
        while ($child->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertFalse($child->isRunning(), 'kill must actually terminate the real process, not just the DB row');
        self::assertFalse($this->lock->isLocked(), 'the lock must be released so the next import can proceed');

        $this->em->clear();
        $killedRun = $this->em->find(ImportRun::class, $targetRun->getId());
        self::assertSame(ImportRunStatus::Completed, $killedRun->getStatus());
        self::assertSame('Killed by admin.', $killedRun->getError());

        $stillControl = $this->em->find(ImportRun::class, $controlRun->getId());
        self::assertSame(
            ImportRunStatus::Queued,
            $stillControl->getStatus(),
            'killing one run must never touch an unrelated queued run',
        );

        try {
            // Expected: Process::wait() throws ProcessSignaledException for a SIGKILL'd process by
            // default. Only here to reap the child rather than leave it a zombie; the kill itself
            // was already verified above.
            $child->wait();
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException) {
        }
    }

    public function testKillingWithNothingRunningFailsGracefullyInsteadOfCrashing(): void
    {
        $this->loginAsTechSupport();

        $response = self::getContainer()->get(ImportLogController::class)->kill($this->em, $this->lock);

        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($this->lock->isLocked());
    }

    public function testProcessQueueButtonActuallyDrainsAQueuedRun(): void
    {
        $run = $this->queueFixtureRun('FIXTURE-BUTTON');

        $this->loginAsTechSupport();
        $response = self::getContainer()->get(ImportLogController::class)
            ->processQueue(self::getContainer()->get(\App\Service\Import\ImportQueueSpawner::class));
        self::assertSame(302, $response->getStatusCode());

        $deadline = microtime(true) + 5.0;
        do {
            $this->em->clear();
            $reloaded = $this->em->find(ImportRun::class, $run->getId());
            if ($reloaded->getStatus() === ImportRunStatus::Completed) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::assertSame(ImportRunStatus::Completed, $reloaded->getStatus(), 'the Process Queue button must actually drain the queue, not just redirect');
        self::assertSame(1, $reloaded->getExecutedCount());
    }
}
