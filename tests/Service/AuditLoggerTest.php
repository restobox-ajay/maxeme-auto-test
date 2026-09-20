<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\CustomerUser;
use App\Service\AuditLogger;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AuditLoggerTest extends TestCase
{
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    /**
     * @param string|null $clientIp the IP the request came from, or null for console/queued work,
     *                              which is the case these unit tests model
     *
     * The Security stub is wrapped in a real DocumentActorResolver rather than a mocked one
     * (#539 stage 2): the logger stopped reading Security directly, but the resolution it now
     * delegates to is the same one it used to perform inline, so these tests keep exercising it
     * end to end — which is what makes the actorType/actorId/actorName assertions below still
     * mean what they meant before the extraction.
     */
    private function makeLogger(EntityManagerInterface $em, Security $security, ?string $clientIp = null): AuditLogger
    {
        $requestStack = new RequestStack();
        if ($clientIp !== null) {
            $request = Request::create('/');
            $request->server->set('REMOTE_ADDR', $clientIp);
            $requestStack->push($request);
        }

        return new AuditLogger($em, new DocumentActorResolver($security), $requestStack);
    }

    public function testTheClientIpIsRecordedWhenThereIsARequest(): void
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void { $persisted = $entity; });

        $this->makeLogger($em, $this->createMock(Security::class), '203.0.113.9')
            ->log('security', 'Thing', 1, 'did_something', 'summary');

        self::assertSame('203.0.113.9', $persisted?->getIpAddress());
    }

    public function testThereIsNoIpWhenThereIsNoRequest(): void
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void { $persisted = $entity; });

        // Console commands, queued jobs and migrations audit too; inventing an IP for them would be
        // worse than recording none.
        $this->makeLogger($em, $this->createMock(Security::class))
            ->log('security', 'Thing', 1, 'did_something', 'summary');

        self::assertNull($persisted?->getIpAddress());
    }

    public function testLogWithAdminActorPersistsAndFlushesImmediately(): void
    {
        $admin = (new AdminUser())->setFirstName('Ada')->setLastName('Admin')->setEmail('ada@example.com');
        $this->setId($admin, 7);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($admin);

        /** @var AuditLog|null $captured */
        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::callback(function ($log) use (&$captured) {
                $captured = $log;
                return $log instanceof AuditLog;
            }));
        $em->expects(self::once())->method('flush');

        $logger = $this->makeLogger($em, $security);
        $logger->log('Admin', 'Company', 42, 'updated', 'Updated the company', ['status' => 'Active'], ['status' => 'Disabled']);

        self::assertInstanceOf(AuditLog::class, $captured);
        self::assertSame('admin', $captured->getActorType());
        self::assertSame(7, $captured->getActorId());
        self::assertSame('Ada Admin (ada@example.com)', $captured->getActorName());
        self::assertSame('Admin', $captured->getArea());
        self::assertSame('Company', $captured->getEntityType());
        self::assertSame(42, $captured->getEntityId());
        self::assertSame('updated', $captured->getAction());
        self::assertSame('Updated the company', $captured->getSummary());
        self::assertSame('{"status":"Active"}', $captured->getDataBefore());
        self::assertSame('{"status":"Disabled"}', $captured->getDataAfter());
    }

    public function testLogWithCustomerActorSetsCustomerActorType(): void
    {
        $customer = (new CustomerUser())->setFirstName('Cara')->setLastName('Customer')->setEmail('cara@example.com');
        $this->setId($customer, 3);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($customer);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->log('Storefront', 'Order', 1, 'placed', 'Placed an order');

        self::assertSame('customer', $captured->getActorType());
        self::assertSame(3, $captured->getActorId());
        self::assertSame('Cara Customer (cara@example.com)', $captured->getActorName());
    }

    public function testLogWithNoAuthenticatedUserFallsBackToSystemActor(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->log('System', 'Order', null, 'expired', 'Order expired automatically');

        self::assertSame('system', $captured->getActorType());
        self::assertNull($captured->getActorId());
        self::assertSame('System', $captured->getActorName());
        self::assertNull($captured->getEntityId());
    }

    public function testActorNameFallsBackToEmailWhenNoNamePresent(): void
    {
        $admin = (new AdminUser())->setEmail('noname@example.com');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($admin);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->log('Admin', 'Company', null, 'updated', 'Updated the company');

        self::assertSame('noname@example.com', $captured->getActorName());
    }

    public function testLogWithNullDataLeavesDataFieldsNull(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->log('System', 'Order', null, 'expired', 'Order expired automatically');

        self::assertNull($captured->getDataBefore());
        self::assertNull($captured->getDataAfter());
    }

    /**
     * #302: an oversized field value used to be copied whole into dataBefore/dataAfter on every
     * edit — the audit trail was the one place the bloat could never be corrected, since a change
     * record is never edited after the fact. A bounded excerpt with the true length noted answers
     * "what changed" without carrying the whole payload.
     */
    public function testLogTruncatesAnOversizedValueAndNotesItsTrueLength(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured): void { $captured = $log; });

        $huge = str_repeat('x', 1_000_000);
        $logger = $this->makeLogger($em, $security);
        $logger->log('Admin', 'SalesOrder', 1, 'updated', 'Updated the order', ['poNumber' => 'PO-1'], ['poNumber' => $huge]);

        $after = json_decode((string) $captured->getDataAfter(), true);
        self::assertLessThan(1000, strlen($after['poNumber']), 'the stored value should be a bounded excerpt, not the full megabyte string');
        self::assertStringContainsString('1000000 characters total', $after['poNumber']);
        self::assertStringStartsWith(str_repeat('x', 100), $after['poNumber'], 'the excerpt should be a genuine prefix of the value, not just a placeholder');
    }

    public function testLogLeavesAShortValueUntouched(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured): void { $captured = $log; });

        $logger = $this->makeLogger($em, $security);
        $logger->log('Admin', 'SalesOrder', 1, 'updated', 'Updated the order', null, ['poNumber' => 'PO-123']);

        self::assertSame('{"poNumber":"PO-123"}', $captured->getDataAfter());
    }

    public function testFlushQueuedAlsoTruncatesAnOversizedValue(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured): void { $captured[] = $log; });

        $huge = str_repeat('y', 1_000_000);
        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('SalesOrder', 1, 'updated', null, ['poNumber' => $huge]);
        $logger->flushQueued();

        $after = json_decode((string) $captured[0]->getDataAfter(), true);
        self::assertLessThan(1000, strlen($after['poNumber']));
        self::assertStringContainsString('1000000 characters total', $after['poNumber']);
    }

    public function testQueueEntityChangeDoesNotPersistOrFlushUntilFlushQueuedIsCalled(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', 1, 'created');
    }

    public function testFlushQueuedWithEmptyQueueDoesNothing(): void
    {
        $security = $this->createStub(Security::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $logger = $this->makeLogger($em, $security);
        $logger->flushQueued();
    }

    public function testFlushQueuedBuildsSummaryForLabelAndIdKnownAtQueueTime(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });
        $em->expects(self::once())->method('flush');

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', 5, 'updated', null, null, 'Acme Inc');
        $logger->flushQueued();

        self::assertCount(1, $captured);
        self::assertSame("Company 'Acme Inc' #5 updated.", $captured[0]->getSummary());
        self::assertSame(5, $captured[0]->getEntityId());
        self::assertSame('System', $captured[0]->getArea());
    }

    public function testFlushQueuedBuildsSummaryForLabelOnlyWhenIdUnresolvable(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', null, 'created', null, null, 'Acme Inc');
        $logger->flushQueued();

        self::assertSame("Company 'Acme Inc' created.", $captured[0]->getSummary());
        self::assertNull($captured[0]->getEntityId());
    }

    public function testFlushQueuedBuildsSummaryForIdOnlyWhenNoLabel(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', 9, 'deleted');
        $logger->flushQueued();

        self::assertSame('Company #9 deleted.', $captured[0]->getSummary());
    }

    public function testFlushQueuedBuildsSummaryForNeitherLabelNorId(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', null, 'created');
        $logger->flushQueued();

        self::assertSame('Company created.', $captured[0]->getSummary());
    }

    public function testFlushQueuedReResolvesIdFromEntityWhenNotKnownAtQueueTime(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        // Simulates an entity that was still unpersisted (no id) at queue time, whose id
        // Doctrine has since assigned on the same object by the time flushQueued() runs.
        $entity = new class {
            public function getId(): int
            {
                return 99;
            }
        };

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', null, 'created', null, null, 'Acme Inc', $entity);
        $logger->flushQueued();

        self::assertSame(99, $captured[0]->getEntityId());
        self::assertSame("Company 'Acme Inc' #99 created.", $captured[0]->getSummary());
    }

    public function testFlushQueuedFlushesOnceForMultipleQueuedItemsAndClearsQueueAfter(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $captured = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured[] = $log;
        });
        $em->expects(self::once())->method('flush');

        $logger = $this->makeLogger($em, $security);
        $logger->queueEntityChange('Company', 1, 'created');
        $logger->queueEntityChange('Order', 2, 'placed');
        $logger->flushQueued();

        self::assertCount(2, $captured);

        // A second call with an empty queue must not persist or flush again.
        $logger->flushQueued();
    }
}
