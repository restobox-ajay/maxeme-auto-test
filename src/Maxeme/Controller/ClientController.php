<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\ClientData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Listing\CsvExport;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\AppointmentRepository;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Repository\VehicleRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * People › Client List, the client profile and the sidebar client search (legacy
 * ClientListController, ClientController, ClientProfileController).
 *
 * Differences from the legacy app, on purpose: the list is paged on the server (the legacy grid
 * silently stopped at 500 clients), deleting asks for confirmation, and a single search match
 * redirects to the profile rather than rendering it under the search URL.
 */
#[Route('/admin/clients', name: 'maxeme_client_')]
final class ClientController extends AbstractMaxemeController
{
    /** The Client List's columns, as the export's header row. */
    private const EXPORT_COLUMNS = ['First name', 'Last name', 'Preferred name', 'Email', 'Phone', 'Address', 'Note', 'Last updated'];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::PEOPLE_VIEW)]
    public function index(Request $request): Response
    {
        return $this->renderList($request, SearchTerm::fromRequest($request));
    }

    /** The sidebar Customer box: one match opens the profile, otherwise the Client List of matches. */
    #[Route('/search', name: 'search', methods: ['GET'])]
    #[RequiresPermission(Permission::PEOPLE_VIEW)]
    public function search(Request $request): Response
    {
        $find = SearchTerm::fromRequest($request);
        $matches = $this->clients->findPage($find, ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS)));

        if ($matches->total === 1) {
            return $this->redirectToRoute('maxeme_client_show', ['id' => $matches->items[0]->getId()]);
        }

        return $this->redirectToRoute('maxeme_client_index', [SearchTerm::PARAM => $find->text]);
    }

    /**
     * Client List › Export CSV: every client of the current view (the sidebar search, the grid's
     * search box, the column search boxes and sort), not just the page shown, in the list's columns.
     */
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::PEOPLE_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        $find = SearchTerm::fromRequest($request);
        $list = ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS));
        $clients = $this->clients->findAllInView($find, $list);
        $filename = sprintf('clients-%s.csv', (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'));

        $view = ($find->text !== '' ? sprintf('search "%s", ', $find->text) : '') . $list->describe();
        $activity->exported('people', 'Client', $filename, count($clients), $view);

        $shopZone = new \DateTimeZone($timezone);

        return CsvExport::response($filename, self::EXPORT_COLUMNS, (static function () use ($clients, $shopZone): \Generator {
            foreach ($clients as $client) {
                yield [
                    $client->getFirstName(),
                    $client->getLastName(),
                    $client->getPreferredName(),
                    $client->getEmail(),
                    implode(' / ', $client->getPhones()),
                    $client->getPrimaryAddress()?->getOneLine(),
                    $client->getLatestNote()?->getText(),
                    $client->getLastUpdated()->setTimezone($shopZone)->format('Y-m-d H:i:s'),
                ];
            }
        })());
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
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
    #[RequiresPermission(Permission::PEOPLE_VIEW)]
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
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
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
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function delete(#[MapEntity] Client $client): JsonResponse
    {
        $this->records->delete($client);

        return $this->json(['message' => sprintf('%s deleted.', $client->getFullName())]);
    }

    private function renderList(Request $request, SearchTerm $find): Response
    {
        $page = $this->clients->findPage($find, ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS)));
        $this->clients->loadAddressesAndNotes($page->items);

        return $this->render('maxeme/client/index.html.twig', [
            'page' => $page,
            'find' => $find,
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
