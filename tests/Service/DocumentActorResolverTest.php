<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use App\Service\DocumentActor;
use App\Service\DocumentActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The one place "who is signed in" becomes a DocumentActor (#539 stage 2).
 *
 * Two names, and this file exists mostly to pin the fact that they are different. The audit log has
 * written "First Last (email)" since long before #539; the document timelines have written
 * "First Last, email (id)". Collapsing them into one shape would silently rewrite one of the two
 * columns for every row written afterwards, so both are carried and each caller keeps what it had.
 */
final class DocumentActorResolverTest extends TestCase
{
    public function testAnAdminIsResolvedWithBothNameShapes(): void
    {
        $actor = $this->resolverFor($this->adminUser(7, 'Priya', 'Nair', 'priya@example.test'))->resolve();

        self::assertSame(DocumentActor::TYPE_ADMIN, $actor->type);
        self::assertSame(7, $actor->id);
        self::assertSame('Priya Nair (priya@example.test)', $actor->auditName);
        self::assertSame('Priya Nair, priya@example.test (7)', $actor->displayName);
    }

    public function testACustomerIsResolvedTheSameWay(): void
    {
        $actor = $this->resolverFor($this->customerUser(12, 'Sam', 'Okoro', 'sam@buyer.test'))->resolve();

        self::assertSame(DocumentActor::TYPE_CUSTOMER, $actor->type);
        self::assertSame(12, $actor->id);
        self::assertSame('Sam Okoro (sam@buyer.test)', $actor->auditName);
        self::assertSame('Sam Okoro, sam@buyer.test (12)', $actor->displayName);
    }

    public function testANamelessUserFallsBackToTheEmail(): void
    {
        $actor = $this->resolverFor($this->adminUser(3, null, null, 'ops@example.test'))->resolve();

        self::assertSame('ops@example.test', $actor->auditName);
        self::assertSame('ops@example.test (3)', $actor->displayName);
    }

    public function testNobodySignedInIsTheSystemActor(): void
    {
        $actor = $this->resolverFor(null)->resolve();

        self::assertSame(DocumentActor::TYPE_SYSTEM, $actor->type);
        self::assertNull($actor->id);
        self::assertSame('System', $actor->auditName);
        self::assertSame('System', $actor->displayName);
    }

    public function testAnUnrecognisedUserTypeIsAlsoTheSystemActor(): void
    {
        // Neither an AdminUser nor a CustomerUser — an API credential, a future user class. Falling
        // back is right; guessing an actor type an id could not back up is not.
        $actor = $this->resolverFor($this->createStub(UserInterface::class))->resolve();

        self::assertSame(DocumentActor::TYPE_SYSTEM, $actor->type);
    }

    public function testAnAutomationIsNeitherAUserNorSystem(): void
    {
        // The distinction #539 requires: an admin looking at a voided order must be able to see at a
        // glance that a scheduled job did it, which a value that also means "we did not know" cannot
        // say.
        $actor = DocumentActor::automation('Stale unpaid order sweep');

        self::assertSame(DocumentActor::TYPE_AUTOMATION, $actor->type);
        self::assertNotSame(DocumentActor::TYPE_SYSTEM, $actor->type);
        self::assertNull($actor->id);
        self::assertSame('Stale unpaid order sweep (automated)', $actor->auditName);
        self::assertSame('Stale unpaid order sweep (automated)', $actor->displayName);
    }

    public function testANamedActorCarriesTheNameItWasGiven(): void
    {
        // For EstimateConversionService, which holds a display name and no security context.
        $actor = DocumentActor::named('Ada Admin, ada@example.test (2)');

        self::assertSame('Ada Admin, ada@example.test (2)', $actor->displayName);
        self::assertSame('Ada Admin, ada@example.test (2)', $actor->auditName);
        // The identity behind the name is genuinely unknown here, so no id is claimed for it.
        self::assertNull($actor->id);
    }

    public function testABlankNameIsTheSystemActor(): void
    {
        self::assertSame('System', DocumentActor::named('   ')->displayName);
    }

    private function resolverFor(?UserInterface $user): DocumentActorResolver
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new DocumentActorResolver($security);
    }

    private function adminUser(int $id, ?string $first, ?string $last, string $email): AdminUser
    {
        $user = (new AdminUser())->setEmail($email);
        if ($first !== null) {
            $user->setFirstName($first);
        }
        if ($last !== null) {
            $user->setLastName($last);
        }
        $this->setId($user, $id);

        return $user;
    }

    private function customerUser(int $id, ?string $first, ?string $last, string $email): CustomerUser
    {
        $user = (new CustomerUser())->setEmail($email);
        if ($first !== null) {
            $user->setFirstName($first);
        }
        if ($last !== null) {
            $user->setLastName($last);
        }
        $this->setId($user, $id);

        return $user;
    }

    /** Ids are database-assigned, and the actor's id is part of what this resolves. */
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
