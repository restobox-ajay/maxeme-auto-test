<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Repository\ProductCategoryRepository;
use App\Service\DocumentActorResolver;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportQueueSpawner;
use App\Service\Import\ImportRunner;
use App\Validation\Constraint\ValidCsvUpload;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\UnmatchedVendorSku;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Import\VendorSheetImportDefinition;
use ProcurementBundle\Import\VendorSheetImportRowExecutor;
use ProcurementBundle\Import\VendorSheetImportValidator;
use ProcurementBundle\Import\VendorSkuResolver;
use ProcurementBundle\Product\ProductPicker;
use ProcurementBundle\Repository\UnmatchedVendorSkuRepository;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Vendor sheet import (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md, §2), on the
 * unified import framework — ColumnMapper for the mapping screen (remembered per vendor),
 * ImportRunner for parse/queue, ImportQueueSpawner + import:process for execution, /admin/imports
 * for status/audit. See App\Controller\Admin\ProductImportController for the same shape applied to
 * core's own product importer.
 */
#[Route('/admin/bundles/procurement/vendor-sheet-import')]
final class VendorSheetImportController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly UnmatchedVendorSkuRepository $unmatched,
        private readonly ProductPicker $picker,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_vendor_sheet_import_index', methods: ['GET', 'POST'])]
    public function index(Request $request, ValidatorInterface $validator): Response
    {
        $this->denyIfInactive();

        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $csv */
            $csv = $request->files->get('csv_file');
            $violations = $validator->validate($csv, new ValidCsvUpload());
            if (count($violations) > 0) {
                throw new ValidationFailedException($csv, $violations);
            }

            $vendor = $this->vendorOr404($request->request->getInt('vendor_id', 0));

            /** @var UploadedFile $csv */
            $token = bin2hex(random_bytes(16));
            $filename = $csv->getClientOriginalName();

            $uploadDir = $this->uploadDir();
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $csv->move($uploadDir, $token . '.csv');

            return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_mapping', [
                'token' => $token,
                'filename' => $filename,
                'vendor_id' => $vendor->getId(),
                // Carried through to confirm() the same way token/filename/vendor_id already are —
                // a checkbox state chosen on THIS screen, not re-asked on the mapping screen.
                'allow_add' => $request->request->getBoolean('allow_add') ? '1' : '0',
                'allow_update' => $request->request->getBoolean('allow_update') ? '1' : '0',
                'allow_delete' => $request->request->getBoolean('allow_delete') ? '1' : '0',
            ]);
        }

        return $this->render('@Procurement/vendor_sheet_import.html.twig', [
            'vendors' => $this->activeVendors(),
        ]);
    }

    #[Route('/mapping/{token}', name: 'admin_bundle_procurement_vendor_sheet_import_mapping', methods: ['GET'])]
    public function mapping(string $token, Request $request, ColumnMapper $columnMapper, CsvRowReader $csvReader): Response
    {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($request->query->getInt('vendor_id', 0));
        $path = $this->storedCsvPath($token);
        if (!is_file($path)) {
            $this->addFlash('error', 'That upload could not be found. It may have expired — please try again.');

            return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_index');
        }

        $file = new UploadedFile($path, basename($path), 'text/csv', null, true);
        $headers = $csvReader->headers($file);
        $fields = (new VendorSheetImportDefinition())->targetFields();

        $rows = $columnMapper->formRows($fields, $headers, VendorSheetImportDefinition::NAME, (string) $vendor->getId());

        return $this->render('admin/_partials/import_column_mapping.html.twig', [
            'heading' => 'Match columns: ' . $request->query->get('filename', basename($path)),
            'lead' => 'Say which column in this file answers each field. ' . $vendor->getName() . '\'s choice is remembered, so the next sheet from them pre-fills automatically.',
            'confirmPath' => $this->generateUrl('admin_bundle_procurement_vendor_sheet_import_confirm'),
            'cancelPath' => $this->generateUrl('admin_bundle_procurement_vendor_sheet_import_index'),
            'rows' => $rows,
            'headers' => $headers,
            'error' => $request->query->get('error'),
            'hiddenFields' => [
                'token' => $token,
                'filename' => $request->query->get('filename', basename($path)),
                'vendor_id' => $vendor->getId(),
                'allow_add' => $request->query->get('allow_add', '0'),
                'allow_update' => $request->query->get('allow_update', '1'),
                'allow_delete' => $request->query->get('allow_delete', '0'),
            ],
        ]);
    }

    #[Route('/confirm', name: 'admin_bundle_procurement_vendor_sheet_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $entityManager,
        ColumnMapper $columnMapper,
        CsvRowReader $csvReader,
        VendorSkuResolver $resolver,
        ProductCategoryRepository $categoryRepository,
        VendorPriceUpserter $upserter,
        ImportQueueSpawner $spawner,
    ): Response {
        $this->denyIfInactive();

        $vendor = $this->vendorOr404($request->request->getInt('vendor_id', 0));
        $token = (string) $request->request->get('token', '');
        $path = $this->storedCsvPath($token);
        if ($token === '' || !is_file($path)) {
            $this->addFlash('error', 'That upload could not be found. It may have expired — please try again.');

            return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_index');
        }

        $filename = (string) $request->request->get('filename', basename($path));
        $fields = (new VendorSheetImportDefinition())->targetFields();
        $mapping = $columnMapper->fromRequest($fields, (array) $request->request->all('column_map'));

        $allowAdd = $request->request->getBoolean('allow_add');
        $allowUpdate = $request->request->getBoolean('allow_update');
        $allowDelete = $request->request->getBoolean('allow_delete');

        $missing = $columnMapper->missingRequired($fields, $mapping);
        if ($missing !== []) {
            $message = 'Required, but not mapped: ' . implode(', ', $missing) . '.';
            $this->addFlash('error', $message);

            return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_mapping', [
                'token' => $token, 'filename' => $filename, 'vendor_id' => $vendor->getId(), 'error' => $message,
                'allow_add' => $allowAdd ? '1' : '0', 'allow_update' => $allowUpdate ? '1' : '0', 'allow_delete' => $allowDelete ? '1' : '0',
            ]);
        }

        $file = new UploadedFile($path, $filename, 'text/csv', null, true);

        $runner = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            (new VendorSheetImportRowExecutor($resolver, $this->unmatched, $categoryRepository, $entityManager, $upserter))
                ->forOptions($allowAdd, $allowUpdate, $allowDelete),
            $columnMapper,
            $csvReader,
            $entityManager,
        );

        $run = $runner->parseAndMap($file, $mapping);

        $wholeRunError = $runner->wholeRunError($run);
        if ($wholeRunError !== null) {
            $this->addFlash('error', $wholeRunError);

            return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_mapping', [
                'token' => $token, 'filename' => $filename, 'vendor_id' => $vendor->getId(), 'error' => $wholeRunError,
                'allow_add' => $allowAdd ? '1' : '0', 'allow_update' => $allowUpdate ? '1' : '0', 'allow_delete' => $allowDelete ? '1' : '0',
            ]);
        }

        $run->setDescription('Vendor: ' . $vendor->getName());
        $run->setContext([
            'vendor_id' => $vendor->getId(),
            'allow_add' => $allowAdd,
            'allow_update' => $allowUpdate,
            'allow_delete' => $allowDelete,
        ]);
        $runner->queueForExecution($run, 'ui');

        $columnMapper->remember(VendorSheetImportDefinition::NAME, (string) $vendor->getId(), $mapping);

        @unlink($path);

        $spawner->spawn();

        return $this->redirectToRoute('admin_import_log_detail', ['id' => $run->getId()]);
    }

    /** The worklist the owner asked for: any vendor SKU seen with no product mapping, per vendor. */
    #[Route('/unmatched', name: 'admin_bundle_procurement_vendor_sheet_import_unmatched', methods: ['GET'])]
    public function unmatched(Request $request): Response
    {
        $this->denyIfInactive();

        $vendorId = $request->query->getInt('vendor_id', 0);
        $vendor = $vendorId > 0 ? $this->vendorOr404($vendorId) : null;

        $qb = $this->em->getRepository(UnmatchedVendorSku::class)->createQueryBuilder('u')
            ->andWhere('u.status = :pending')->setParameter('pending', UnmatchedVendorSku::STATUS_PENDING)
            ->orderBy('u.lastSeenAt', 'DESC');

        if ($vendor instanceof Vendor) {
            $qb->andWhere('u.vendor = :vendor')->setParameter('vendor', $vendor);
        }

        return $this->render('@Procurement/vendor_sheet_import_unmatched.html.twig', [
            'rows' => $qb->getQuery()->getResult(),
            'vendor' => $vendor,
            'vendors' => $this->activeVendors(),
            'productOptions' => $this->picker->options(),
            'productsRemote' => $this->picker->isRemote(),
        ]);
    }

    #[Route('/unmatched/{id}/link', name: 'admin_bundle_procurement_vendor_sheet_import_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function link(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyIfInactive();

        $row = $this->unmatchedOr404($id);
        $product = $this->productOr404($this->productIdFrom($request->request->all()));

        $vendorPrice = $entityManager->getRepository(VendorPrice::class)
            ->findOneBy(['vendor' => $row->getVendor(), 'product' => $product]);
        if ($vendorPrice === null) {
            $vendorPrice = (new VendorPrice())->setVendor($row->getVendor())->setProduct($product);
            $entityManager->persist($vendorPrice);
        }
        $vendorPrice->setVendorSku($row->getVendorSku());
        if ($row->getLastSeenPrice() !== null) {
            $vendorPrice->setUnitCost($row->getLastSeenPrice());
        }
        if ($row->getLastSeenQuantity() !== null) {
            $vendorPrice->setAvailableQuantity($row->getLastSeenQuantity());
        }
        if ($row->getLastSeenName() !== null) {
            $vendorPrice->setVendorItemName($row->getLastSeenName());
        }

        $row->resolveTo($product);

        $entityManager->flush();

        $this->addFlash('success', sprintf('Linked "%s" to %s.', $row->getVendorSku(), $product->getSku() ?: $product->getName()));

        return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_unmatched', ['vendor_id' => $row->getVendor()->getId()]);
    }

    #[Route('/unmatched/{id}/ignore', name: 'admin_bundle_procurement_vendor_sheet_import_ignore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignoreRow(int $id, EntityManagerInterface $entityManager): Response
    {
        $this->denyIfInactive();

        $row = $this->unmatchedOr404($id);
        $row->ignore();
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" ignored.', $row->getVendorSku()));

        return $this->redirectToRoute('admin_bundle_procurement_vendor_sheet_import_unmatched', ['vendor_id' => $row->getVendor()->getId()]);
    }

    private function unmatchedOr404(int $id): UnmatchedVendorSku
    {
        $row = $this->em->find(UnmatchedVendorSku::class, $id);
        if (!$row instanceof UnmatchedVendorSku) {
            throw new NotFoundHttpException('No such unmatched vendor SKU.');
        }

        return $row;
    }

    private function storedCsvPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return '';
        }

        return $this->uploadDir() . DIRECTORY_SEPARATOR . $token . '.csv';
    }

    private function uploadDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/import_uploads';
    }
}
