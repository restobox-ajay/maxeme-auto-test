<?php

namespace App\Controller\Admin;

use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportQueueSpawner;
use App\Service\Import\ImportRunner;
use App\Service\ProductImport\ProductImportDefinition;
use App\Service\ProductImport\ProductImportRowValidator;
use App\Service\ProductImport\ProductImportService;
use App\Validation\Constraint\ValidCsvUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Core's own product importer, on the unified import framework (see
 * docs/plans/2026-09-18-unified-import-framework.md) — ColumnMapper for the mapping screen,
 * ImportRunner for parse/queue, ImportQueueSpawner + import:process for execution, ImportLock for
 * serialization, /admin/imports for status/audit instead of a dedicated progress page. The
 * business logic underneath (ProductImportService) is unchanged; only how this screen reaches it
 * is new. Number1ProductImportBundle and Number1RimImportBundle are unaffected — they call
 * ProductImportService::import() directly and always have.
 */
#[Route('/admin/product/import')]
final class ProductImportController extends AbstractAdminController
{
    /**
     * Value stamped on ProductCore::syncSource for products this screen creates — see #477/#483,
     * documented at length on ProductImportService::import()'s own $syncSource line. Distinct from
     * the two Number1 bundles' own constants; this is the fourth (and, since the queue-per-process
     * command era ended, only remaining core) admin-triggered CSV path.
     */
    public const SYNC_SOURCE = 'core-admin-product-import';

    #[Route('', name: 'admin_product_import_index', methods: ['GET', 'POST'])]
    public function index(Request $request, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $csv */
            $csv = $request->files->get('csv_file');

            // Delegated to the ValidationExceptionSubscriber (issue #308) rather than an addFlash
            // + redirect done by hand, so a future fix to this check is uniform with every other
            // validation failure in the app instead of reinventing its own response.
            $violations = $validator->validate($csv, new ValidCsvUpload());
            if (count($violations) > 0) {
                throw new ValidationFailedException($csv, $violations);
            }

            /** @var UploadedFile $csv */
            $primaryKey = (string) $request->request->get('primary_key', 'sku') === 'id' ? 'id' : 'sku';
            $missingRows = (string) $request->request->get('missing_rows', 'do_nothing') === 'inactive_missing' ? 'inactive_missing' : 'do_nothing';
            $token = $this->newImportToken();
            $originalFilename = $csv->getClientOriginalName();

            $uploadDir = $this->importUploadDir();
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            // Durable copy for the mapping screen (a second GET, possibly seconds later) to read —
            // PHP's per-request upload tmp file is gone by then.
            $csv->move($uploadDir, $token . '.csv');

            return $this->redirectToRoute('admin_product_import_mapping', [
                'token' => $token,
                'filename' => $originalFilename,
                'primary_key' => $primaryKey,
                'missing_rows' => $missingRows,
            ]);
        }

