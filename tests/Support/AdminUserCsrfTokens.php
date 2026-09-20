<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Reads Admin\UserController's CSRF tokens the way the browser gets them: off the rendered page.
 *
 * The token manager cannot be called directly from a test — it reads the session through the
 * request stack, which is empty once a request has finished — so every token here comes from the
 * markup the admin would actually be looking at.
 */
trait AdminUserCsrfTokens
{
    /** @param 'admin'|'customer' $type */
    private function grabUserFormToken(FunctionalTester $I, string $path): string
    {
        $I->amOnPage($path);

        return (string) $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value');
    }

    /** @param 'admin'|'customer' $type */
    private function grabUserRowToken(FunctionalTester $I, string $type, int $id, string $action): string
    {
        $I->amOnPage($type === 'admin' ? '/admin/user/staff' : '/admin/user/customer');

        if ($action === 'status') {
            return (string) $I->grabAttributeFrom(
                '.js-user-status-open[data-status-url$="/status/' . $type . '/' . $id . '"]',
                'data-status-token'
            );
        }

        return (string) $I->grabAttributeFrom(
            '[data-url$="/' . $action . '/' . $type . '/' . $id . '"]',
            'data-token'
        );
    }
}
