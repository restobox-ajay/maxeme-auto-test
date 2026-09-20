<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Contract\Connector\ConnectorStatusTone;
use App\Service\Connector\ConnectorRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The core connector directory (#741) — every connector type currently registered and Active, with
 * nothing type-specific on this page at all. A connector type earns a row here the moment its
 * bundle exists and is switched on; there is no "add a connector" action, because a connector type
 * is a bundle, not something this screen creates.
 */
final class ConnectorController extends AbstractAdminController
{
    #[Route('/admin/connectors', name: 'admin_connectors_index', methods: ['GET'])]
    public function index(ConnectorRegistry $registry): Response
    {
        $rows = [];
        foreach ($registry->activeTypes() as $type) {
            $connections = $type->connections();

            $needingAttention = 0;
            foreach ($connections as $connection) {
                if ($connection->statusTone !== ConnectorStatusTone::Ok) {
                    $needingAttention++;
                }
            }

            $rows[] = [
                'label' => $type->getLabel(),
                'description' => $type->getDescription(),
                'connectionCount' => count($connections),
                'needingAttention' => $needingAttention,
                'manageRouteName' => $type->getManageRouteName(),
            ];
        }

        return $this->render('admin/connector/index.html.twig', [
            'rows' => $rows,
        ]);
    }
}
