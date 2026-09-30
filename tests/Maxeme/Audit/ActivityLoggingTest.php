<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Audit;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Audit\AuditLogEnricher;
use App\Maxeme\Audit\SecurityActivitySubscriber;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Security\StaffRole;
use App\Tests\DoctrineIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Every kind of action lands in the Activity Log with who did it, their role and what changed; and
 * errors that are only logged land in the Error Log. A new record type or named action that skips
 * the log fails here (see also testEveryShopRecordTypeHasAnArea).
 */
final class ActivityLoggingTest extends DoctrineIntegrationTestCase
{
    private AdminUser $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = (new AdminUser())->setEmail('reception@example.invalid')->setPassword('x')->setRoles([StaffRole::Receptionist->value]);
        $this->em->persist($this->receptionist);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($this->receptionist, 'admin', $this->receptionist->getRoles()),
        );
    }

    public function testEveryShopRecordTypeHasAnArea(): void
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!str_starts_with($metadata->getName(), 'App\\Maxeme\\Entity\\') || $metadata->isMappedSuperclass) {
                continue;
            }
            $shortName = $metadata->getReflectionClass()->getShortName();
            self::assertNotNull(AuditLogEnricher::areaFor($shortName), sprintf('%s has no Activity Log area in AuditLogEnricher::ENTITY_AREAS.', $shortName));
        }
    }

    public function testRecordChangesAreLoggedWithTheRoleTheAreaAndTheChanges(): void
    {
        $client = (new Client())->setFirstName('Ada')->setLastName('Lovelace');
        $this->em->persist($client);
        $this->em->flush();

        $client->setPhone4('604 555 0199');
        $this->em->flush();

        $this->em->remove($client);
        $this->em->flush();

        $rows = $this->rows('Client');
        self::assertSame(['created', 'updated', 'deleted'], array_map(static fn (AuditLog $log): string => $log->getAction(), $rows));

        foreach ($rows as $row) {
            self::assertSame('Receptionist', $row->getActorRole());
            self::assertSame('People', $row->getArea());
            self::assertSame($this->receptionist->getId(), $row->getActorId());
        }

        self::assertSame(['phone4' => '604 555 0199'], array_intersect_key(json_decode((string) $rows[1]->getDataAfter(), true), ['phone4' => true]));
    }

    public function testNamedActionsAreLoggedWithTheRole(): void
    {
        $client = (new Client())->setFirstName('Ada');
        $invoice = Invoice::forClient($client, null, 5, 7);
        $this->em->persist($client);
        $this->em->persist($invoice);
        $this->em->flush();
        $activity = self::getContainer()->get(ActivityRecorder::class);

        $activity->downloaded($invoice, DocumentKind::WorkOrder);
        $activity->emailed($invoice, DocumentKind::Invoice, 'someone@example.invalid');
        $activity->signedIn();
        $activity->exported('people', 'Client', 'clients.csv', 12, 'all clients');

        $actions = [];
        foreach ($this->em->getRepository(AuditLog::class)->findBy(['actorRole' => 'Receptionist'], ['id' => 'ASC']) as $row) {
            $actions[$row->getAction()] = $row->getArea();
        }

        self::assertSame('Work Order', $actions[ActivityRecorder::DOWNLOADED] ?? null);
        self::assertSame('Accounting', $actions[ActivityRecorder::EMAILED] ?? null);
        self::assertSame(ActivityRecorder::AREA_SIGN_IN, $actions[ActivityRecorder::SIGNED_IN] ?? null);
        self::assertSame('People', $actions[ActivityRecorder::EXPORTED] ?? null);
    }

    public function testARefusedPageIsLogged(): void
    {
        $request = Request::create('/admin/clients');
        $event = new ExceptionEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException('Missing permission "/people/view".'));

        self::getContainer()->get(SecurityActivitySubscriber::class)->onException($event);

        $row = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => ActivityRecorder::ACCESS_DENIED]);
        self::assertNotNull($row);
        self::assertSame('Receptionist', $row->getActorRole());
        self::assertStringContainsString('/admin/clients', $row->getSummary());
    }

    public function testLoggedErrorsLandInTheErrorLog(): void
    {
        self::getContainer()->get(LoggerInterface::class)->error('Could not email {document}.', ['document' => 'Invoice-1.pdf', 'exception' => new \RuntimeException('SMTP down')]);
        self::getContainer()->get(LoggerInterface::class)->warning('Only a warning.');

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT level, message, user_email FROM error_log');
        self::assertCount(1, $rows, 'error and above only');
        self::assertSame('error', $rows[0]['level']);
        self::assertSame('reception@example.invalid', $rows[0]['user_email']);

        $message = json_decode($rows[0]['message'], true);
        self::assertSame('Could not email Invoice-1.pdf.', $message['message']);
        self::assertSame('SMTP down', $message['exception_message']);
    }

    /** @return list<AuditLog> */
    private function rows(string $entityType): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(['entityType' => $entityType], ['id' => 'ASC']);
    }
}
