<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\AppointmentData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\AppointmentService;
use App\Maxeme\Service\RecordWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schedule an appointment / Edit an appointment (legacy Appointment/form.html.twig, which the
 * client profile opened in a modal; here they are pages that return to the profile).
 */
final class AppointmentController extends AbstractMaxemeController
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly RecordWriter $records,
        private readonly ScheduleSettings $settings,
    ) {
    }

    #[Route('/admin/clients/{id}/appointments/new', name: 'maxeme_appointment_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function new(#[MapEntity] Client $client, Request $request): Response
    {
        return $this->handle($request, $client, null);
    }

    #[Route('/admin/appointments/{id}/edit', name: 'maxeme_appointment_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::APPOINTMENT_EDIT)]
    public function edit(#[MapEntity] Appointment $appointment, Request $request): Response
    {
        return $this->handle($request, $appointment->getClient(), $appointment);
    }

    private function handle(Request $request, Client $client, ?Appointment $appointment): Response
    {
        $data = $appointment !== null ? AppointmentData::fromAppointment($appointment, $this->settings) : new AppointmentData();
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = AppointmentData::fromRequest($request);
            $errors = $this->records->validate($data);

            if ($errors === []) {
                try {
                    $appointment !== null ? $this->appointments->update($appointment, $data) : $this->appointments->book($client, $data);
                    $this->addFlash('success', $appointment !== null ? 'Appointment updated.' : 'Appointment booked.');

                    return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId(), 'tab' => ClientProfileTab::Appointments->value]);
                } catch (\DomainException $exception) {
                    $errors = ['appointment' => $exception->getMessage()];
                }
            }
        }

        return $this->render('maxeme/schedule/form.html.twig', [
            'client' => $client,
            'appointment' => $appointment,
            'data' => $data,
            'errors' => $errors,
            'settings' => $this->settings,
        ], new Response(status: $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
