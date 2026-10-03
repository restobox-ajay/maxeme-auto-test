<?php

declare(strict_types=1);

namespace App\Maxeme\Ghl;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The few GoHighLevel (LeadConnector API v2) calls the shop needs: find a contact by email, create
 * one, add a tag. Authenticated with a Private Integration token for one sub-account (location).
 * isConfigured() is false while the token or the location id is blank: nothing is called then.
 */
final class GhlClient
{
    private const API_VERSION = '2021-07-28';

    /** @param array{base_url: string, token: string, location_id: string, review_tag: string} $config */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(param: 'maxeme.ghl')]
        private readonly array $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->config['token']) !== '' && trim($this->config['location_id']) !== '';
    }

    public function reviewTag(): string
    {
        return $this->config['review_tag'];
    }

    /** @return array{id: string, tags: list<string>}|null the contact with this email, if the location has one */
    public function findContactByEmail(string $email): ?array
    {
        $data = $this->request('GET', '/contacts/search/duplicate', ['query' => ['locationId' => $this->config['location_id'], 'email' => $email]]);
        $contact = $data['contact'] ?? null;

        return is_array($contact) && isset($contact['id']) ? self::contact($contact) : null;
    }

    /** @return array{id: string, tags: list<string>} */
    public function createContact(string $email, ?string $firstName, ?string $lastName, ?string $phone): array
    {
        $data = $this->request('POST', '/contacts/', ['json' => array_filter([
            'locationId' => $this->config['location_id'],
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: null,
            'phone' => $phone,
            'source' => 'Maxeme Auto',
        ], static fn (?string $value): bool => $value !== null && $value !== '')]);

        if (!isset($data['contact']['id'])) {
            throw new \RuntimeException('GoHighLevel did not return the new contact.');
        }

        return self::contact($data['contact']);
    }

    public function addTag(string $contactId, string $tag): void
    {
        $this->request('POST', sprintf('/contacts/%s/tags', rawurlencode($contactId)), ['json' => ['tags' => [$tag]]]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->isConfigured()) {
            throw new \LogicException('GoHighLevel is not configured (GHL_API_TOKEN, GHL_LOCATION_ID).');
        }

        $response = $this->httpClient->request($method, rtrim($this->config['base_url'], '/') . $path, $options + [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['token'],
                'Version' => self::API_VERSION,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ]);

        // toArray() throws on a 3xx/4xx/5xx answer, with the status and body in the message.
        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return array{id: string, tags: list<string>}
     */
    private static function contact(array $contact): array
    {
        return ['id' => (string) $contact['id'], 'tags' => array_values(array_map('strval', (array) ($contact['tags'] ?? [])))];
    }
}
