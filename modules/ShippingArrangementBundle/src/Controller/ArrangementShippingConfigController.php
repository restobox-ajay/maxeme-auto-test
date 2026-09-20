<?php

declare(strict_types=1);

namespace ShippingArrangementBundle\Controller;

use App\Entity\CustomFieldDefinition;
use App\Repository\CompanyRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use ShippingArrangementBundle\EventSubscriber\ArrangementEligibilityFieldSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/shipping')]
final class ArrangementShippingConfigController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepo,
        private readonly CustomFieldDefinitionRepository $definitionRepo,
        private readonly CustomFieldValueRepository $valueRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/arrangement', name: 'admin_bundle_shipping_arrangement_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $companyDefinition = $this->definitionRepo->ensureBySlug(
            CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            ArrangementEligibilityFieldSubscriber::SLUG,
            ['label' => 'Eligible for Existing-Arrangement Shipping', 'fieldType' => CustomFieldDefinition::FIELD_TYPE_CHECKBOX, 'source' => ArrangementEligibilityFieldSubscriber::SOURCE],
        );
        $addressDefinition = $this->definitionRepo->ensureBySlug(
            CustomFieldDefinition::OBJECT_TYPE_COMPANY_ADDRESS,
            ArrangementEligibilityFieldSubscriber::SLUG,
            ['label' => 'Eligible for Existing-Arrangement Shipping', 'fieldType' => CustomFieldDefinition::FIELD_TYPE_CHECKBOX, 'source' => ArrangementEligibilityFieldSubscriber::SOURCE],
        );

        if ($request->isMethod('POST')) {
            $checkedCompanyIds = array_map('intval', array_keys($request->request->all('company')));
            $checkedAddressIds = array_map('intval', array_keys($request->request->all('address')));
            // Only companies/addresses actually rendered on the (possibly search-filtered) page the
            // form was submitted from — anything outside that, e.g. a company hidden by an active
            // search filter, has no checkbox in this POST at all and must be left untouched, not
            // treated as "unchecked".
            $visibleCompanyIds = array_map('intval', $request->request->all('visible_company_ids'));
            $visibleAddressIds = array_map('intval', $request->request->all('visible_address_ids'));

            // Only write a row when the checked state actually changes — setValue() creates a row
            // even for a null value, so writing unconditionally on every save would insert a stray
            // null-valued row for every company/address in the system, not just the ones toggled.
            $allCompanies = $this->companyRepo->findBy([], ['name' => 'ASC']);
            foreach ($allCompanies as $company) {
                if (in_array($company->getId(), $visibleCompanyIds, true)) {
                    $shouldBeEligible = in_array($company->getId(), $checkedCompanyIds, true);
                    if ($shouldBeEligible !== ($this->valueRepo->getValue($companyDefinition, $company->getId()) === '1')) {
                        $this->valueRepo->setValue($companyDefinition, $company->getId(), $shouldBeEligible ? '1' : null);
                    }
                }
                foreach ($company->getAddresses() as $address) {
                    if (!in_array($address->getId(), $visibleAddressIds, true)) {
                        continue;
                    }
                    $shouldBeEligible = in_array($address->getId(), $checkedAddressIds, true);
                    if ($shouldBeEligible !== ($this->valueRepo->getValue($addressDefinition, $address->getId()) === '1')) {
                        $this->valueRepo->setValue($addressDefinition, $address->getId(), $shouldBeEligible ? '1' : null);
                    }
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Eligibility updated.');

            return $this->redirectToRoute('admin_bundle_shipping_arrangement_index', [
                'q' => (string) $request->request->get('q', ''),
                'dir' => (string) $request->request->get('dir', 'asc'),
            ]);
        }

        $search = trim((string) $request->query->get('q', ''));
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $qb = $this->companyRepo->createQueryBuilder('c')->orderBy('c.name', $dir);
        if ($search !== '') {
            $qb->andWhere('c.name LIKE :q')->setParameter('q', '%' . $search . '%');
        }
        $companies = $qb->getQuery()->getResult();

        $companyChecked = [];
        $addressChecked = [];
        foreach ($companies as $company) {
            $companyChecked[$company->getId()] = $this->valueRepo->getValue($companyDefinition, $company->getId()) === '1';
            foreach ($company->getAddresses() as $address) {
                $addressChecked[$address->getId()] = $this->valueRepo->getValue($addressDefinition, $address->getId()) === '1';
            }
        }

        return $this->render('@ShippingArrangement/config.html.twig', [
            'companies' => $companies,
            'companyChecked' => $companyChecked,
            'addressChecked' => $addressChecked,
            'search' => $search,
            'currentDir' => strtolower($dir),
        ]);
    }
}
