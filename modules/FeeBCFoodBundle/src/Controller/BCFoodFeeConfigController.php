<?php

declare(strict_types=1);

namespace FeeBCFoodBundle\Controller;

use App\Repository\FeeRepository;
use App\Validation\Constraint\ValidFee;
use Doctrine\ORM\EntityManagerInterface;
use FeeBCFoodBundle\Fee\BCFoodFeeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/bundles/fees/bc-food')]
final class BCFoodFeeConfigController extends AbstractController
{
    private const TAX_CLASSES = ['E' => 'Exempt (E)', 'G' => 'GST only (G)', 'S' => 'Standard (S)'];

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_bundle_fee_bc_food_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        // This used to be a loop of $this->feeRepo->ensureBySlug(), which CREATED this
        // bundle's fee rows on a GET. It does not any more: BCFoodFeeSeeder
        // owns them and runs once, on the first admin login. This action reads (and, on a
        // POST, writes only what the admin submitted).
        $fees = $this->feeRepo->findBySource(BCFoodFeeCalculator::SOURCE);

        if ($request->isMethod('POST')) {
            foreach ($fees as $fee) {
                $slug = $fee->getSlug();
                $fee->setName(trim((string) $request->request->get('name_' . $slug, $fee->getName())));
                $fee->setDefaultValue((float) $request->request->get('value_' . $slug, $fee->getDefaultValue()));
                $fee->setTaxClass((string) $request->request->get('tax_class_' . $slug, $fee->getTaxClass()));
                $fee->setPlacement((string) $request->request->get('placement_' . $slug, $fee->getPlacement()));
            }

            $validator = Validation::createValidator();
            foreach ($fees as $fee) {
                $violations = $validator->validate($fee, new ValidFee());
                if (count($violations) > 0) {
                    $this->addFlash('error', (string) $violations[0]->getMessage());
                    return $this->redirectToRoute('admin_bundle_fee_bc_food_config');
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'BC Food fees updated.');
            return $this->redirectToRoute('admin_bundle_fee_bc_food_config');
        }

        return $this->render('@FeeBCFood/config.html.twig', [
            'fees'       => $fees,
            'taxClasses' => self::TAX_CLASSES,
        ]);
    }
}
