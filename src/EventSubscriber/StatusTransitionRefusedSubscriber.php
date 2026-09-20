<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\StatusTransitionRefused;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * THE handling point for a refused status move (handoff section 6).
 *
 * The owner's condition on throwing was exact: *"a throw works for me (as long as its caught)"*,
 * and then *"im always nervous someone forgets to catch a throw."* So this does not rely on anybody
 * remembering. An uncaught `DomainException` is a 500 — a blank error page that tells the person
 * nothing and a log line that says only that something threw, which is the opposite of loud.
 *
 * With this listener, forgetting to catch locally degrades to *the right message appeared*.
 * Catching locally becomes an optimisation for a nicer screen rather than a requirement anybody can
 * fail to meet, and the refusal wording is written once on the exception instead of each controller
 * inventing its own — the same argument as the shared colour classes.
 *
 * The alternative considered and rejected was a conformance test asserting every caller wraps the
 * call. That is a hand-kept list of callers, which is the thing this design says not to build.
 *
 * Deliberately shaped like {@see ValidationExceptionSubscriber}, which solves the same problem for
 * a different refusal: JSON for a JSON caller, flash-and-redirect for a browser, and never a bounce
 * onward to an attacker-supplied Referer.
 */
final class StatusTransitionRefusedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof StatusTransitionRefused) {
            return;
        }

        $event->setResponse($this->response($event->getRequest(), $exception->getMessage()));
    }

    private function response(Request $request, string $message): Response
    {
        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'message' => $message,
            ], Response::HTTP_CONFLICT);
        }

        try {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $message);
            }
        } catch (SessionNotFoundException) {
            // No session to flash into; the redirect below still keeps the caller off the write.
        }

        return new RedirectResponse($this->safeReturnUrl($request));
    }

    /** Never bounce a rejected request onward to an attacker-supplied Referer. */
    private function safeReturnUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')
            ? $referer
            : '/';
    }

    private function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json')
            || ($accept !== '' && !str_contains($accept, 'text/html'));
    }
}
