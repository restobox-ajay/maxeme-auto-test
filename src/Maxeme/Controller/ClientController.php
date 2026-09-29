<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ClientData;
use App\Maxeme\Dto\ClientSearchCriteria;
use App\Maxeme\Entity\Client;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Repository\AppointmentRepository;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\VehicleRepository;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * People › Client List, the client profile and the sidebar client search (legacy
 * ClientListController, ClientController, ClientProfileController).
 *
 * Differences from the legacy app, on purpose: the list is paged on the server (the legacy grid
 * silently stopped at 500 clients), deleting asks for confirmation, and a single search match
 * redirects to the profile rather than rendering it under the search URL.
 */
#[Route('/admin/clients', name: 'maxeme_client_')]
#[IsGranted(StaffRole::STAFF)]
final class ClientController extends AbstractMaxemeController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderList($request, new ClientSearchCriteria());
    }

    /** The sidebar "Find a customer" form: one match opens the profile, otherwise the filtered list. */
    #[Route('/search', name: 'search', methods: ['GET'])]
    #[IsGranted(StaffRole::MANAGER)]
    public function search(Request $request): Response
    {
        $criteria = ClientSearchCriteria::fromRequest($request);
        $matches = $this->clients->findPage($criteria, ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS)));

        if ($matches->total === 1) {
            return $this->redirectToRoute('maxeme_client_show', ['id' => $matches->items[0]->getId()]);
        }

        return $this->renderList($request, $criteria);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted(StaffRole::MANAGER)]
    public function create(Request $request): Response
    {
        $data = ClientData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);

            return $this->redirectBack($request, 'maxeme_client_index');
        }

        $this->records->save($client = new Client(), $data);

        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId()]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] Client $client, Request $request, VehicleRepository $vehicles, AppointmentRepository $appointments, InvoiceRepository $invoices): Response
    {
        $this->denyUnlessActive($client);
        $tab = ClientProfileTab::fromRequest($request);

        // Only the open tab's list: the tabs share the URL's sort / page parameters.
        $pending = $tab === ClientProfileTab::Appointments ? $appointments->pendingForClient($client) : [];
        $past = $tab === ClientProfileTab::Appointments ? $appointments->findCompletedPageForClient($client, ListQuery::fromRequest($request, array_keys(AppointmentRepository::PAST_SORTS), 'desc')) : null;

        return $this->render('maxeme/client/show.html.twig', [
            'client' => $client,
            'form' => ClientData::fromEntity($client),
            'tab' => $tab,
            'tabs' => ClientProfileTab::cases(),
            'vehicles' => $tab === ClientProfileTab::Vehicles ? $vehicles->findPageForClient($client, ListQuery::fromRequest($request, array_keys(VehicleRepository::SORTS))) : null,
            'pending' => $pending,
            'past' => $past,
            'invoiceKeys' => $invoices->keysByAppointment([...$pending, ...($past?->items ?? [])]),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(StaffRole::MANAGER)]
    public function update(#[MapEntity] Client $client, Request $request): Response
    {
        $this->denyUnlessActive($client);

        $data = ClientData::fromRequest($request);
        if (($errors = $this->records->validate($data)) !== []) {
            $this->flashErrors($errors);
        } else {
            $this->records->save($client, $data);
            $this->addFlash('success', sprintf('%s saved.', $client->getFullName()));
        }

        return $this->redirectBack($request, 'maxeme_client_show', ['id' => $client->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(StaffRole::MANAGER)]
    public function delete(#[MapEntity] Client $client): JsonResponse
    {
        $this->records->delete($client);

        return $this->json(['message' => sprintf('%s deleted.', $client->getFullName())]);
    }

    private function renderList(Request $request, ClientSearchCriteria $criteria): Response
    {
        return $this->render('maxeme/client/index.html.twig', [
            'page' => $this->clients->findPage($criteria, ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS))),
        ]);
    }

    /** A deleted client is gone from the app, as in the legacy lists and searches. */
    private function denyUnlessActive(Client $client): void
    {
        if (!$client->isActive()) {
            throw new NotFoundHttpException('This client has been deleted.');
        }
    }
}
