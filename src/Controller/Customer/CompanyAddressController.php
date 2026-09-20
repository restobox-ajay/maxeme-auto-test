<?php

namespace App\Controller\Customer;

use App\Service\Region;
use App\Service\TextInput;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Validation\Constraint\ValidCompanyAddressRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/company-addresses')]
final class CompanyAddressController extends AbstractCustomerController
{
    #[Route('/new', name: 'customer_company_addresses_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, Region $region): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage addresses.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $actor->getCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $returnTo = $this->normalizeReturnTo($request);

        // The address book is owner-managed (#522 §3). Staff can see the company's addresses — on
        // /company-profile and when picking one at checkout — but cannot add, change, remove or
        // re-default them from any entry point, checkout included. Repeated on every mutating action
        // below rather than hoisted, so no route can pick up a new caller and skip the check.
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can manage company addresses.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        /** @var array<string, string> $values */
        $values = [
            'label' => '',
            'first_name' => '',
            'last_name' => '',
            'company_name' => '',
            'phone' => '',
            'address1' => '',
            'address2' => '',
            'city' => '',
            'province' => '',
            'country' => 'CA',
            'postal_code' => '',
            'delivery_instructions' => '',
        ];

        /** @var array<string, string> $errors */
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            foreach (array_keys($values) as $key) {
                $values[$key] = trim((string) $request->request->get($key, ''));
            }

