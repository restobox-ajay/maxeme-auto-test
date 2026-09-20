<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use App\Entity\ErrorLog;
use App\EventSubscriber\ErrorLogSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ErrorLogSubscriberTest extends TestCase
{
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function event(Request $request, \Throwable $exception, bool $isMainRequest = true): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ExceptionEvent(
            $kernel,
            $request,
            $isMainRequest ? KernelInterface::MAIN_REQUEST : KernelInterface::SUB_REQUEST,
            $exception,
        );
    }

    private function securityWithUser(?object $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    public function testGetSubscribedEventsMapsKernelException(): void
    {
        self::assertSame(
            ['kernel.exception' => 'onException'],
            ErrorLogSubscriber::getSubscribedEvents(),
        );
    }

    public function testOnExceptionPersistsErrorLogWithRouteAndExceptionDetails(): void
    {
        $request = Request::create('https://shop.example.com/cart/checkout', 'POST');
        $request->attributes->set('_route', 'app_checkout');
        $exception = new \RuntimeException('boom');

        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::callback(function ($log) use (&$captured) {
                $captured = $log;
                return $log instanceof ErrorLog;
            }));
        $em->expects(self::once())->method('flush');

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        self::assertSame('error', $captured->getLevel());
        self::assertSame('app_checkout', $captured->getArea());

        $payload = json_decode($captured->getMessage(), true);
        self::assertSame('POST', $payload['method']);
        self::assertSame('/cart/checkout', $payload['path']);
        self::assertSame('app_checkout', $payload['route']);
        self::assertSame(\RuntimeException::class, $payload['exception']);
        self::assertSame('boom', $payload['message']);
    }

    public function testOnExceptionFallsBackToMethodAndPathWhenNoRouteMatched(): void
    {
        $request = Request::create('https://shop.example.com/no/such/path', 'GET');
        $exception = new \RuntimeException('not found');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        self::assertSame('GET /no/such/path', $captured->getArea());
    }

    public function testOnExceptionCapturesIpAndReferrerOnTheEntity(): void
    {
        $request = Request::create('https://shop.example.com/cart/checkout', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set('referer', 'https://shop.example.com/cart');
        $exception = new \RuntimeException('boom');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        self::assertSame('203.0.113.9', $captured->getIpAddress());
        self::assertSame('https://shop.example.com/cart', $captured->getReferrer());
    }

    public function testOnExceptionResolvesStaffActorFromAdminUser(): void
    {
        $admin = (new AdminUser())->setEmail('ada@example.com');
        $this->setId($admin, 7);

        $request = Request::create('/whatever', 'GET');
        $exception = new \RuntimeException('boom');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser($admin)))->onException($this->event($request, $exception));

        self::assertSame('staff', $captured->getUserType());
        self::assertSame(7, $captured->getUserId());
        self::assertSame('ada@example.com', $captured->getUserEmail());
    }

    public function testOnExceptionResolvesCustomerActorFromCustomerUser(): void
    {
        $customer = (new CustomerUser())->setEmail('cara@example.com');
        $this->setId($customer, 3);

        $request = Request::create('/whatever', 'GET');
        $exception = new \RuntimeException('boom');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser($customer)))->onException($this->event($request, $exception));

        self::assertSame('customer', $captured->getUserType());
        self::assertSame(3, $captured->getUserId());
        self::assertSame('cara@example.com', $captured->getUserEmail());
    }

    public function testOnExceptionFallsBackToGuestWhenNoUserIsAuthenticated(): void
    {
        $request = Request::create('/whatever', 'GET');
        $exception = new \RuntimeException('boom');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        self::assertSame('guest', $captured->getUserType());
        self::assertNull($captured->getUserId());
        self::assertNull($captured->getUserEmail());
    }

    private function makeExceptionWithDeepTrace(int $depth): void
    {
        if ($depth <= 0) {
            throw new \RuntimeException('trace too long');
        }

        $this->makeExceptionWithDeepTrace($depth - 1);
    }

    public function testOnExceptionTruncatesTraceTo20000Characters(): void
    {
        $request = Request::create('/whatever', 'GET');

        $exception = null;
        try {
            $this->makeExceptionWithDeepTrace(1000);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }
        self::assertGreaterThan(20000, strlen($exception->getTraceAsString()), 'test setup must produce a trace longer than the truncation limit');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        $payload = json_decode($captured->getMessage(), true);
        self::assertSame(20000, strlen($payload['trace']));
    }

    public function testOnExceptionIgnoresSubRequests(): void
    {
        $request = Request::create('/whatever', 'GET');
        $exception = new \RuntimeException('sub-request boom');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception, isMainRequest: false));
    }

    public function testOnExceptionSwallowsExceptionsFromPersistingTheLog(): void
    {
        $request = Request::create('/whatever', 'GET');
        $exception = new \RuntimeException('original failure');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('db down'));
        $em->expects(self::never())->method('flush');

        (new ErrorLogSubscriber($em, $this->securityWithUser(null)))->onException($this->event($request, $exception));

        $this->addToAssertionCount(1);
    }
}
