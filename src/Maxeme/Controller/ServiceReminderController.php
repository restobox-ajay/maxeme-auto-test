<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ServiceReminderData;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Entity\ServiceReminderTemplate;
use App\Maxeme\Repository\ServiceReminderRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parts & Services › Service Reminders: for each service, how many days after its invoice is
 * completed to remind the customer, with which template and message. Only the rules for now;
 * sending them is specified later.
 */
#[Route('/admin/service-reminders', name: 'maxeme_service_reminder_')]
final class ServiceReminderController extends AbstractMaxemeController
{
    public function __construct(
        private readonly ServiceReminderRepository $reminders,
        private readonly RecordWriter $records,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(): Response
    {
        return $this->render('maxeme/service_reminder/index.html.twig', [
            'reminders' => $this->reminders->findAllByService(),
            'services' => $this->entityManager->getRepository(ServiceItem::class)->findBy(['active' => true], ['name' => 'ASC']),
            'templates' => $this->entityManager->getRepository(ServiceReminderTemplate::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function create(Request $request): RedirectResponse
    {
        return $this->save(null, $request, 'Service reminder for %s added.');
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function update(#[MapEntity] ServiceReminder $reminder, Request $request): RedirectResponse
    {
        return $this->save($reminder, $request, 'Service reminder for %s saved.');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function delete(#[MapEntity] ServiceReminder $reminder): JsonResponse
    {
        $this->entityManager->remove($reminder);
        $this->entityManager->flush();

        return $this->json(['message' => sprintf('Service reminder for %s deleted.', $reminder->getService()->getName())]);
    }

    private function save(?ServiceReminder $reminder, Request $request, string $message): RedirectResponse
    {
        $data = ServiceReminderData::fromRequest($request);
        $errors = $this->records->validate($data);

        $service = $data->serviceId !== null ? $this->entityManager->find(ServiceItem::class, (int) $data->serviceId) : null;
        $template = $data->templateId !== null ? $this->entityManager->find(ServiceReminderTemplate::class, (int) $data->templateId) : null;
        if (!isset($errors['serviceId']) && ($service === null || !$service->isActive())) {
            $errors['serviceId'] = 'Choose the service from the list.';
        }
        if ($data->templateId !== null && $template === null) {
            $errors['templateId'] = 'Choose the template from the list.';
        }
        $taken = $service !== null ? $this->reminders->findOneBy(['service' => $service]) : null;
        if ($taken !== null && $taken !== $reminder) {
            $errors['serviceId'] = sprintf('%s already has a service reminder. Edit that one instead.', $service->getName());
        }

        if ($errors !== []) {
            $this->flashErrors($errors);
        } else {
            $reminder ??= new ServiceReminder($service);
            $reminder->setService($service)->setTemplate($template);
            $this->records->save($reminder, $data);
            $this->addFlash('success', sprintf($message, $service->getName()));
        }

        return $this->redirectToRoute('maxeme_service_reminder_index');
    }
}
