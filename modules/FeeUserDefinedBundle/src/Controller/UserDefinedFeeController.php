<?php

declare(strict_types=1);

namespace FeeUserDefinedBundle\Controller;

use App\Entity\Fee;
use App\Repository\FeeRepository;
use App\Validation\Constraint\ValidUserDefinedFeeRequest;
use Doctrine\ORM\EntityManagerInterface;
use FeeUserDefinedBundle\Fee\UserDefinedFeeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/bundles/fees/user-defined')]
final class UserDefinedFeeController extends AbstractController
{
    private const TAX_CLASSES = ['E' => 'Exempt (E)', 'G' => 'GST only (G)', 'S' => 'Standard (S)'];

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_bundle_fee_user_defined_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@FeeUserDefined/index.html.twig', [
            'fees' => $this->feeRepo->findBySource(UserDefinedFeeCalculator::SOURCE),
        ]);
    }

    private const SESSION_FORM_STATE_KEY = 'fee_user_defined_form_state_';

    #[Route('/new', name: 'admin_bundle_fee_user_defined_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $session = $request->getSession();
        $sessionKey = self::SESSION_FORM_STATE_KEY . 'new';

        if ($request->isMethod('POST')) {
            [$fee, $errors] = $this->buildFromRequest($request, new Fee());
            if (empty($errors)) {
                $this->em->persist($fee);
                $this->em->flush();
                $this->addFlash('success', 'Fee created.');
                return $this->redirectToRoute('admin_bundle_fee_user_defined_index');
            }

            // Redirect instead of rendering the error directly (Post/Redirect/Get) — otherwise
            // reloading the page resubmits the same invalid POST and the error never goes away
            // until the user navigates elsewhere and back.
            $session->set($sessionKey, ['errors' => $errors, 'data' => $request->request->all()]);

            return $this->redirectToRoute('admin_bundle_fee_user_defined_new');
        }

        $state = $session->remove($sessionKey) ?? [];

        return $this->render('@FeeUserDefined/new.html.twig', [
            'taxClasses' => self::TAX_CLASSES,
            'errors'     => $state['errors'] ?? [],
            'data'       => $state['data'] ?? [],
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_fee_user_defined_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Fee $fee): Response
    {
        $session = $request->getSession();
        $sessionKey = self::SESSION_FORM_STATE_KEY . $fee->getId();

        if ($request->isMethod('POST')) {
            [$fee, $errors] = $this->buildFromRequest($request, $fee);
            if (empty($errors)) {
                $this->em->flush();
                $this->addFlash('success', 'Fee updated.');
                return $this->redirectToRoute('admin_bundle_fee_user_defined_index');
            }

            // Same Post/Redirect/Get reasoning as new() above.
            $session->set($sessionKey, ['errors' => $errors, 'data' => $request->request->all()]);

            return $this->redirectToRoute('admin_bundle_fee_user_defined_edit', ['id' => $fee->getId()]);
        }

        $state = $session->remove($sessionKey) ?? [];

        return $this->render('@FeeUserDefined/edit.html.twig', [
            'fee'        => $fee,
            'taxClasses' => self::TAX_CLASSES,
            'errors'     => $state['errors'] ?? [],
            'data'       => $state['data'] ?? [],
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_bundle_fee_user_defined_delete', methods: ['POST'])]
    public function delete(Request $request, Fee $fee): Response
    {
        $this->em->remove($fee);
        $this->em->flush();
        $this->addFlash('success', 'Fee deleted.');
    

        return $this->redirectToRoute('admin_bundle_fee_user_defined_index');
    }

    /** @return array{Fee, array<string, string>} */
    private function buildFromRequest(Request $request, Fee $fee): array
    {
        $name         = trim((string) $request->request->get('name', ''));
        $defaultValue = $request->request->get('default_value', '');
        $taxClass     = (string) $request->request->get('tax_class', '');
        $placement    = (string) $request->request->get('placement', Fee::PLACEMENT_MAIN_LINE);

        $violations = Validation::createValidator()->validate(
            ['name' => $name, 'default_value' => $defaultValue, 'tax_class' => $taxClass, 'placement' => $placement],
            new ValidUserDefinedFeeRequest(self::TAX_CLASSES),
        );

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        if (empty($errors)) {
            if ($fee->getId() === null) {
                $fee->setSlug('user-' . uniqid())->setSource(UserDefinedFeeCalculator::SOURCE);
            }
            $fee->setName($name)->setDefaultValue((float) $defaultValue)->setTaxClass($taxClass)->setPlacement($placement);
        }

        return [$fee, $errors];
    }
}
