<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Writes an entity's `status` column directly, bypassing HasStatus::setStatus()'s vocabulary check.
 *
 * For the handful of security tests that deliberately construct a row holding a value the
 * vocabulary does NOT know ('Disabled', 'Suspended', arbitrary case/whitespace) to prove the
 * login-blocking checks (AccountStatusResolver, AdminUserChecker, CustomerUserChecker,
 * InactiveAccountLogoutSubscriber) fail closed no matter what is sitting in the column — the same
 * thing `UnknownStatusIsNotADeadEndCest` proves for documents via a raw
 * `UPDATE ... SET status = ?` SQL statement. These are plain PHPUnit\Framework\TestCase classes
 * with no kernel and no database, so there is no connection to run that SQL against; reflection is
 * the equivalent move with no database to reach through.
 *
 * `getStatus()` itself is unaffected either way — it is a bare `return $this->status;` on all
 * three classes, same before and after HasStatus, so what these tests are proving stays proved.
 */
trait PutsRawEntityStatus
{
    private function putRawStatus(object $entity, string $status): object
    {
        $property = new \ReflectionProperty($entity, 'status');
        $property->setValue($entity, $status);

        return $entity;
    }
}
