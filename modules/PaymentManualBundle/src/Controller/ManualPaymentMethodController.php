<?php

declare(strict_types=1);

namespace PaymentManualBundle\Controller;

use App\Entity\PaymentMethod;
use App\Repository\PaymentMethodRepository;
use App\Validation\Constraint\ValidPaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use PaymentManualBundle\Payment\ManualPaymentMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/bundles/payments/manual')]
final class ManualPaymentMethodController extends AbstractController
{
    public function __construct(
        private readonly PaymentMethodRepository $paymentMethodRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_bundle_payment_manual_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@PaymentManual/index.html.twig', [
            'paymentMethods' => $this->paymentMethodRepo->findBySource(ManualPaymentMethod::SOURCE),
        ]);
    }

    #[Route('/new', name: 'admin_bundle_payment_manual_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            [$paymentMethod, $errors] = $this->buildFromRequest($request, new PaymentMethod());
            if (empty($errors)) {
                $this->em->persist($paymentMethod);
                $this->em->flush();
                $this->addFlash('success', 'Payment method created.');
                return $this->redirectToRoute('admin_bundle_payment_manual_index');
            }
        }

        return $this->render('@PaymentManual/new.html.twig', [
            'errors' => $errors,
            'data' => $request->request->all(),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_payment_manual_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PaymentMethod $paymentMethod): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            [$paymentMethod, $errors] = $this->buildFromRequest($request, $paymentMethod);
            if (empty($errors)) {
                $this->em->flush();
                $this->addFlash('success', 'Payment method updated.');
                return $this->redirectToRoute('admin_bundle_payment_manual_index');
            }
        }

        return $this->render('@PaymentManual/edit.html.twig', [
            'paymentMethod' => $paymentMethod,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_bundle_payment_manual_delete', methods: ['POST'])]
    public function delete(Request $request, PaymentMethod $paymentMethod): Response
    {
        $this->em->remove($paymentMethod);
        $this->em->flush();
        $this->addFlash('success', 'Payment method deleted.');
    

        return $this->redirectToRoute('admin_bundle_payment_manual_index');
    }

    /** @return array{PaymentMethod, array<string, string>} */
    private function buildFromRequest(Request $request, PaymentMethod $paymentMethod): array
    {
        $name = trim((string) $request->request->get('name', ''));
        $paymentMethod->setName($name);
        if ($paymentMethod->getId() === null) {
            $paymentMethod->setSlug('manual-' . uniqid())->setSource(ManualPaymentMethod::SOURCE);
        }

        $violations = Validation::createValidator()->validate($paymentMethod, new ValidPaymentMethod());

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return [$paymentMethod, $errors];
    }
}
