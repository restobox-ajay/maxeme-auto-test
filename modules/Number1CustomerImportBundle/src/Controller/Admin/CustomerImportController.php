<?php

declare(strict_types=1);

namespace Number1CustomerImportBundle\Controller\Admin;

use App\Validation\Constraint\ValidCsvUpload;
use Number1CustomerImportBundle\Service\CustomerImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/bundles/customer-import')]
final class CustomerImportController extends AbstractController
{
    #[Route('', name: 'admin_bundle_customer_import_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, CustomerImportService $importService, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $csv */
            $csv = $request->files->get('csv_file');

            $violations = $validator->validate($csv, new ValidCsvUpload());
            if (count($violations) > 0) {
                throw new ValidationFailedException($csv, $violations);
            }

            /** @var UploadedFile $csv */
            $sendInvite = $request->request->get('send_invite') === 'yes';
            $result = $importService->import($csv, $entityManager, $sendInvite);

            if ($result->errors === []) {
                $this->addFlash('success', sprintf(
                    'Import completed. %d created, %d updated, %d skipped, %d compan%s created.',
                    $result->created,
                    $result->updated,
                    $result->skipped,
                    $result->companiesCreated,
                    $result->companiesCreated === 1 ? 'y' : 'ies'
                ));
            } else {
                $this->addFlash('error', sprintf(
                    'Import completed with %d error(s). %d created, %d updated, %d skipped.',
                    count($result->errors),
                    $result->created,
                    $result->updated,
                    $result->skipped
                ));
            }

            return $this->render('@Number1CustomerImport/admin/customer_import/index.html.twig', [
                'result' => $result,
                'sendInvite' => $sendInvite,
            ]);
        }

        return $this->render('@Number1CustomerImport/admin/customer_import/index.html.twig', [
            'result' => null,
            'sendInvite' => false,
        ]);
    }

    #[Route('/template', name: 'admin_bundle_customer_import_template', methods: ['GET'])]
    public function template(CustomerImportService $importService): Response
    {
        return new Response($importService->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customer-import-template.csv"',
        ]);
    }
}
