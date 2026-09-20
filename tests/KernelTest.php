<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    /**
     * Storage must be UTC forever (see AppSettings::timezone() for the read-side conversion), and
     * every entity relies on PHP's ambient default timezone for its bare `new \DateTimeImmutable()`
     * calls rather than passing one explicitly. Pinning it here, once, in the one constructor every
     * process (web, bin/console, the messenger worker) goes through is what makes that true
     * regardless of the deployment's own php.ini setting — which is exactly what this test forces
     * to something else first, to prove the Kernel is the one doing the pinning.
     */
    public function testConstructingTheKernelPinsTheDefaultTimezoneToUtc(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');

        try {
            new Kernel('test', false);

            self::assertSame('UTC', date_default_timezone_get());
        } finally {
            date_default_timezone_set($original);
        }
    }
}
