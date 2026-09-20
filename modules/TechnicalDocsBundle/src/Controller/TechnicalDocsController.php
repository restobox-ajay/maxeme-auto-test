<?php

declare(strict_types=1);

namespace TechnicalDocsBundle\Controller;

use App\Repository\BundleStatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TechnicalDocsBundle\Docs\DocsRepository;

final class TechnicalDocsController extends AbstractController
{
    private const SOURCE = 'TechnicalDocsBundle';

    public function __construct(
        private readonly DocsRepository $docsRepository,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    #[Route('/admin/technical-docs/view/{path}', name: 'admin_technical_docs_view', requirements: ['path' => '.+'], methods: ['GET'])]
    public function view(string $path): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');

        // Same kill-switch every other bundle respects: Inactive means gone, not just
        // hidden from the sidebar -- the route itself stops serving content too.
        if (!$this->bundleStatusRepo->isActive(self::SOURCE)) {
            throw $this->createNotFoundException();
        }

        $contents = $this->docsRepository->read($path);
        if ($contents === null) {
            throw $this->createNotFoundException();
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
