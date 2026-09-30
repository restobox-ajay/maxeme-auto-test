<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ClientAddressData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\ClientAddressBook;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Client Profile › Address Book: the client's pickup addresses, added, edited, removed and put in order. */
#[Route('/admin/clients/{id}/addresses', name: 'maxeme_client_address_', requirements: ['id' => '\d+'])]
final class ClientAddressController extends AbstractMaxemeController
{
    public function __construct(
        private readonly ClientAddressBook $book,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function create(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $data = ClientAddressData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->book->add($client, $data);
            $this->addFlash('success', 'Address added.');
        }

        return $this->toAddressBook($client);
    }

    #[Route('/{addressId}', name: 'update', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function update(#[MapEntity] Client $client, int $addressId, Request $request): RedirectResponse
    {
        $address = $this->addressOf($client, $addressId);
        $data = ClientAddressData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->book->update($address, $data);
            $this->addFlash('success', 'Address saved.');
        }

        return $this->toAddressBook($client);
    }

    #[Route('/{addressId}/delete', name: 'delete', requirements: ['addressId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function delete(#[MapEntity] Client $client, int $addressId): JsonResponse
    {
        $this->book->remove($this->addressOf($client, $addressId));

        return $this->json(['message' => 'Address deleted.']);
    }

    /** Core's drag-to-reorder rows (app.js .js-sortable-config) post the new order as JSON { ids: [...] }. */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function reorder(#[MapEntity] Client $client, Request $request): JsonResponse
    {
        $ids = $request->getContentTypeFormat() === 'json' ? ($request->toArray()['ids'] ?? []) : $request->request->all('ids');
        $this->book->reorder($client, array_values(array_map('intval', is_array($ids) ? $ids : [])));

        return $this->json(['message' => 'Address order saved.']);
    }

    private function addressOf(Client $client, int $addressId): ClientAddress
    {
        foreach ($client->getAddresses() as $address) {
            if ($address->getId() === $addressId) {
                return $address;
            }
        }

        throw new NotFoundHttpException('This address is not in the client\'s address book.');
    }

    private function toAddressBook(Client $client): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId(), 'tab' => ClientProfileTab::Addresses->value]);
    }
}
