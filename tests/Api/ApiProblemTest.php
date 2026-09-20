<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\ApiProblem;
use PHPUnit\Framework\TestCase;

/**
 * The API's one error body shape (#715): additive over the old `{"error": "..."}` contract, never
 * a replacement of it — see ApiProblem's own docblock for why.
 */
final class ApiProblemTest extends TestCase
{
    public function testTheBodyCarriesBothTheNewEnvelopeAndTheOldErrorKey(): void
    {
        $response = (new ApiProblem('invalid_api_key', 'Invalid API key', 'Invalid API key.', 401))->toResponse();
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        self::assertSame('/api/errors/invalid_api_key', $body['type']);
        self::assertSame('Invalid API key', $body['title']);
        self::assertSame(401, $body['status']);
        self::assertSame('Invalid API key.', $body['detail']);
        self::assertSame('invalid_api_key', $body['code']);

        // The field every consumer written before this issue already reads.
        self::assertSame('Invalid API key.', $body['error']);
    }

    public function testExtraFieldsRideAlongsideTheEnvelopeRatherThanReplacingIt(): void
    {
        $response = (new ApiProblem(
            'region_required',
            'Fulfillment region required',
            'region must be specified.',
            400,
            ['parameter' => 'region', 'allowedValues' => ['WEST', 'EAST']],
        ))->toResponse();
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame('region', $body['parameter']);
        self::assertSame(['WEST', 'EAST'], $body['allowedValues']);
        self::assertSame('region_required', $body['code']);
    }

    public function testExtraHeadersPassThroughAlongsideTheProblemContentType(): void
    {
        $response = (new ApiProblem('rate_limited', 'Rate limit exceeded', 'Rate limit exceeded.', 429))
            ->toResponse(['Retry-After' => '30']);

        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }
}
