<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * THE handling point for symfony/validator failures (issue #222) — the counterpart to
 * CsrfProtectionSubscriber for validation instead of forged requests.
 *
 * Before this, each controller invented its own shape for "the input was bad": collect an array of
 * strings, addFlash('error', implode(' ', $errors)), re-render or redirect by hand. A controller
 * that calls the validator and lets ValidationFailedException propagate gets that same flash/JSON
 * response for free, and every future caller gets it identically rather than reinventing it.
 *
 * Unlike CsrfProtectionSubscriber (which runs on kernel.controller, before the action executes),
 * this runs on kernel.exception: validation can only fail once a controller has done enough work to
 * have something to validate, so there is no "before" moment to intercept.
 */
final class ValidationExceptionSubscriber implements EventSubscriberInterface
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
        if (!$exception instanceof ValidationFailedException) {
            return;
        }

        $messages = array_values(array_unique(array_map(
            static fn ($violation): string => (string) $violation->getMessage(),
            iterator_to_array($exception->getViolations()),
        )));

        $event->setResponse($this->response($event->getRequest(), $messages));
    }

    /** @param list<string> $messages */
    private function response(Request $request, array $messages): Response
    {
        $message = implode(' ', $messages);

        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'message' => $message,
                'errors' => $messages,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
