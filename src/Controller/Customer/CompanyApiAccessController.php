<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Repository\ApiCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Owner-managed API access for the company's users (#532).
 *
 * This page grants PERMISSION TO HOLD A KEY. It never operates one. An owner can switch a
 * teammate's access on or off and can do the same to themselves, and that is the entire surface:
 * there is no action here that generates, reveals, rotates or revokes anybody's key, including the
 * owner's own. Minting stays where #521 put it — /profile/api-key, self-service and self-only —
 * because a key authenticates as the person it belongs to, so anyone able to read one could act as
 * them. Nothing on this page changes that.
 *
 * The two gates it sits between:
 *
 *   Company::$apiEnabled       administrator's, and only theirs. Not writable here, not writable by
 *                              any customer-side route. It is the lever an administrator uses to
 *                              shut a company's API down, which is why an owner must not be able to
 *                              undo it.
 *   CustomerUser::$apiEnabled  the owner's. Still settable by an administrator on the admin user
 *                              form, but with an active owner in the company that checkbox is now
 *                              effectively advisory — same field, last writer wins. That is
 *                              intended: an administrator who needs a company off turns the COMPANY
 *                              off, rather than playing tug-of-war over individual people.
 *
 * Gated on ROLE_COMPANY_OWNER and nothing else — deliberately never on the actor's own
 * $apiEnabled. An owner who switches themselves off has to be able to switch back on; gating the
 * page on the flag it edits would let a sole owner lock their company out of its own API in one
 * click, with support as the only way back.
 *
 * Disabling is a PAUSE, not a revocation: the ApiCredential row is untouched, ApiKeyAuthenticator
 * simply refuses it on every request while the flag is off, and re-enabling makes the same key work
 * again with nothing to regenerate. The template says so, because an owner who assumes otherwise
 * tells people to regenerate keys for no reason.
 *
 * Every change is audited without anything here doing the auditing: AuditLogSubscriber records the
 * CustomerUser changeset on flush, and 'apikey' is in its redaction list, so the log carries who
 * enabled or disabled whom and never a key value.
 */
#[Route('/company-users/api-access')]
final class CompanyApiAccessController extends AbstractCustomerController
{
    #[Route('', name: 'customer_company_api_access', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, ApiCredentialRepository $apiCredentials): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage API access.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $actor->getCompany();
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        // Owner-only, enforced here and not merely by hiding the button on My Company User — a
        // hidden link stops nobody who types the URL. Staff get the read-only API Access column on
        // that page instead, which answers "who has access" without offering to change it. See #522.
        if (!$this->actorIsOwner($actor)) {
            $this->addFlash('error', 'Only a company owner can manage API access.');
            return $this->redirectToRoute('customer_company_users');
        }

        $users = $this->companyUsers($entityManager, $company);

        return $this->render('customer/company_user/api_access.html.twig', [
            'users' => $users,
            'companyEnabled' => $company->isApiEnabled(),
            'apiKeyFlags' => $apiCredentials->activeKeyFlagsForUserIds(
                array_values(array_filter(array_map(static fn (CustomerUser $u): ?int => $u->getId(), $users)))
            ),
            'actorId' => $actor->getId(),
        ]);
    }

    /**
     * One user, on or off. A POST rather than a link because it changes state, and it carries the
     * target state explicitly rather than flipping whatever is there — a toggle that reads the
     * current value does the wrong thing when two owners act on the same person at once, or when
     * someone double-submits.
     */
    #[Route('/{id<\d+>}', name: 'customer_company_api_access_set', methods: ['POST'])]
    public function set(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage API access.');
            return $this->redirectToRoute('customer_login');
        }

        $company = $actor->getCompany();
        if (!$company instanceof Company) {
            $this->addFlash('error', 'Your account is not linked to a company yet.');
            return $this->redirectToRoute('customer_home');
        }

        if (!$this->actorIsOwner($actor)) {
            $this->addFlash('error', 'Only a company owner can manage API access.');
            return $this->redirectToRoute('customer_company_users');
        }

        // The company gate is an administrator's decision and is not ours to work around. Enabling a
        // person while the company is off would mint a permission the authenticator refuses anyway,
        // so it would only look like it worked.
        if (!$company->isApiEnabled()) {
            $this->addFlash('error', 'API access is not enabled for your company. Please ask an administrator to activate it.');
            return $this->redirectToRoute('customer_company_api_access');
        }

        // Scoped to the actor's own company, so an id from another company is "not found" rather
        // than a target. Same rule the rest of /company-users applies.
        $user = $entityManager->getRepository(CustomerUser::class)->find($id);
        if (!$user instanceof CustomerUser || $user->getCompany()?->getId() !== $company->getId()) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('customer_company_api_access');
        }

        $enabled = $request->request->getBoolean('enabled');
        if ($user->isApiEnabled() === $enabled) {
            // Nothing changed — say nothing rather than claiming a change and writing an audit row
            // for a flush that had no changeset.
            return $this->redirectToRoute('customer_company_api_access');
        }

        $user->setApiEnabled($enabled);
        $entityManager->flush();

        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();

        $this->addFlash('success', $enabled
            ? 'API access enabled for ' . $name . '. They can now create their own key from their profile.'
            : 'API access disabled for ' . $name . '. Any key they hold stops working until you switch this back on.');

        return $this->redirectToRoute('customer_company_api_access');
    }

    /** @return list<CustomerUser> */
    private function companyUsers(EntityManagerInterface $entityManager, Company $company): array
    {
        /** @var list<CustomerUser> $users */
        $users = $entityManager->getRepository(CustomerUser::class)
            ->createQueryBuilder('u')
            ->andWhere('u.company = :company')
            ->setParameter('company', $company)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    private function actorIsOwner(CustomerUser $actor): bool
    {
        return in_array('ROLE_COMPANY_OWNER', $actor->getRoles(), true);
    }
}
