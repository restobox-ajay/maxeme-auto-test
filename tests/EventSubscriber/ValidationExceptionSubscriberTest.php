<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ValidationExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/** Mirrors CsrfProtectionSubscriberTest — same response-shape contract, different trigger. */
final class ValidationExceptionSubscriberTest extends TestCase
{
    private function violations(string ...$messages): ConstraintViolationList
    {
        $list = new ConstraintViolationList();
        foreach ($messages as $message) {
            $list->add(new ConstraintViolation($message, null, [], null, '', null));
        }

        return $list;
    }

    private function event(Request $request, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    public function testIgnoresOtherExceptions(): void
    {
        $event = $this->event(Request::create('/admin/thing', 'POST'), new \RuntimeException('unrelated'));

        (new ValidationExceptionSubscriber())->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testJoinsAllViolationMessagesForABrowserFormPost(): void
    {
        $request = Request::create('http://admin.example.com/admin/redirect/create', 'POST');
        $request->headers->set('Accept', 'text/html');
        $request->headers->set('referer', 'http://admin.example.com/admin/redirect/create');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = $this->event($request, new ValidationFailedException(
            null,
            $this->violations('Source URL is required.', 'A valid destination type is required.'),
        ));

        (new ValidationExceptionSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('http://admin.example.com/admin/redirect/create', $response->getTargetUrl());

        $flashes = $request->getSession()->getFlashBag()->get('error');
        self::assertSame(['Source URL is required. A valid destination type is required.'], $flashes);
    }

    public function testDeduplicatesRepeatedMessages(): void
    {
        $request = Request::create('/admin/redirect/create', 'POST');
        $request->headers->set('Accept', 'text/html');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = $this->event($request, new ValidationFailedException(
            null,
            $this->violations('Source URL is required.', 'Source URL is required.'),
        ));

        (new ValidationExceptionSubscriber())->onKernelException($event);

        self::assertSame(['Source URL is required.'], $request->getSession()->getFlashBag()->get('error'));
    }

    public function testRejectionIsJsonForXmlHttpRequest(): void
    {
        $request = Request::create('/admin/redirect/create', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $event = $this->event($request, new ValidationFailedException(null, $this->violations('Source URL is required.')));

        (new ValidationExceptionSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertFalse($payload['ok']);
        self::assertSame('Source URL is required.', $payload['message']);
        self::assertSame(['Source URL is required.'], $payload['errors']);
    }

    /** Never bounce a rejected request onward to an attacker-supplied Referer. */
    public function testRejectionIgnoresOffSiteReferer(): void
    {
        $request = Request::create('http://admin.example.com/admin/redirect/create', 'POST');
        $request->headers->set('Accept', 'text/html');
        $request->headers->set('referer', 'https://evil.example/hook');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = $this->event($request, new ValidationFailedException(null, $this->violations('Source URL is required.')));

        (new ValidationExceptionSubscriber())->onKernelException($event);

        self::assertSame('/', $event->getResponse()->getTargetUrl());
    }
}
