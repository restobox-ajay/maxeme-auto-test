<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The API's one error body shape (#715): RFC 7807-style (`application/problem+json`), plus an
 * app-specific `code` a consumer can branch on without parsing `detail`'s prose.
 *
 * Additive, not a replacement: every response still carries the original `{"error": "..."}` shape
 * (`ApiKeyAuthenticator`'s, and any future caller's), pointed at the same `detail` string, so an
 * existing integration reading `.error` keeps working unchanged. Nothing here touches HTTP status
 * codes — those were already correct (401/403/429) and stay exactly as chosen at each call site;
 * this only replaces the body.
 *
 * `type` is a relative reference (`/api/errors/{code}`), not an external URL — RFC 7807 allows
 * that, resolved against the request's own origin, and this app has no public docs domain to point
 * it at instead. `about:blank` was the other RFC-sanctioned option, but it means "nothing beyond
 * the HTTP status", which is exactly what `code` exists to give a caller instead.
 */
final class ApiProblem
{
    /** @param array<string, mixed> $extra fields specific to this one error (e.g. `parameter`, `allowedValues`) */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $detail,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
    }

    /** @param array<string, string> $headers extra headers (e.g. Retry-After) to carry alongside the body */
    public function toResponse(array $headers = []): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => '/api/errors/' . $this->code,
                'title' => $this->title,
                'status' => $this->status,
                'detail' => $this->detail,
                'code' => $this->code,
                // Additive: the shape every existing consumer already reads.
                'error' => $this->detail,
                ...$this->extra,
            ],
            $this->status,
            $headers + ['Content-Type' => 'application/problem+json'],
        );
    }
}
