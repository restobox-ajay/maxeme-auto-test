<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Guards the admin order status modal's success handler in public/assets/js/app.js (#224).
 *
 * Nothing in the suite executes app.js — the functional suite drives the Symfony kernel, not a
 * browser — so the handler is only checkable as text, the same way CustomerCartJsTest checks the
 * cart handlers. What can be asserted is that the handler and the markup it writes into still name
 * the same hook: the previous version located the badge by its colour modifier
 * (`.badge.success, .badge.warn, .badge.draft`), which a Pending order's status badge does not
 * carry — so the write landed on the "Paid" payment badge or on nothing, and the page went on
 * showing the old status.
 */
final class AdminOrderStatusJsTest extends TestCase
{
    private const APP_JS = __DIR__ . '/../../public/assets/js/app.js';
    private const ORDER_DETAIL = __DIR__ . '/../../templates/admin/order/detail.html.twig';
    private const ORDER_FORM = __DIR__ . '/../../templates/admin/order/form.html.twig';

    public function testTheStatusHandlerWritesIntoTheHookTheDetailPageRenders(): void
    {
        self::assertStringContainsString(
            'js-order-status-badge',
            (string) file_get_contents(self::ORDER_DETAIL),
            'The order detail page no longer renders the status badge hook.',
        );

        self::assertStringContainsString(
            "$('.js-order-status-badge')",
            $this->statusUpdateHandler(),
            'The status modal handler no longer updates the detail page badge.',
        );
    }

    public function testTheStatusHandlerStillUpdatesTheEditOrderPill(): void
    {
        self::assertStringContainsString(
            'order-edit-status',
            (string) file_get_contents(self::ORDER_FORM),
            'The Edit Order header no longer renders the status pill wrapper.',
        );

        self::assertStringContainsString(
            "$('.order-edit-status .order-status')",
            $this->statusUpdateHandler(),
            'The status modal handler no longer updates the Edit Order pill.',
        );
    }

    /** The selector that made the handler rewrite the payment badge instead of the status badge. */
    public function testTheStatusHandlerNoLongerLocatesTheBadgeByItsColour(): void
    {
        self::assertStringNotContainsString(
            '.badge.success',
            $this->statusUpdateHandler(),
            'The status modal handler is back to guessing at the badge by its colour modifier.',
        );
    }

    private function statusUpdateHandler(): string
    {
        $source = (string) file_get_contents(self::APP_JS);

        $start = strpos($source, "'.js-status-update'");
        self::assertNotFalse($start, 'app.js no longer binds a .js-status-update handler.');

        $end = strpos($source, "\n    });", $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
