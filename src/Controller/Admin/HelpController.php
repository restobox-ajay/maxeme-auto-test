<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HelpController extends AbstractAdminController
{
    // Fixed allowlist: {key} is a lookup index, never a filesystem path, so there's no
    // way to reach a file outside this list (no path traversal / arbitrary file read).
    private const RAW_DOCS = [
        'inventory-calculation' => 'docs/inventory-calculation.md',
    ];

    /**
     * Integration artifacts an admin hands to a customer's developer (#521): the OpenAPI
     * description and the Postman collection. Downloads rather than links, because the recipient is
     * outside this system and cannot be sent to a page behind the admin login.
     *
     * Same fixed-allowlist shape as RAW_DOCS above and for the same reason — {key} indexes this
     * array, it never reaches the filesystem. Content type and download filename are declared here
     * too: guessing either from the extension is how a .yaml ends up rendering in the browser as
     * text/plain and a .json ends up saved as "postman".
     *
     * @var array<string, array{0: string, 1: string, 2: string}> key => [path, content type, filename]
     */
    private const API_DOCS = [
        'openapi' => ['docs/api/openapi.yaml', 'application/yaml; charset=utf-8', 'wholesale-b2b-api-openapi.yaml'],
        'postman' => ['docs/api/wholesale-b2b-api.postman_collection.json', 'application/json; charset=utf-8', 'wholesale-b2b-api.postman_collection.json'],
    ];

    #[Route('/admin/help', name: 'admin_help_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/help/index.html.twig');
    }

    #[Route('/admin/help/doc/{key}', name: 'admin_help_doc_raw', methods: ['GET'])]
    public function rawDoc(string $key, #[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        if (!isset(self::RAW_DOCS[$key])) {
            throw $this->createNotFoundException();
        }

        $contents = file_get_contents($projectDir . '/' . self::RAW_DOCS[$key]);

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    #[Route('/admin/help/api-doc/{key}', name: 'admin_help_api_doc', methods: ['GET'])]
    public function apiDoc(string $key, #[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        if (!isset(self::API_DOCS[$key])) {
            throw $this->createNotFoundException();
        }

        [$path, $contentType, $filename] = self::API_DOCS[$key];

        return new Response((string) file_get_contents($projectDir . '/' . $path), Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }
}
