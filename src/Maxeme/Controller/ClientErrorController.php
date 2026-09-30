<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ErrorLogWriter;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives a browser-side error from maxeme.js (a script error or an unhandled promise rejection
 * on any admin page) and records it in Logs › Error Log as level "js". Any signed-in staff member's
 * page can report, so it needs only the permission every role holds.
 */
final class ClientErrorController extends AbstractController
{
    private const FIELDS = ['message' => 1000, 'source' => 500, 'line' => 10, 'column' => 10, 'stack' => 5000, 'page' => 500];

    #[Route('/admin/logs/client-error', name: 'maxeme_client_error', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNT)]
    public function record(Request $request, ErrorLogWriter $errors): Response
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $payload = [];
        foreach (self::FIELDS as $field => $max) {
            $payload[$field] = mb_substr((string) ($data[$field] ?? ''), 0, $max);
        }
        $payload['user_agent'] = mb_substr((string) $request->headers->get('User-Agent'), 0, 300);

        $page = parse_url($payload['page'], PHP_URL_PATH);
        $errors->write('js', 'js ' . (is_string($page) ? $page : ''), $payload);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
