<?php

declare(strict_types=1);

namespace TaxBCBundle\Controller;

use App\Repository\SalesTaxRepository;
use App\Validation\Constraint\ValidSalesTax;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use TaxBCBundle\Tax\BCTaxCalculator;

#[Route('/admin/bundles/tax/bc')]
final class BCTaxConfigController extends AbstractController
{
    public function __construct(
        private readonly SalesTaxRepository $salesTaxRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_bundle_tax_bc_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        $gst = $this->salesTaxRepo->getBySlug('gst', [
            'province_name' => 'Canada (Federal)',
            'abbreviation' => '',
            'tax_type' => 'GST',
            'rate' => 0.05,
            'status' => 'Active',
            'source' => BCTaxCalculator::SOURCE,
        ]);
        $pst = $this->salesTaxRepo->getBySlug('bc-pst', [
            'province_name' => 'British Columbia',
            'abbreviation' => 'BC',
            'tax_type' => 'PST',
            'rate' => 0.07,
            'status' => 'Active',
            'source' => BCTaxCalculator::SOURCE,
        ]);
        $rows = [$gst, $pst];

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

                    return $this->redirectToRoute('admin_bundle_tax_bc_config');
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'BC tax rates updated.');

            return $this->redirectToRoute('admin_bundle_tax_bc_config');
        }

        return $this->render('@TaxBC/config.html.twig', ['rows' => $rows]);
    }
}
