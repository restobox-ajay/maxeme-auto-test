<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLog;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLog;
use App\Tests\DoctrineIntegrationTestCase;
use App\Service\DocumentActor;

/**
 * Exercises AuditLogSubscriber through the real onFlush/postFlush Doctrine listener path —
 * persisting/updating/deleting an AdminUser and flushing — rather than calling its private
 * methods directly, so these tests also prove the listener is actually wired up to fire on a
 * real flush (see InventoryReservationReconcilerTest for the same rationale).
 */
final class AuditLogSubscriberTest extends DoctrineIntegrationTestCase
{
    private function findLogsFor(string $entityType, string $action): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(['entityType' => $entityType, 'action' => $action]);
    }

    public function testCreatingEntityLogsRedactedPasswordAndTokenWithEmailLabel(): void
    {
        $user = (new AdminUser())
            ->setEmail('new.admin@example.com')
            ->setPassword('hashed-secret-value')
            ->setResetToken('super-secret-token');

        $this->em->persist($user);
        $this->em->flush();

        $logs = $this->findLogsFor('AdminUser', 'created');
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame($user->getId(), $log->getEntityId());
        self::assertNull($log->getDataBefore());
        self::assertStringContainsString("AdminUser 'new.admin@example.com' #" . $user->getId() . ' created.', $log->getSummary());

        $after = json_decode((string) $log->getDataAfter(), true);
        self::assertSame('***REDACTED***', $after['password']);
        self::assertSame('***REDACTED***', $after['resetToken']);
        self::assertSame('new.admin@example.com', $after['email']);

        // No logged-in admin/customer at the time of this flush — falls back to 'system'.
        self::assertSame('system', $log->getActorType());
        self::assertNull($log->getActorId());
        self::assertSame('System', $log->getActorName());
    }

    public function testUpdatingEntityLogsBeforeAndAfterWithRedactedPassword(): void
    {
        $user = (new AdminUser())->setEmail('update.me@example.com')->setPassword('old-hash');
        $this->em->persist($user);
        $this->em->flush();

        $user->setPassword('new-hash');
        $user->setStatus('Inactive', DocumentActor::system());
        $this->em->flush();

        $logs = $this->findLogsFor('AdminUser', 'updated');
        self::assertCount(1, $logs);

        $log = $logs[0];
        $before = json_decode((string) $log->getDataBefore(), true);
        $after = json_decode((string) $log->getDataAfter(), true);

        self::assertSame('***REDACTED***', $before['password']);
        self::assertSame('***REDACTED***', $after['password']);
        self::assertSame('Active', $before['status']);
        self::assertSame('Inactive', $after['status']);
    }

    public function testDeletingEntityLogsRedactedDataBefore(): void
    {
        $user = (new AdminUser())->setEmail('delete.me@example.com')->setPassword('doomed-hash');
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();

        $this->em->remove($user);
        $this->em->flush();

        $logs = $this->findLogsFor('AdminUser', 'deleted');
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame($id, $log->getEntityId());
        self::assertNull($log->getDataAfter());

        $before = json_decode((string) $log->getDataBefore(), true);
        self::assertSame('***REDACTED***', $before['password']);
        self::assertSame('delete.me@example.com', $before['email']);
    }

    public function testAuditLogEntityItselfIsExcludedFromLogging(): void
    {
        $manualLog = (new AuditLog())
            ->setActorType('system')
            ->setActorName('System')
            ->setArea('Test')
            ->setEntityType('Whatever')
            ->setAction('created')
            ->setSummary('manual entry');

        $this->em->persist($manualLog);
        $this->em->flush();

        self::assertCount(0, $this->findLogsFor('AuditLog', 'created'));
    }

    /**
     * #273: EstimateLog was missing from EXCLUDED, so every quote note produced both the note and an
     * audit row saying a note had been written, while the identical order note produced only the
     * note. Both document logs are asserted here so the pair cannot drift apart again.
     */
    public function testBothDocumentLogsAreExcludedFromAuditLogging(): void
    {
        $company = (new Company())->setName('Excluded Co')->setCode('EXCL');
        $this->em->persist($company);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-EXCL-1');
        $estimate->setStatus('Draft', DocumentActor::system());
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-EXCL-1');
        $this->em->persist($estimate);
        $this->em->persist($order);
        $this->em->flush();

        $this->em->persist((new EstimateLog())->setEstimate($estimate)->setComment('Quote note.'));
        $this->em->persist((new SalesOrderLog())->setOrder($order)->setComment('Order note.'));
        $this->em->flush();

        self::assertCount(0, $this->findLogsFor('EstimateLog', 'created'));
        self::assertCount(0, $this->findLogsFor('SalesOrderLog', 'created'));
    }
}