            // Validated server-side, not just constrained by the <select>: the dropdown is a
            // suggestion a client can ignore, and an unrecognised province reaches no tax
            // calculator, which silently produces a $0-tax order.
            $valuesBag = new \ArrayObject($values);
            $violations = Validation::createValidator()->validate($valuesBag, new ValidCompanyAddressRequest($region));
            $values = $valuesBag->getArrayCopy();
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            if ($errors === []) {
                $address = new CompanyAddress();
                $address->setCompany($company);
                $address->setIsDefaultBilling(false);

                $hasDefaultShipping = false;
                foreach ($company->getAddresses() as $existing) {
                    // A dual-purpose address (also serving as default billing) still counts as
                    // "already the default shipping address" — otherwise a second address added
                    // here would also get flagged default shipping, leaving two rows with that flag.
                    if ($existing->isDefaultShipping()) {
                        $hasDefaultShipping = true;
                        break;
                    }
                }
                $address->setIsDefaultShipping(!$hasDefaultShipping);

                $address->setLabel($values['label']);
                $address->setFirstName($values['first_name'] ?: null);
                $address->setLastName($values['last_name'] ?: null);
                $address->setCompanyName($values['company_name'] ?: $company->getName());
                $address->setPhone($values['phone'] ?: null);
                $address->setAddressLine1($values['address1'] ?: null);
                $address->setAddressLine2($values['address2'] ?: null);
                $address->setCity($values['city'] ?: null);
                $address->setProvince($values['province'] ?: null);
                $address->setCountry($values['country'] ?: null);
                $address->setPostalCode($values['postal_code'] ?: null);
                // Capped rather than left as the bare trimmed string: the column is `type: 'text'`,
                // so nothing downstream bounds it, and this address is copied verbatim onto every
                // order placed against it. See TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH.
                $address->setDeliveryInstructions(TextInput::nullableStringMax(
                    $values['delivery_instructions'],
                    TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
                ));

                $entityManager->persist($address);
                $company->addAddress($address);
                $entityManager->flush();

                $this->addFlash('success', 'Address added.');
                return $this->redirectToRoute($this->returnRouteName($returnTo));
            }
        }

        return $this->render('customer/company_address/form.html.twig', [
            'page_title' => 'Add Address',
            'form_action' => $this->generateUrl('customer_company_addresses_create'),
            'csrf_id' => 'customer_company_addresses_create',
            'return_to' => $returnTo,
            'return_path' => $this->generateUrl($this->returnRouteName($returnTo)),
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id<\\d+>}/edit', name: 'customer_company_addresses_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager, Region $region): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage addresses.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $returnTo = $this->normalizeReturnTo($request);

        // Owner-only — see create() above (#522 §3).
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can manage company addresses.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        $address = $entityManager->getRepository(CompanyAddress::class)->find($id);
        if (!$address instanceof CompanyAddress || $address->getCompany()->getId() !== $companyId) {
            $this->addFlash('error', 'Address not found.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        /** @var array<string, string> $values */
        $values = [
            'label' => (string) ($address->getLabel() ?? ''),
            'first_name' => (string) ($address->getFirstName() ?? ''),
            'last_name' => (string) ($address->getLastName() ?? ''),
            'company_name' => (string) ($address->getCompanyName() ?? ''),
            'phone' => (string) ($address->getPhone() ?? ''),
            'address1' => (string) ($address->getAddressLine1() ?? ''),
            'address2' => (string) ($address->getAddressLine2() ?? ''),
            'city' => (string) ($address->getCity() ?? ''),
            'province' => (string) ($address->getProvince() ?? ''),
            'country' => (string) ($address->getCountry() ?? ''),
            'postal_code' => (string) ($address->getPostalCode() ?? ''),
            'delivery_instructions' => (string) ($address->getDeliveryInstructions() ?? ''),
        ];

        /** @var array<string, string> $errors */
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            foreach (array_keys($values) as $key) {
                $values[$key] = trim((string) $request->request->get($key, ''));
            }

            // Validated server-side, not just constrained by the <select>: the dropdown is a
            // suggestion a client can ignore, and an unrecognised province reaches no tax
            // calculator, which silently produces a $0-tax order.
            $valuesBag = new \ArrayObject($values);
            $violations = Validation::createValidator()->validate($valuesBag, new ValidCompanyAddressRequest($region));
            $values = $valuesBag->getArrayCopy();
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            if ($errors === []) {
                $address->setLabel($values['label']);
                $address->setFirstName($values['first_name'] ?: null);
                $address->setLastName($values['last_name'] ?: null);
                $address->setCompanyName($values['company_name'] ?: $address->getCompany()->getName());
                $address->setPhone($values['phone'] ?: null);
                $address->setAddressLine1($values['address1'] ?: null);
                $address->setAddressLine2($values['address2'] ?: null);
                $address->setCity($values['city'] ?: null);
                $address->setProvince($values['province'] ?: null);
                $address->setCountry($values['country'] ?: null);
                $address->setPostalCode($values['postal_code'] ?: null);
                // Same cap as the create path above — an edit is just as capable of pasting an
                // unbounded value into a column that has no declared width.
                $address->setDeliveryInstructions(TextInput::nullableStringMax(
                    $values['delivery_instructions'],
                    TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
                ));

                $entityManager->flush();
                $this->addFlash('success', 'Address updated.');
                return $this->redirectToRoute($this->returnRouteName($returnTo));
            }
        }

        return $this->render('customer/company_address/form.html.twig', [
            'page_title' => 'Edit Address',
            'form_action' => $this->generateUrl('customer_company_addresses_edit', ['id' => $id]),
            'csrf_id' => 'company_address_edit_'.$id,
            'return_to' => $returnTo,
            'return_path' => $this->generateUrl($this->returnRouteName($returnTo)),
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id<\\d+>}/delete', name: 'customer_company_addresses_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to continue.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $returnTo = $this->normalizeReturnTo($request);

        // Owner-only — see create() above (#522 §3).
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can manage company addresses.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        $token = (string) $request->request->get('_token', '');
        $address = $entityManager->getRepository(CompanyAddress::class)->find($id);
        if (!$address instanceof CompanyAddress || $address->getCompany()->getId() !== $companyId) {
            $this->addFlash('error', 'Address not found.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        $wasDefaultShipping = $address->isDefaultShipping();
        $wasDefaultBilling = $address->isDefaultBilling();
        $entityManager->remove($address);
        $entityManager->flush();

        if ($wasDefaultShipping) {
            /** @var list<CompanyAddress> $remaining */
            $remaining = $entityManager->getRepository(CompanyAddress::class)->findBy(['company' => $companyId], ['createdAt' => 'ASC']);
            foreach ($remaining as $candidate) {
                if (!$candidate->isDefaultBilling()) {
                    $candidate->setIsDefaultShipping(true);
                    $entityManager->flush();
                    break;
                }
            }
        }

        if ($wasDefaultBilling) {
            /** @var list<CompanyAddress> $remaining */
            $remaining = $entityManager->getRepository(CompanyAddress::class)->findBy(['company' => $companyId], ['createdAt' => 'ASC']);
            foreach ($remaining as $candidate) {
                if (!$candidate->isDefaultShipping()) {
                    $candidate->setIsDefaultBilling(true);
                    $entityManager->flush();
                    break;
                }
            }
        }

        $this->addFlash('success', 'Address removed.');
        return $this->redirectToRoute($this->returnRouteName($returnTo));
    }

    #[Route('/{id<\\d+>}/default/{type}', name: 'customer_company_addresses_default', methods: ['POST'])]
    public function setDefault(int $id, string $type, Request $request, EntityManagerInterface $entityManager): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage addresses.');
            return $this->redirectToRoute('customer_login');
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        $returnTo = $this->normalizeReturnTo($request);

        // Owner-only — see create() above (#522 §3).
        if (!in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true)) {
            $this->addFlash('error', 'Only a company owner can manage company addresses.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        $token = (string) $request->request->get('_token', '');
        if ($type !== 'billing' && $type !== 'shipping') {
            $this->addFlash('error', 'Address not found.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        $address = $entityManager->getRepository(CompanyAddress::class)->find($id);
        if (!$address instanceof CompanyAddress || $address->getCompany()->getId() !== $companyId) {
            $this->addFlash('error', 'Address not found.');
            return $this->redirectToRoute($this->returnRouteName($returnTo));
        }

        foreach ($address->getCompany()->getAddresses() as $existingAddress) {
            if ($type === 'billing') {
                $existingAddress->setIsDefaultBilling(false);
            } else {
                $existingAddress->setIsDefaultShipping(false);
            }
        }

        if ($type === 'billing') {
            $address->setIsDefaultBilling(true);
        } else {
            $address->setIsDefaultShipping(true);
        }

        $entityManager->flush();
        $this->addFlash('success', sprintf('Default %s address was updated.', $type));

        return $this->redirectToRoute($this->returnRouteName($returnTo));
    }

    private function normalizeReturnTo(Request $request): string
    {
        $returnTo = trim((string) $request->request->get('return_to', $request->query->get('return_to', 'profile')));
        return $returnTo === 'checkout' ? 'checkout' : 'profile';
    }

    private function returnRouteName(string $returnTo): string
    {
        return $returnTo === 'checkout' ? 'customer_checkout' : 'customer_company_profile';
    }
}
