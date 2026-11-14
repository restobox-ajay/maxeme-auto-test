<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\Dashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin landing page. Takes /admin over from core's B2B dashboard (priority beats the core
 * route on the same path, so `path('admin_dashboard')` links still land here).
 *
 * The Dashboard (mockup 345c037): today's appointments, in the shop, ready for pick up, unpaid
 * invoices and Needs attention. The invoice figures only for those who may see invoices.
 */
final class HomeController extends AbstractController
{
    #[Route('/admin', name: 'maxeme_home', methods: ['GET'], priority: 10)]
    #[Route('/admin/', name: 'maxeme_home_slash', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::APPOINTMENT_VIEW)]
    public function index(Dashboard $dashboard): Response
    {
        $canSeeInvoices = $this->isGranted(Permission::ACCOUNTING_VIEW);

        return $this->render('maxeme/dashboard.html.twig', $dashboard->build($canSeeInvoices) + ['canSeeInvoices' => $canSeeInvoices]);
    }
}
