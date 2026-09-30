<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sidebar entries whose screens are not specified yet (legacy "This page is under construction..."),
 * so the menu is complete while the specs are written. Each keeps the permission its real screen
 * will need.
 */
final class UnderConstructionController extends AbstractController
{
    #[Route('/admin/repair-orders', name: 'maxeme_repair_order_index', methods: ['GET'])]
    #[RequiresPermission(Permission::WORK_ORDER_VIEW)]
    public function repairOrders(): Response
    {
        return $this->placeholder('Schedule', 'Repair Order');
    }

    #[Route('/admin/shop-settings', name: 'maxeme_settings', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function settings(): Response
    {
        return $this->placeholder('Config', 'Settings');
    }

    private function placeholder(string $eyebrow, string $title): Response
    {
        return $this->render('maxeme/under_construction.html.twig', ['eyebrow' => $eyebrow, 'title' => $title]);
    }
}
