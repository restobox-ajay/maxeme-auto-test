<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Repository;

use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\ClientNote;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\VehicleRepository;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The sidebar's three search boxes: Customer, Vehicle and Invoice #. */
final class ClientRepositoryTest extends DoctrineIntegrationTestCase
{
    private ClientRepository $clients;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clients = self::getContainer()->get(ClientRepository::class);

        $singh = $this->client('Harpreet', 'Singh', phone3: '(604) 555-0100');
        $this->em->persist((new ClientAddress($singh, 0))->setAddressLine1('12 Main St')->setCity('Richmond'));
        $this->vehicle($singh, 'Honda', 'Civic', 'VIN-AAA', 'PLATE1');
        $singhera = $this->client('Amar', 'Singhera', phone1: '604.555.0200')->setPreferredName('Sonny');
        $this->vehicle($singhera, 'Toyota', 'RAV4', 'VIN-BBB', 'PLATE2')->deactivate();
        $this->client('Deleted', 'Singh')->deactivate();

        $this->invoice = Invoice::forClient($singhera, null, 5, 7);
        $this->em->persist($this->invoice);
        $this->em->flush();
    }

    public function testEveryWordHasToMatchAName(): void
    {
        self::assertSame(['Singh', 'Singhera'], $this->customers('singh'), 'contains, any case; deleted clients never');
        self::assertSame(['Singhera'], $this->customers('amar SINGH'), 'each word may match a different field');
        self::assertSame(['Singhera'], $this->customers('sonny'), 'preferred name');
        self::assertSame([], $this->customers('harpreet singhera'));
    }

    public function testPhoneMatchesItsDigitsWhateverTheFormatting(): void
    {
        self::assertSame(['Singh'], $this->customers('6045550100'));
        self::assertSame(['Singh'], $this->customers('555-0100'));
        self::assertSame(['Singhera'], $this->customers('604 555 0200'));
    }

    public function testAddressAndInvoiceNumberFindTheCustomer(): void
    {
        self::assertSame(['Singh'], $this->customers('richmond'));
        self::assertSame(['Singhera'], $this->customers(str_pad((string) $this->invoice->getId(), 8, '0', STR_PAD_LEFT)));
    }

    public function testTheGridSearchBoxStillNarrowsTheList(): void
    {
        $page = $this->clients->findPage(SearchTerm::of(''), ListQuery::fromRequest(new Request(['q' => 'amar']), array_keys(ClientRepository::SORTS)));

        self::assertSame(1, $page->total);
    }

    public function testTheExportTakesTheWholeViewNotOnePage(): void
    {
        $view = ListQuery::fromRequest(new Request(['sort' => 'lastName', 'dir' => 'desc', 'limit' => 1, 'page' => 2]), array_keys(ClientRepository::SORTS));

        self::assertSame(['Singhera', 'Singh'], array_map(static fn (Client $client): ?string => $client->getLastName(), $this->clients->findAllInView(SearchTerm::of('singh'), $view)), 'search and sort kept, paging ignored, deleted clients left out');
        self::assertSame(['Singhera'], array_map(static fn (Client $client): ?string => $client->getLastName(), $this->clients->findAllInView(SearchTerm::of(''), ListQuery::fromRequest(new Request(['q' => 'amar']), array_keys(ClientRepository::SORTS)))), 'the grid search box too');
    }

    public function testTheColumnSearchBoxesNarrowTheListAndTheExport(): void
    {
        $this->em->persist((new ClientNote($this->client('Note', 'Holder')))->setText('Wants a call before pickup'));
        $this->em->flush();
        $filtered = fn (array $filters): array => array_map(
            static fn (Client $client): ?string => $client->getLastName(),
            $this->clients->findPage(SearchTerm::of(''), ListQuery::fromRequest(new Request(['filters' => $filters, 'sort' => 'lastName']), array_keys(ClientRepository::SORTS)))->items,
        );

        self::assertSame(['Singhera'], $filtered(['firstName' => 'ama']));
        self::assertSame(['Singh'], $filtered(['phone' => '604 555-0100']), 'phone 3, compared on digits');
        self::assertSame(['Singhera'], $filtered(['phone' => '0200']), 'phone 1');
        self::assertSame(['Singh'], $filtered(['address' => 'RICHMOND']), 'any address in the book');
        self::assertSame(['Holder'], $filtered(['note' => 'call before']));
        self::assertSame([], $filtered(['firstName' => 'amar', 'lastName' => 'holder']), 'every box has to match');
        self::assertCount(3, $filtered(['unknown' => 'x', 'email' => '']), 'unknown or blank boxes are ignored');
        self::assertSame(['Holder'], array_map(static fn (Client $client): ?string => $client->getLastName(), $this->clients->findAllInView(SearchTerm::of(''), ListQuery::fromRequest(new Request(['filters' => ['note' => 'pickup']]), array_keys(ClientRepository::SORTS)))));
    }

    public function testVehicleBoxSearchesActiveVehiclesByAnyOfTheirFields(): void
    {
        $vehicles = self::getContainer()->get(VehicleRepository::class);
        $find = static fn (string $text): array => array_map(
            static fn (Vehicle $vehicle): ?string => $vehicle->getVin(),
            $vehicles->findPage(SearchTerm::of($text), ListQuery::fromRequest(new Request(), array_keys(VehicleRepository::LIST_SORTS)))->items,
        );

        self::assertSame(['VIN-AAA'], $find('plate1'));
        self::assertSame(['VIN-AAA'], $find('honda civic'));
        self::assertSame([], $find('toyota'), 'a deleted vehicle is not listed');
    }

    public function testInvoiceBoxMatchesTheNumberAsShown(): void
    {
        $invoices = self::getContainer()->get(InvoiceRepository::class);
        $id = (int) $this->invoice->getId();

        self::assertSame($this->invoice, $invoices->findOneByNumber(SearchTerm::of(str_pad((string) $id, 8, '0', STR_PAD_LEFT))));
        self::assertSame($this->invoice, $invoices->findOneByNumber(SearchTerm::of('#' . $id)));
        self::assertNull($invoices->findOneByNumber(SearchTerm::of('abc')));
        self::assertSame(1, $invoices->findPage(SearchTerm::of((string) $id), ListQuery::fromRequest(new Request(), array_keys(InvoiceRepository::LIST_SORTS)))->total);
    }

    /** @return list<?string> matching last names, sorted */
    private function customers(string $text): array
    {
        $page = $this->clients->findPage(SearchTerm::of($text), ListQuery::fromRequest(new Request(['sort' => 'lastName']), array_keys(ClientRepository::SORTS)));

        return array_map(static fn (Client $client): ?string => $client->getLastName(), $page->items);
    }

    private function client(string $first, string $last, ?string $phone1 = null, ?string $phone3 = null): Client
    {
        $client = (new Client())->setFirstName($first)->setLastName($last)->setPhone1($phone1)->setPhone3($phone3);
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
