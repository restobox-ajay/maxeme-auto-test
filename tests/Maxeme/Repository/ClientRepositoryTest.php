<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Repository;

use App\Maxeme\Dto\ClientSearchCriteria;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\ClientRepository;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The sidebar "Find a customer" rules, as the legacy ClientRepository::getByParams() applied them. */
final class ClientRepositoryTest extends DoctrineIntegrationTestCase
{
    private ClientRepository $clients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clients = self::getContainer()->get(ClientRepository::class);

        $singh = $this->client('Harpreet', 'Singh', cell: '604-555-0100');
        $this->vehicle($singh, 'Honda', 'Civic', 'VIN-AAA', 'PLATE1');
        $singhera = $this->client('Amar', 'Singhera', home: '604-555-0200');
        $this->vehicle($singhera, 'Toyota', 'RAV4', 'VIN-BBB', 'PLATE2')->deactivate();
        $this->client('Deleted', 'Singh')->deactivate();
        $this->em->flush();
    }

    public function testNamesMatchByPrefixAndIgnoreDeletedClients(): void
    {
        self::assertSame(['Singh', 'Singhera'], $this->lastNames(['lastName' => 'singh']));
        self::assertSame(['Singhera'], $this->lastNames(['firstName' => 'AM']));
        self::assertSame([], $this->lastNames(['lastName' => 'ingh']), 'starts with, not contains');
    }

    public function testPhoneMatchesAnyNumberExactly(): void
    {
        self::assertSame(['Singh'], $this->lastNames(['phoneNumber' => '604-555-0100']));
        self::assertSame(['Singhera'], $this->lastNames(['phoneNumber' => '604-555-0200']));
        self::assertSame([], $this->lastNames(['phoneNumber' => '604-555']));
    }

    public function testVehicleFieldsMatchExactlyOnAnyVehicleIncludingDeletedOnes(): void
    {
        self::assertSame(['Singh'], $this->lastNames(['vin' => 'vin-aaa']));
        self::assertSame(['Singh'], $this->lastNames(['license_plate' => 'PLATE1']), 'the legacy license-plate criterion never matched; this one does');
        self::assertSame(['Singhera'], $this->lastNames(['manufacturer' => 'toyota']), 'a deleted vehicle still finds its owner');
        self::assertSame([], $this->lastNames(['model' => 'Civ']));
    }

    public function testCriteriaAreAnded(): void
    {
        self::assertSame(['Singh'], $this->lastNames(['lastName' => 'Singh', 'manufacturer' => 'Honda']));
        self::assertSame([], $this->lastNames(['lastName' => 'Singhera', 'manufacturer' => 'Honda']));
    }

    public function testSearchBoxLooksInEveryColumn(): void
    {
        $page = $this->clients->findPage(new ClientSearchCriteria(), ListQuery::fromRequest(new Request(['q' => '0200']), array_keys(ClientRepository::SORTS)));

        self::assertSame(1, $page->total);
    }

    /** @param array<string, string> $query */
    private function lastNames(array $query): array
    {
        $criteria = ClientSearchCriteria::fromRequest(new Request($query));
        $page = $this->clients->findPage($criteria, ListQuery::fromRequest(new Request(['sort' => 'lastName']), array_keys(ClientRepository::SORTS)));

        return array_map(static fn (Client $client): ?string => $client->getLastName(), $page->items);
    }

    private function client(string $first, string $last, ?string $home = null, ?string $cell = null): Client
    {
        $client = (new Client())->setFirstName($first)->setLastName($last)->setHomeNumber($home)->setCellNumber($cell);
        $this->em->persist($client);

        return $client;
    }

    private function vehicle(Client $client, string $make, string $model, string $vin, string $plate): Vehicle
    {
        $vehicle = (new Vehicle($client))->setManufacturer($make)->setModel($model)->setVin($vin)->setLicensePlate($plate);
        $this->em->persist($vehicle);

        return $vehicle;
    }
}
