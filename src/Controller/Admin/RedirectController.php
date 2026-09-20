<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProductCategory;
use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/redirect')]
final class RedirectController extends AbstractAdminController
{

    #[Route('', name: 'admin_redirect_index', methods: ['GET'])]
    public function index(RedirectRepository $redirectRepo): Response
    {
        return $this->render('admin/redirect/index.html.twig', [
            'redirects' => array_map($this->redirectToRow(...), $redirectRepo->findAllOrdered()),
        ]);
    }

    #[Route('/create', name: 'admin_redirect_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $redirect = new Redirect();
            $this->applyRequest($redirect, $request, $entityManager);

            $violations = $validator->validate($redirect);
            if (count($violations) > 0) {
                throw new ValidationFailedException($redirect, $violations);
            }

            $entityManager->persist($redirect);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Redirect from "%s" was created successfully.', $redirect->getSourcePath()));

            return $this->redirectToRoute('admin_redirect_index');
        }

        return $this->render('admin/redirect/form.html.twig', [
            'mode' => 'Create',
            'redirect' => null,
            'formData' => [],
            'categoryOptions' => $this->categoryRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/{id}/update', name: 'admin_redirect_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        $redirect = $entityManager->find(Redirect::class, $id);
        if (!$redirect instanceof Redirect) {
            $this->addFlash('error', 'Redirect could not be found.');

            return $this->redirectToRoute('admin_redirect_index');
        }

        if ($request->isMethod('POST')) {
            $this->applyRequest($redirect, $request, $entityManager);

            $violations = $validator->validate($redirect);
            if (count($violations) > 0) {
                throw new ValidationFailedException($redirect, $violations);
            }

            $redirect->touch();
            $entityManager->flush();

            $this->addFlash('success', sprintf('Redirect from "%s" was updated successfully.', $redirect->getSourcePath()));

            return $this->redirectToRoute('admin_redirect_index');
        }

        return $this->render('admin/redirect/form.html.twig', [
            'mode' => 'Update',
            'redirect' => $redirect,
            'formData' => [],
            'categoryOptions' => $this->categoryRowsFromDatabase($entityManager),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_redirect_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $redirect = $entityManager->find(Redirect::class, $id);
        if (!$redirect instanceof Redirect) {
            $this->addFlash('error', 'Redirect could not be found.');

            return $this->redirectToRoute('admin_redirect_index');
        }

        $sourcePath = $redirect->getSourcePath();
        $entityManager->remove($redirect);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Redirect from "%s" was deleted.', $sourcePath));

        return $this->redirectToRoute('admin_redirect_index');
    }

    private function applyRequest(Redirect $redirect, Request $request, EntityManagerInterface $entityManager): void
    {
        $destinationType = (string) $request->request->get('destination_type', Redirect::DESTINATION_TYPE_URL);

        $redirect
            ->setSourcePath(trim((string) $request->request->get('source_path', '')))
            ->setDestinationType($destinationType)
            ->setRedirectType((int) $request->request->get('redirect_type', Redirect::REDIRECT_TYPE_PERMANENT))
            ->setQueryHandling((string) $request->request->get('query_handling', Redirect::QUERY_HANDLING_PASS));

        if ($destinationType === Redirect::DESTINATION_TYPE_CATEGORY) {
            $categoryId = (int) $request->request->get('destination_category_id', 0);
            $redirect
                ->setDestinationCategory($entityManager->find(ProductCategory::class, $categoryId))
                ->setDestinationUrl(null);
        } else {
            $redirect
                ->setDestinationUrl(trim((string) $request->request->get('destination_url', '')))
                ->setDestinationCategory(null);
        }
    }

    /** @return array<string, string> */
    private function redirectToRow(Redirect $redirect): array
    {
        $category = $redirect->getDestinationCategory();

        return [
            'id' => (string) $redirect->getId(),
            'sourcePath' => $redirect->getSourcePath(),
            'destinationType' => $redirect->getDestinationType(),
            'destinationDisplay' => $redirect->getDestinationType() === Redirect::DESTINATION_TYPE_CATEGORY
                ? sprintf('Category: %s', $category?->getName() ?? '(deleted category)')
                : (string) $redirect->getDestinationUrl(),
            'redirectType' => (string) $redirect->getRedirectType(),
            'queryHandling' => $redirect->getQueryHandling(),
            'queryHandlingLabel' => match ($redirect->getQueryHandling()) {
                Redirect::QUERY_HANDLING_MATCH => 'Must match',
                Redirect::QUERY_HANDLING_STRIP => 'Strip',
                default => 'Pass through',
            },
        ];
    }
}
