<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Enum\ClientPickPurpose;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\ClientRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Choose a client: the first step of the sidebar "+" on Repair Order, Appointments and Reminders,
 * which all need a client. Searches like the sidebar Customer box; choosing one opens the purpose's
 * own screen for that client (the purpose's permission is checked here too, so nobody reaches a
 * screen they could not open from the client's profile).
 */
final class ClientPickerController extends AbstractMaxemeController
{
    #[Route('/admin/clients/pick/{purpose}', name: 'maxeme_client_pick', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::PEOPLE_VIEW)]
    public function pick(string $purpose, Request $request, ClientRepository $clients): Response
    {
        $for = ClientPickPurpose::tryFrom($purpose) ?? throw new NotFoundHttpException();
        if (!$this->isGranted($for->permission())) {
            throw new AccessDeniedHttpException(sprintf('Missing permission "%s".', $for->permission()));
        }

        $find = SearchTerm::fromRequest($request);

        return $this->render('maxeme/client/pick.html.twig', [
            'for' => $for,
            'find' => $find,
            'page' => $clients->findPage($find, ListQuery::fromRequest($request, array_keys(ClientRepository::SORTS))),
        ]);
    }
}
