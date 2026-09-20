<?php

declare(strict_types=1);

namespace App\Security\Csrf\Attribute;

/**
 * Exempts a controller (class or method) from the central CSRF check in
 * App\EventSubscriber\CsrfProtectionSubscriber.
 *
 * Only two categories legitimately need this:
 *   1. an endpoint called by an external system that cannot know our token, and that authenticates
 *      the caller some other way (the Stripe webhook verifies a signature header);
 *   2. the login forms, whose CSRF the firewall already enforces via `enable_csrf: true` /
 *      csrf_token_id 'authenticate' (config/packages/security.yaml). Without the exemption a
 *      *failed* login — which falls through to the controller to re-render the form — would be
 *      rejected as a CSRF failure instead of showing "wrong password".
 *
 * A $reason is required: an unexplained exemption is indistinguishable from an oversight, and this
 * is the one place a reviewer can see why an endpoint is unprotected.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class CsrfExempt
{
    public function __construct(
        public readonly string $reason,
    ) {
    }
}
