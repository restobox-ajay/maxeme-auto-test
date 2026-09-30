<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Schedule\CalendarView;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin landing page. Takes /admin over from core's B2B dashboard (priority beats the core
 * route on the same path, so `path('admin_dashboard')` links still land here).
 *
 * Lands on Schedule › Appointments in day view, as the legacy app did.
 */
final class HomeController extends AbstractController
{
    #[Route('/admin', name: 'maxeme_home', methods: ['GET'], priority: 10)]
    #[Route('/admin/', name: 'maxeme_home_slash', methods: ['GET'], priority: 10)]
    #[RequiresPermission(Permission::APPOINTMENT_VIEW)]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_appointment_calendar', ['view' => CalendarView::Day->value]);
    }
}
