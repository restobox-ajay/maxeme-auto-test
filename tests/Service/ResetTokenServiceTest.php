<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ResetTokenService;
use PHPUnit\Framework\TestCase;

final class ResetTokenServiceTest extends TestCase
{
    private ResetTokenService $service;

    protected function setUp(): void
    {
        $this->service = new ResetTokenService();
    }

    public function testGenerateReturnsSixtyFourCharacterHexString(): void
    {
        $token = $this->service->generate();

        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsDifferentTokensEachCall(): void
    {
        self::assertNotSame($this->service->generate(), $this->service->generate());
    }

    public function testHashIsDeterministicSha256(): void
    {
        $token = 'abc123';

        $hash = $this->service->hash($token);

        self::assertSame(hash('sha256', $token), $hash);
        self::assertSame($hash, $this->service->hash($token));
    }

    public function testHashOfDifferentTokensDiffer(): void
    {
        self::assertNotSame($this->service->hash('token-one'), $this->service->hash('token-two'));
    }

    public function testHashOfGeneratedTokenIsNotThePlaintextToken(): void
    {
        $token = $this->service->generate();

        self::assertNotSame($token, $this->service->hash($token));
    }
}
