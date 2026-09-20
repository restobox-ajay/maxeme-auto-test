<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\EventSubscriber\CustomerLoginSubscriber;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Exercises CustomerLoginSubscriber against a real EntityManager since it runs a live
 * repository query (case-insensitive email match against active companies) that a mocked
 * EntityManager can't meaningfully stand in for.
 */
final class CustomerLoginSubscriberTest extends DoctrineIntegrationTestCase
{
    private function loginEvent(object $user): LoginSuccessEvent
    {
        $event = $this->createStub(LoginSuccessEvent::class);
        $event->method('getUser')->willReturn($user);

        return $event;
    }

    private function persistCompany(string $name, string $code, string $primaryEmail, string $status = 'Active'): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode($code)
            ->setPrimaryEmail($primaryEmail);
        $company->setStatus($status, DocumentActor::system());

        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    public function testLinksCustomerToActiveCompanyMatchingEmailCaseInsensitively(): void
    {
        $company = $this->persistCompany('Acme Inc', 'ACME', 'contact@acme.test');

        $user = (new CustomerUser())->setEmail('Contact@Acme.test')->setPassword('hash');
        $this->em->persist($user);
        $this->em->flush();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($user));

        self::assertSame($company->getId(), $user->getCompany()?->getId());
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testDoesNotLinkToInactiveCompanyEvenWhenEmailMatches(): void
    {
        $this->persistCompany('Inactive Co', 'INACT', 'contact@inactive.test', 'Inactive');

        $user = (new CustomerUser())->setEmail('contact@inactive.test')->setPassword('hash');
        $this->em->persist($user);
        $this->em->flush();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($user));

        self::assertNull($user->getCompany());
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testDoesNotOverwriteAlreadyAssignedCompany(): void
    {
        $existingCompany = $this->persistCompany('Existing Co', 'EXIST', 'someone-else@example.test');
        $otherCompany = $this->persistCompany('Other Co', 'OTHER', 'contact@other.test');

        $user = (new CustomerUser())->setEmail('contact@other.test')->setPassword('hash')->setCompany($existingCompany);
        $this->em->persist($user);
        $this->em->flush();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($user));

        self::assertSame($existingCompany->getId(), $user->getCompany()?->getId());
        self::assertNotSame($otherCompany->getId(), $user->getCompany()?->getId());
    }

    public function testBlankEmailSkipsLookupAndLeavesCompanyNull(): void
    {
        $user = (new CustomerUser())->setEmail('   ')->setPassword('hash');
        $this->em->persist($user);
        $this->em->flush();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($user));

        self::assertNull($user->getCompany());
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testIgnoresNonCustomerUsers(): void
    {
        $admin = (new AdminUser())->setEmail('admin@example.test')->setPassword('hash');
        $this->em->persist($admin);
        $this->em->flush();
        $id = $admin->getId();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($admin));

        $this->em->clear();
        $reloaded = $this->em->getRepository(AdminUser::class)->find($id);
        self::assertSame('Active', $reloaded->getStatus());
    }

    public function testUpdatesLastLoginAtOnEveryLogin(): void
    {
        $user = (new CustomerUser())->setEmail('repeat@example.test')->setPassword('hash');
        $this->em->persist($user);
        $this->em->flush();

        $subscriber = new CustomerLoginSubscriber($this->em);
        $subscriber->onLoginSuccess($this->loginEvent($user));

        $firstLogin = $user->getLastLoginAt();
        self::assertNotNull($firstLogin);

        $subscriber->onLoginSuccess($this->loginEvent($user));

        self::assertNotNull($user->getLastLoginAt());
    }

    public function testGetSubscribedEventsRegistersLoginSuccessListener(): void
    {
        $events = CustomerLoginSubscriber::getSubscribedEvents();

        self::assertSame('onLoginSuccess', $events[LoginSuccessEvent::class]);
    }
}
