<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Dto\ClientAddressData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A client's address book: add, edit, remove and put in order. Positions stay 0, 1, 2… in the
 * client's order, so "the first two" and the repair order's list are both just that order.
 */
final class ClientAddressBook
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecordWriter $records,
    ) {
    }

    /** @param ClientAddressData $data already validated; the new address goes last */
    public function add(Client $client, ClientAddressData $data): ClientAddress
    {
        $address = new ClientAddress($client, $client->getAddresses()->count());
        $client->getAddresses()->add($address);
        $this->records->save($address, $data);

        return $address;
    }

    /** @param ClientAddressData $data already validated */
    public function update(ClientAddress $address, ClientAddressData $data): void
    {
        $this->records->save($address, $data);
    }

    public function remove(ClientAddress $address): void
    {
        $client = $address->getClient();
        $client->getAddresses()->removeElement($address);
        $this->entityManager->remove($address);
        $this->renumber($client, $client->getAddresses()->getValues());
        $this->entityManager->flush();
    }

    /**
     * Puts the book in the order of $ids (address ids, first to last). Ids that are not this
     * client's are ignored; the client's addresses $ids leaves out keep their order after the rest.
     *
     * @param list<int> $ids
     */
    public function reorder(Client $client, array $ids): void
    {
        $byId = [];
        foreach ($client->getAddresses() as $address) {
            $byId[$address->getId()] = $address;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        $this->renumber($client, [...$ordered, ...array_values($byId)]);
        $this->entityManager->flush();
    }

    /**
     * Numbers $addresses 0, 1, 2… and puts the loaded collection in that order too (the mapping's
     * OrderBy only sorts it when it is loaded).
     *
     * @param list<ClientAddress> $addresses
     */
    private function renumber(Client $client, array $addresses): void
    {
        $book = $client->getAddresses();
        $book->clear();
        foreach ($addresses as $position => $address) {
            if ($address->getPosition() !== $position) {
                $address->setPosition($position);
            }
            $book->add($address);
        }
    }
}
