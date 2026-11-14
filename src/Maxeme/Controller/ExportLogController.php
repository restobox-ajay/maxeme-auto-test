<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A list exported in the browser (mockup a38e7a2: Export CSV on the lists that show every row on one
 * page, maxeme.js [data-csv-of]): the export is written to the Activity Log like a server export.
 */
final class ExportLogController extends AbstractMaxemeController
{
    /** Any signed-in staff member (every role manages its own account); the list's area must be one they can view. */
    #[Route('/admin/exports/logged', name: 'maxeme_export_logged', methods: ['POST'])]
    #[RequiresPermission(Permission::ACCOUNT)]
    public function logged(Request $request, ActivityRecorder $activity): JsonResponse
    {
        $area = (string) $request->request->get('area', '');
        $entity = (string) $request->request->get('entity', '');
        $filename = (string) $request->request->get('filename', '');
        $rows = $request->request->getInt('rows');
        if (!isset(Permission::AREAS[$area]) || preg_match('/^[A-Za-z]{1,60}$/', $entity) !== 1 || preg_match('/^[a-z0-9._-]{1,120}\.csv$/', $filename) !== 1 || $rows < 0) {
            return $this->json(['message' => 'Not an export.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->isGranted('/' . $area . '/view') && !$this->isGranted('/' . $area . '/manage')) {
            return $this->json(['message' => 'Not allowed.'], Response::HTTP_FORBIDDEN);
        }
        $activity->exported($area, $entity, $filename, $rows, 'the rows on the page');

        return $this->json(['message' => 'Logged.']);
    }
}
