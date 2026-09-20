<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Api\CatalogParams;
use App\Api\ProductPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the published API description honest.
 *
 * docs/api/openapi.yaml and the Postman collection are what an integrator builds against, and
 * documentation drifts silently: nothing breaks when a field is added to ProductPresenter and not
 * to the spec, so nobody notices until a customer's import is missing a column. The two lists that
 * make up the contract are already declared as data — ProductPresenter::FIELDS and
 * CatalogParams::PARAMS — precisely so they can be compared against the file rather than read by a
 * human hoping to spot a difference.
 *
 * So this test does not check that the documentation is good. It checks that it is not stale, which
 * is the failure mode that actually happens.
 */
final class ApiDocumentationContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../docs/api/openapi.yaml';
    private const POSTMAN = __DIR__ . '/../../docs/api/wholesale-b2b-api.postman_collection.json';

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(self::SPEC);
    }

    public function testTheSpecDocumentsExactlyTheFieldsTheApiEmits(): void
    {
        $documented = $this->spec()['components']['schemas']['Product']['required'];

        // Equality, not subset, and in order — matching ProductPresenter::FIELDS, which is asserted
        // the same way against the live payload. A field that appears in the response but not here
        // is undocumented; one that appears here but not in the response is a promise we break.
        self::assertSame(
            ProductPresenter::FIELDS,
            $documented,
            'docs/api/openapi.yaml no longer matches ProductPresenter::FIELDS. Update the spec.'
        );

        // required lists the names; properties has to actually define each one, or a generated
        // client gets a field it has no type for.
        self::assertSame(
            ProductPresenter::FIELDS,
            array_keys($this->spec()['components']['schemas']['Product']['properties']),
            'Every documented field needs a property definition, in the same order.'
        );
    }

    public function testTheSpecDocumentsExactlyTheQueryParametersTheApiAccepts(): void
    {
        $parameters = $this->spec()['paths']['/api/v1/products']['get']['parameters'];
        $documented = array_column($parameters, 'name');
        sort($documented);

        $accepted = [...array_keys(CatalogParams::PARAMS), CatalogParams::FILTER_PREFIX];
        sort($accepted);

        // CatalogParams ignores anything outside this set rather than erroring, which is the right
        // behaviour and also the reason an undocumented-but-accepted parameter would never be
        // noticed: it works, silently, for whoever happened to guess it.
        self::assertSame(
            $accepted,
            $documented,
            'docs/api/openapi.yaml no longer matches CatalogParams::PARAMS / FILTER_PREFIX.'
        );
    }

    public function testTheSpecDescribesTheHeaderTheAuthenticatorActuallyReads(): void
    {
        $scheme = $this->spec()['components']['securitySchemes']['ApiKeyAuth'];

        self::assertSame('apiKey', $scheme['type']);
        self::assertSame('header', $scheme['in']);
        self::assertSame(\App\Security\Api\ApiKeyAuthenticator::HEADER, $scheme['name']);
    }

    public function testThePostmanCollectionParsesAndSendsTheKeyAsAHeader(): void
    {
        $collection = json_decode((string) file_get_contents(self::POSTMAN), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $collection['info']['schema']
        );

        // Collection-level auth, so no individual request carries the key and there is one place to
        // change it. `in: header` is the part that matters: the API refuses a key in the query
        // string, and a collection that put it there would teach integrators the wrong habit.
        $auth = array_column($collection['auth']['apikey'], 'value', 'key');
        self::assertSame(\App\Security\Api\ApiKeyAuthenticator::HEADER, $auth['key']);
        self::assertSame('header', $auth['in']);
        self::assertSame('{{apiKey}}', $auth['value']);
    }

    public function testTheDocumentedRefusalMessagesAreTheOnesTheAuthenticatorSends(): void
    {
        $postman = (string) file_get_contents(self::POSTMAN);

        // These two are quoted verbatim in the troubleshooting material — in the collection's
        // example responses and in the admin Help Guide — because support matches on them. If the
        // wording changes, the material that tells someone what to do about it has to change too.
        self::assertStringContainsString(
            \App\Security\Api\ApiKeyAuthenticator::COMPANY_DISABLED_MESSAGE,
            $postman
        );
        self::assertStringContainsString(
            \App\Security\Api\ApiKeyAuthenticator::USER_DISABLED_MESSAGE,
            $postman
        );
    }
}
