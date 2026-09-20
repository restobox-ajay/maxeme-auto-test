<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the autowired HttpClientInterface — builds the request from the config
 * screen's stored api_url/api_client_id/api_key, decodes the JSON, returns the raw "data" array
 * (header row + data rows, matching https://wheels.rimalloycanada.com/v1/get-wheels's own shape)
 * or throws RimApiException on any transport/HTTP-status/JSON-decode failure, so both the console
 * command and the admin controller can surface a clear message instead of a stack trace.
 */
final class RimApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /** @return list<list<mixed>> */
    public function fetchRows(string $apiUrl, string $apiClientId, string $apiKey): array
    {
        $apiUrl = trim($apiUrl);
        if ($apiUrl === '') {
            throw new RimApiException('No rim API URL is configured.');
        }

        try {
            $response = $this->httpClient->request('GET', $apiUrl, [
                'query' => [
                    'api_client_id' => $apiClientId,
                    'api_key' => $apiKey,
                    'output' => 'json',
                ],
                'timeout' => 30,
                'max_duration' => 120,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            throw new RimApiException('Could not reach the rim API: ' . $e->getMessage(), previous: $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new RimApiException(sprintf('Rim API returned HTTP %d.', $status));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RimApiException('Rim API response was not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RimApiException('Rim API response did not contain a "data" array.');
        }

        /** @var list<list<mixed>> */
        return array_values($decoded['data']);
    }
}
