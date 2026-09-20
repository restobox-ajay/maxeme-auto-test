<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\Support\FunctionalTester;

/**
 * Covers the access-control side of the Stripe webhook, which the controller's own unit tests
 * cannot see because they invoke it directly.
 *
 * Stripe posts as an anonymous client with no session and no CSRF token, so /webhook/stripe must
 * be PUBLIC_ACCESS. If a future access_control rule shadows it, card payments still appear to work
 * — the browser flow is unaffected — while every asynchronous confirmation is silently redirected
 * to a login page and orders paid by customers who closed the tab quietly stay unpaid until the
 * sweeper cancels them. That failure is invisible without this test.
 */
final class StripeWebhookRouteCest
{
    public function webhookEndpointIsReachableWithoutAuthentication(FunctionalTester $I): void
    {
        $I->sendRawPostRequest('/webhook/stripe', '{}', ['HTTP_STRIPE_SIGNATURE' => 't=0,v1=deadbeef']);

        // Whatever it decides about this payload, it must decide it itself rather than being
        // bounced by the firewall.
        $I->dontSeeResponseCodeIs(302);
        $I->dontSeeResponseCodeIs(401);
        $I->dontSeeResponseCodeIs(403);
    }

    public function webhookRejectsAnUnsignedPayloadRatherThanAcceptingIt(FunctionalTester $I): void
    {
        $I->sendRawPostRequest('/webhook/stripe', '{"type":"payment_intent.succeeded"}');

        // 400 (bad signature) or 500 (no signing secret configured in this environment) are both
        // refusals. What must never happen is a 200 acknowledging an unauthenticated event.
        $I->dontSeeResponseCodeIs(200);
    }

    public function webhookDoesNotAcceptGet(FunctionalTester $I): void
    {
        $I->amOnPage('/webhook/stripe');

        $I->seeResponseCodeIs(405);
    }
}
