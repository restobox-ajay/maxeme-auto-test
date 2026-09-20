<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Controller;

use App\Entity\SalesTax;
use App\Repository\SalesTaxRepository;
use App\Validation\Constraint\ValidSalesTax;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use TaxCanadaSimpleBundle\Tax\CanadaSimpleTaxCalculator;

#[Route('/admin/bundles/tax/canada-simple')]
final class CanadaSimpleTaxConfigController extends AbstractController
{
    public function __construct(
        private readonly SalesTaxRepository $salesTaxRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_bundle_tax_canada_simple_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        // This used to call $this->calculator->ensureProvinceRows(), which CREATED the nine
        // sales_tax rows on a GET. It does not any more: TaxCanadaSimpleBundle\ReferenceData\
        // CanadaSimpleTaxSeeder owns them and runs once, on the first admin login. This action
        // reads them, by the same slugs the calculator looks them up by, so the screen and the
        // calculation cannot disagree about which row is which.
        //
        // The province grouping is built HERE and handed to the template. It used to be rebuilt in
        // Twig, walking PROVINCES through constant() and indexing a flat `rows` list positionally —
        // which only worked while the list was guaranteed to hold exactly one entry per component in
        // exactly that order. That guarantee came from the creating call this change removed, and a
        // single missing row (one an admin deleted) would have shifted every subsequent province's
        // rate onto the wrong heading, or thrown. The grouping is a fact about the province table,
        // so it is computed where the province table is read.
        $provinceRows = [];
        $rows = [];
        foreach (CanadaSimpleTaxCalculator::PROVINCES as $provinceCode => $province) {
            $components = [];
            foreach ($province['components'] as $component) {
                // GST is federal and identical everywhere it applies, so every province that uses it
                // points at the single `gst` row rather than a copy — same rule the calculator and
                // the seeder use. The screen renders it once, above, and says "refer above" here.
                $isGst = $component['type'] === 'GST';
                $slug = $isGst ? 'gst' : CanadaSimpleTaxCalculator::slugFor($provinceCode, $component['type']);

                $row = $this->salesTaxRepo->findOneBy(['slug' => $slug]);
                if (!$row instanceof SalesTax) {
                    // A row an admin deleted. Skipped rather than recreated (QUEUE.md: never write to
                    // existing data, and a deletion is a decision) and rather than thrown on.
                    continue;
                }

                $components[] = ['isGst' => $isGst, 'row' => $row];
                // Deduplicated: the shared GST row must be saved once, not once per province.
                $rows[$slug] = $row;
            }

            $provinceRows[] = ['code' => $provinceCode, 'name' => $province['name'], 'components' => $components];
        }

        $rows = array_values($rows);

        if ($request->isMethod('POST')) {
            foreach ($rows as $row) {
                $slug = $row->getSlug();
                $percent = (float) str_replace(',', '', (string) $request->request->get('rate_'.$slug, (string) ($row->getRate() * 100)));
                $status = (string) $request->request->get('status_'.$slug, $row->getStatus());

                $row->setRate($percent / 100);
                if (in_array($status, ['Active', 'Inactive'], true)) {
                    $row->setStatus($status);
                }
            }

            $validator = Validation::createValidator();
            foreach ($rows as $row) {
                $violations = $validator->validate($row, new ValidSalesTax());
                if (count($violations) > 0) {
                    $this->addFlash('error', (string) $violations[0]->getMessage());

                    return $this->redirectToRoute('admin_bundle_tax_canada_simple_config');
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Canada (non-BC) tax rates updated.');

            return $this->redirectToRoute('admin_bundle_tax_canada_simple_config');
        }

        return $this->render('@TaxCanadaSimple/config.html.twig', ['rows' => $rows, 'provinceRows' => $provinceRows]);
    }
}
