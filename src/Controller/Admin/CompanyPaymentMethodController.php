<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Entity\CompanyPaymentMethod;
use App\Entity\PaymentMethod;
use App\Repository\CompanyPaymentMethodRepository;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompanyPaymentMethodController extends AbstractAdminController
{
    #[Route('/admin/company-payment-methods/{companyId}/{paymentMethodId}/toggle', name: 'admin_company_payment_method_toggle', methods: ['POST'])]
    public function toggle(int $companyId, int $paymentMethodId, Request $request, EntityManagerInterface $entityManager, CompanyPaymentMethodRepository $companyPaymentMethodRepo): Response
    {
        $company = $entityManager->find(Company::class, $companyId);
        $paymentMethod = $entityManager->find(PaymentMethod::class, $paymentMethodId);

        if (!$company instanceof Company || !$paymentMethod instanceof PaymentMethod) {
            $this->addFlash('error', 'Customer or payment method could not be found.');

            return $this->redirectBackFromToggle($companyId);
        }

        $grant = $companyPaymentMethodRepo->findOneBy(['company' => $company, 'paymentMethod' => $paymentMethod]);
        $currentStatus = $grant?->getStatus() ?? CompanyPaymentMethod::STATUS_ACTIVE;
        $newStatus = $currentStatus === CompanyPaymentMethod::STATUS_ACTIVE
            ? CompanyPaymentMethod::STATUS_INACTIVE
            : CompanyPaymentMethod::STATUS_ACTIVE;

        $grant ??= (new CompanyPaymentMethod())->setCompany($company)->setPaymentMethod($paymentMethod);
        $grant->setStatus($newStatus);

        $entityManager->persist($grant);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is now %s for "%s".', $paymentMethod->getName(), strtolower($newStatus), $company->getName()));

        return $this->redirectBackFromToggle($companyId);
    }

    #[Route('/admin/company/{id}/payment-methods', name: 'admin_company_payment_methods_by_id', methods: ['GET'])]
    public function forCompany(int $id, EntityManagerInterface $entityManager, PaymentMethodRepository $paymentMethodRepo, CompanyPaymentMethodRepository $companyPaymentMethodRepo): Response
    {
        $company = $entityManager->find(Company::class, $id);
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Customer could not be found.');

            return $this->redirectToRoute('admin_company_index');
        }

        return $this->render('admin/company_payment_method/for_company.html.twig', [
            'company' => $this->companyToRow($company),
            'rows' => $this->paymentMethodRowsForCompany($company, $paymentMethodRepo, $companyPaymentMethodRepo),
        ]);
    }

    private function redirectBackFromToggle(int $companyId): Response
    {
        // The per-company page is the only place the toggle is used now (the standalone
        // /admin/company-payment-methods listing was removed — issue #123).
        return $this->redirectToRoute('admin_company_payment_methods_by_id', ['id' => $companyId]);
    }

    /** @return list<array{paymentMethod: PaymentMethod, status: string}> */
    private function paymentMethodRowsForCompany(Company $company, PaymentMethodRepository $paymentMethodRepo, CompanyPaymentMethodRepository $companyPaymentMethodRepo): array
    {
        $statusByMethodId = [];
        foreach ($companyPaymentMethodRepo->findAllForCompany($company) as $grant) {
            $statusByMethodId[$grant->getPaymentMethod()->getId()] = $grant->getStatus();
        }

        return array_map(static fn (PaymentMethod $pm): array => [
            'paymentMethod' => $pm,
            'status' => $statusByMethodId[$pm->getId()] ?? CompanyPaymentMethod::STATUS_ACTIVE,
        ], $paymentMethodRepo->findBy([], ['name' => 'ASC']));
    }
}
