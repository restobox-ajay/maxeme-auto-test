<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The two ambient PHP defaults App\Kernel pins for every process it starts.
 *
 * Both exist because the box cannot be trusted to carry them: production and dev run on cPanel,
 * where the loaded php.ini is server-wide and shared, the docroot's .user.ini is ignored (LiteSpeed
 * LSAPI does not implement it), and the only remaining lever is a cPanel GUI setting that no git
 * bundle carries to the next instance.
 *
 * So the test has to prove the Kernel *changes* the value, not merely that the value is right —
 * asserting the latter passes for free on any developer machine that already has PHP's defaults,
 * which is exactly the machine where the bug is invisible. Each case therefore sets the setting to
 * the wrong thing first and asserts the Kernel corrects it.
 */
final class KernelAmbientDefaultsTest extends TestCase
{
    private string $serializePrecision;
    private string $timezone;

    protected function setUp(): void
    {
        $this->serializePrecision = (string) ini_get('serialize_precision');
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        ini_set('serialize_precision', $this->serializePrecision);
        date_default_timezone_set($this->timezone);
    }

    public function testTheKernelForcesShortestRoundTripFloatPrinting(): void
    {
        // What both cPanel boxes ship. json_encode then prints a float's full binary expansion.
        ini_set('serialize_precision', '100');
        self::assertStringContainsString(
            '125.0999',
            json_encode(['v' => 125.10]),
            'Guard on the test itself: if this no longer reproduces, the case below proves nothing.'
        );

        new Kernel('test', false);

        self::assertSame('-1', ini_get('serialize_precision'));
        self::assertSame('{"v":125.1}', json_encode(['v' => 125.10]));
    }

    /**
     * Money is the reason this matters: the customer API publishes companyPrice/retailPrice as JSON
     * numbers, and an integrator reading 125.099999999999994315658113919198513031005859375 has to
     * decide whether that is a price.
     */
    public function testApiPricesSerialiseAsPeopleWriteThem(): void
    {
        ini_set('serialize_precision', '100');
        new Kernel('test', false);

        self::assertSame(
            '{"companyPrice":125.1,"retailPrice":189.99}',
            json_encode(['companyPrice' => (float) '125.10', 'retailPrice' => (float) '189.99'])
        );
    }

    public function testTheKernelForcesUtc(): void
    {
        date_default_timezone_set('America/Vancouver');

        new Kernel('test', false);

        self::assertSame('UTC', date_default_timezone_get());
    }
}