        return $this->render('admin/product/import.html.twig', [
            'primaryKey' => 'sku',
            'missingRows' => 'do_nothing',
        ]);
    }

    #[Route('/mapping/{token}', name: 'admin_product_import_mapping', methods: ['GET'])]
    public function mapping(
        string $token,
        Request $request,
        EntityManagerInterface $entityManager,
        ProductImportService $productImportService,
        ColumnMapper $columnMapper,
        CsvRowReader $csvReader,
    ): Response {
        $path = $this->storedCsvPath($token);
        if (!is_file($path)) {
            $this->addFlash('error', 'That upload could not be found. It may have expired — please try again.');

            return $this->redirectToRoute('admin_product_import_index');
        }

        $file = new UploadedFile($path, basename($path), 'text/csv', null, true);
        $headers = $csvReader->headers($file);
        $fields = (new ProductImportDefinition($entityManager, $productImportService))->targetFields();

        // Auto-preselect via the importer's own normalizeHeaderKey() — the same flexible matching
        // (case/whitespace/dash-insensitive, fulfillment_region_*/price_*/fee_* aliasing) this
        // importer always used, now driving ColumnMapper's real mapping screen instead of being
        // invisible. A CSV using the expected header names — the common case, since
        // admin_product_import_template hands out exactly these — needs no clicks here.
        $guessed = [];
        $fieldKeys = array_map(static fn ($field) => $field->key, $fields);
        foreach ($headers as $header) {
            $normalized = $productImportService->normalizeHeaderKey($header);
            if (in_array($normalized, $fieldKeys, true) && !isset($guessed[$normalized])) {
                $guessed[$normalized] = $header;
            }
        }

        $rows = $columnMapper->rowsForMapping($fields, $headers, $guessed);

        return $this->render('admin/_partials/import_column_mapping.html.twig', [
            'heading' => 'Match columns: ' . $request->query->get('filename', basename($path)),
            'lead' => 'Columns matching this importer\'s expected names are already selected below — check and click Import.',
            'confirmPath' => $this->generateUrl('admin_product_import_confirm'),
            'cancelPath' => $this->generateUrl('admin_product_import_index'),
            'rows' => $rows,
            'headers' => $headers,
            'error' => $request->query->get('error'),
            'hiddenFields' => [
                'token' => $token,
                'filename' => $request->query->get('filename', basename($path)),
                'primary_key' => $request->query->get('primary_key', 'sku'),
                'missing_rows' => $request->query->get('missing_rows', 'do_nothing'),
            ],
        ]);
    }

    #[Route('/confirm', name: 'admin_product_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $entityManager,
        ProductImportService $productImportService,
        ColumnMapper $columnMapper,
        CsvRowReader $csvReader,
        ImportQueueSpawner $spawner,
    ): Response {
        $token = (string) $request->request->get('token', '');
        $path = $this->storedCsvPath($token);
        if ($token === '' || !is_file($path)) {
            $this->addFlash('error', 'That upload could not be found. It may have expired — please try again.');

            return $this->redirectToRoute('admin_product_import_index');
        }

        $primaryKey = (string) $request->request->get('primary_key', 'sku') === 'id' ? 'id' : 'sku';
        $missingRows = (string) $request->request->get('missing_rows', 'do_nothing') === 'inactive_missing' ? 'inactive_missing' : 'do_nothing';
        $filename = (string) $request->request->get('filename', basename($path));

        $definition = new ProductImportDefinition($entityManager, $productImportService);
        $fields = $definition->targetFields();
        $mapping = $columnMapper->fromRequest($fields, (array) $request->request->all('column_map'));

        $missing = $columnMapper->missingRequired($fields, $mapping);
        if ($missing !== []) {
            // No required fields exist today (every product column is individually optional — see
            // ProductImportDefinition), so this is unreachable, kept only so a future required
            // field fails safely (re-show the mapping screen) instead of silently importing blanks.
            $message = 'Required, but not mapped: ' . implode(', ', $missing) . '.';
            $this->addFlash('error', $message);

            return $this->redirectToRoute('admin_product_import_mapping', [
                'token' => $token, 'filename' => $filename, 'primary_key' => $primaryKey, 'missing_rows' => $missingRows, 'error' => $message,
            ]);
        }

        $file = new UploadedFile($path, $filename, 'text/csv', null, true);

        $runner = new ImportRunner(
            $definition,
            new ProductImportRowValidator($primaryKey),
            $productImportService,
            $columnMapper,
            $csvReader,
            $entityManager,
        );

        $run = $runner->parseAndMap($file, $mapping);
        $run->setDescription('Source: ' . $filename);
        $run->setContext([
            'primary_key' => $primaryKey,
            'missing_rows' => $missingRows,
            'sync_source' => self::SYNC_SOURCE,
        ]);
        $runner->queueForExecution($run, 'ui');

        $columnMapper->remember(ProductImportDefinition::NAME, '', $mapping);

        @unlink($path);

        $spawner->spawn();

        return $this->redirectToRoute('admin_import_log_detail', ['id' => $run->getId()]);
    }

    #[Route('/template', name: 'admin_product_import_template', methods: ['GET'])]
    public function template(EntityManagerInterface $entityManager, ProductImportService $importService): Response
    {
        $csv = $importService->templateCsv($entityManager);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-import-template.csv"',
        ]);
    }

    #[Route('/template-guide', name: 'admin_product_import_template_guide', methods: ['GET'])]
    public function templateGuide(EntityManagerInterface $entityManager, ProductImportService $importService): Response
    {
        $xml = $importService->templateGuideWorkbook($entityManager);

        return new Response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-import-template-guide.xml"',
        ]);
    }

    private function storedCsvPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return '';
        }

        return $this->importUploadDir() . DIRECTORY_SEPARATOR . $token . '.csv';
    }

    private function importUploadDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/import_uploads';
    }

    private function newImportToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
