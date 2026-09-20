<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\DocumentLocked;
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
 * THE handling point for a write refused by a document lock.
 *
 * Deliberately shaped like {@see StatusTransitionRefusedSubscriber} and
 * {@see ValidationExceptionSubscriber}, which solve the same problem for two other refusals: JSON
 * for a JSON caller, flash-and-redirect for a browser, and never a bounce onward to an
 * attacker-supplied Referer. Three refusals, one shape.
 *
 * It matters more here than for either of those. {@see DocumentLockFlushGuard} throws from inside
 * Doctrine's `onFlush`, which no controller wraps in a `try` and none should have to — so without
 * this listener, the guard that makes the lock complete would also make every refusal a 500. With
 * it, an unenumerated write path degrades to *the right message appeared*, which is exactly the
 * property the lock is claimed to have.
 *
 * 409 Conflict for a JSON caller, matching the status-transition refusal: the request was
 * well-formed and was refused by the state of the thing it addressed.
 */
final class DocumentLockedSubscriber implements EventSubscriberInterface
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
        if (!$exception instanceof DocumentLocked) {
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
