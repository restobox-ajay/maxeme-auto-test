<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\ServiceReminderTemplateData;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Entity\ServiceReminderTemplate;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parts & Services › Reminder Templates: the emails a Service Reminder can use (Name, Subject,
 * Body in plain HTML with tags). List, add, edit and delete; a template is deleted only while
 * no service reminder uses it.
 */
#[Route('/admin/service-reminder-templates', name: 'maxeme_service_reminder_template_')]
final class ServiceReminderTemplateController extends AbstractMaxemeController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecordWriter $records,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(): Response
    {
        return $this->render('maxeme/service_reminder_template/index.html.twig', [
            'templates' => $this->entityManager->getRepository(ServiceReminderTemplate::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function new(Request $request): Response
    {
        return $this->form(new ServiceReminderTemplate(), $request, 'Add Reminder Template', '"%s" was created.');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function edit(#[MapEntity] ServiceReminderTemplate $template, Request $request): Response
    {
        return $this->form($template, $request, sprintf('Edit Reminder Template: %s', $template->getName()), '"%s" was saved.');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function delete(#[MapEntity] ServiceReminderTemplate $template): JsonResponse
    {
        $reminders = $this->entityManager->getRepository(ServiceReminder::class)->count(['template' => $template]);
        if ($reminders > 0) {
            return $this->json(['message' => sprintf('"%s" is used by %d service reminder%s. Choose another template for them first.', $template->getName(), $reminders, $reminders === 1 ? '' : 's')], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($template);
        $this->entityManager->flush();

        return $this->json(['message' => sprintf('"%s" was deleted.', $template->getName())]);
    }

    private function form(ServiceReminderTemplate $template, Request $request, string $title, string $saved): Response
    {
        $data = ServiceReminderTemplateData::fromEntity($template);
        $errors = [];

        if ($request->isMethod('POST')) {
            $data = ServiceReminderTemplateData::fromRequest($request);
            $errors = $this->records->validate($data);
            if ($errors === []) {
                $this->records->save($template, $data);
                $this->addFlash('success', sprintf($saved, $template->getName()));

                return $this->redirectToRoute('maxeme_service_reminder_template_index');
            }
        }

        return $this->render('maxeme/service_reminder_template/form.html.twig', [
            'title' => $title,
            'data' => $data,
            'errors' => $errors,
            'tags' => ServiceReminderTemplate::TAGS,
        ], new Response('', $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
