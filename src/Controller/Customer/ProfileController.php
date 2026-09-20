<?php

namespace App\Controller\Customer;

use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Service\CustomFieldRenderer;
use App\Validation\Dto\PasswordChangeRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProfileController extends AbstractCustomerController
{
    #[Route('/profile', name: 'customer_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, ValidatorInterface $validator): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your profile.');
            return $this->redirectToRoute('customer_login');
        }

        $errors = [];
        $values = [
            'first_name' => $user->getFirstName() ?? '',
            'last_name' => $user->getLastName() ?? '',
            'phone_number' => $user->getPhoneNumber() ?? '',
        ];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            $values['first_name'] = trim((string) $request->request->get('first_name', ''));
            $values['last_name'] = trim((string) $request->request->get('last_name', ''));
            $values['phone_number'] = trim((string) $request->request->get('phone_number', ''));

            $currentPassword = (string) $request->request->get('current_password', '');
            $newPassword = (string) $request->request->get('new_password', '');
            $confirmNewPassword = (string) $request->request->get('confirm_new_password', '');

            $firstNameViolations = $validator->validate($values['first_name'], new Assert\NotBlank(message: 'First name is required.'));
            if (count($firstNameViolations) > 0) {
                $errors['first_name'] = (string) $firstNameViolations[0]->getMessage();
            }

            $lastNameViolations = $validator->validate($values['last_name'], new Assert\NotBlank(message: 'Last name is required.'));
            if (count($lastNameViolations) > 0) {
                $errors['last_name'] = (string) $lastNameViolations[0]->getMessage();
            }

            $changingPassword = $currentPassword !== '' || $newPassword !== '' || $confirmNewPassword !== '';
            if ($changingPassword) {
                $passwordChange = new PasswordChangeRequest($user, $currentPassword, $newPassword, $confirmNewPassword);
                $errors = array_merge($errors, PasswordChangeRequest::fieldErrors($validator->validate($passwordChange)));
            }

            if ($errors === []) {
                $user->setFirstName($values['first_name']);
                $user->setLastName($values['last_name']);
                $user->setPhoneNumber($values['phone_number'] !== '' ? $values['phone_number'] : null);

                if ($changingPassword) {
                    $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                }

                $entityManager->flush();
                $this->addFlash('success', $changingPassword ? 'Profile and password updated.' : 'Profile updated.');
                return $this->redirectToRoute('customer_profile');
            }
        }

        return $this->render('customer/profile/index.html.twig', [
            'user' => $user,
            'errors' => $errors,
            'values' => $values,
        ]);
    }

    #[Route('/company-profile', name: 'customer_company_profile', methods: ['GET', 'POST'])]
    public function companyProfile(Request $request, EntityManagerInterface $entityManager, CustomFieldRenderer $customFieldRenderer, \App\Service\Region $region): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to view your company profile.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $user->getCompany();
        if ($company === null) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        // Staff can read the company profile but not change it (#522 §3). The template renders the
        // fields disabled for them; this is the half that actually holds, since a disabled input is
        // only a rendering hint.
        $canEdit = in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true);

        if ($request->isMethod('POST') && !$canEdit) {
            $this->addFlash('error', 'Only a company owner can edit the company profile.');
            return $this->redirectToRoute('customer_company_profile');
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            $newName = trim((string) $request->request->get('company_name', $company->getName()));

            if ($newName === '') {
                $this->addFlash('error', 'Company name is required.');
            } else {
                $company->setName($newName);
                $company->setTradeName(trim((string) $request->request->get('trade_name', '')) ?: null);
                $company->setBusinessLicense(trim((string) $request->request->get('business_license', '')) ?: null);
                // Account type is rendered readonly on this customer-facing form (admin-managed field), but a
                // readonly input still submits its value — reading account_type from the request here would let
                // a direct POST smuggle in a change. Never write it from this route.
                $company->setFirstName(trim((string) $request->request->get('company_first_name', '')) ?: null);
                $company->setLastName(trim((string) $request->request->get('company_last_name', '')) ?: null);
                $company->setPhoneNumber(trim((string) $request->request->get('company_phone', '')) ?: null);
                $company->setPrimaryEmail(trim((string) $request->request->get('company_email', '')) ?: null);
                // Sales rep is internal-only and is no longer part of the customer-facing form. Neither
                // $salesRepNote (the registration-time free text) nor $salesRepUser (the real, admin-managed
                // assignment, #718) is touched here — an unconditional write to either would blank it on
                // every customer save, since neither is ever submitted from this route.

                $billing = $company->getDefaultBillingAddress();
                // If the current default billing address is *also* the default shipping address (the normal
                // state right after registering with "same as shipping" checked), editing billing details
                // here must not overwrite it in place — that would silently destroy the shipping address.
                // Split into a separate billing-only record instead.
                $needsNewBillingAddress = !$billing instanceof CompanyAddress || $billing->isDefaultShipping();

                if ($needsNewBillingAddress) {
                    if ($billing instanceof CompanyAddress) {
                        $billing->setIsDefaultBilling(false);
                    }
                    $billing = new CompanyAddress();
                    $billing->setCompany($company);
                    $billing->setIsDefaultBilling(true);
                    $entityManager->persist($billing);
                    $company->addAddress($billing);
                } else {
                    $billing->setIsDefaultBilling(true);
                }

                $billing->setFirstName(trim((string) $request->request->get('bill_first_name', '')) ?: null);
                $billing->setLastName(trim((string) $request->request->get('bill_last_name', '')) ?: null);
                $billing->setPhone(trim((string) $request->request->get('bill_phone', '')) ?: null);
                $billing->setAddressLine1(trim((string) $request->request->get('bill_address1', '')) ?: null);
                $billing->setAddressLine2(trim((string) $request->request->get('bill_address2', '')) ?: null);
                $billing->setCity(trim((string) $request->request->get('bill_city', '')) ?: null);
                // Normalised and validated rather than stored as typed: an unrecognised province
                // matches no tax calculator, and safeCalculateTax() then invoices the order at $0.
                $billCountry = $region->normalizeCountry(trim((string) $request->request->get('bill_country', ''))) ?? 'CA';
                $billProvince = $region->normalizeProvince($billCountry, trim((string) $request->request->get('bill_province', '')));
                $billing->setCountry($region->isValidCountry($billCountry) ? $billCountry : null);
                $billing->setProvince($billProvince !== null && $region->isValidProvince($billCountry, $billProvince) ? $billProvince : null);
                $billing->setPostalCode(trim((string) $request->request->get('bill_postal_code', '')) ?: null);

                $entityManager->flush();
                $customFieldRenderer->saveFromRequest('company', $company, $request);
                $entityManager->flush();
                $this->addFlash('success', 'Company profile updated.');
                return $this->redirectToRoute('customer_company_profile');
            }
        }

        return $this->render('customer/profile/company.html.twig', [
            'company' => $company,
            'canEdit' => $canEdit,
            // Still rendered in 'edit' context for a staff viewer — the renderer has no read-only
            // context, so the template disables the whole form instead (a disabled fieldset also
            // stops its inputs submitting).
            'customFieldFragment' => $customFieldRenderer->renderFields('company', $company, 'edit'),
        ]);
    }
}
