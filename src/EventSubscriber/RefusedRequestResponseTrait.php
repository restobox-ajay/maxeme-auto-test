<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the subscribers that turn a refused request into a response (CSRF, document locked, status
 * transition refused, validation) share: whether the caller wants JSON, and where to send a browser
 * back to. One copy, so the four cannot drift apart.
 */
trait RefusedRequestResponseTrait
{
    /** An XHR, or an Accept header that asks for JSON (or for anything but HTML). */
    private function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json')
            || ($accept !== '' && !str_contains($accept, 'text/html'));
    }

    /** The Referer when it is a page of this same site (scheme and host); otherwise the home page. */
    private function safeReturnUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')
            ? $referer
            : '/';
    }
}
