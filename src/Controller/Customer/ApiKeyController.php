<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\ApiCredential;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Repository\ApiCredentialRepository;
use App\Security\Api\ApiKeyGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Each user's own API key, on their own profile.
 *
 * Strictly self-service, and strictly self-only: there is no route here that takes a user id, so
 * there is no shape of request that operates on somebody else's key. Not "a teammate is checked and
 * rejected" — there is nothing to check, because the acting user is the only user any of these
 * actions can reach. A company owner has no more access to a staff member's key than the staff
 * member has to theirs.
 *
 * That is the difference from the removed bundle's /company-api page, which showed one key shared
 * by the whole company. A shared key belongs to nobody, which is why it could not authenticate as
 * anybody — see App\Entity\ApiCredential.
 *
 * Whether the user may hold a key at all is an administrator's decision, in two parts:
 * Company::$apiEnabled and CustomerUser::$apiEnabled. Both are read here and neither is writable
 * here — this page operates a key, it never grants permission to have one.
 */
#[Route('/profile/api-key')]
final class ApiKeyController extends AbstractCustomerController
{
    #[Route('', name: 'customer_api_key', methods: ['GET'])]
    public function index(ApiCredentialRepository $credentials): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage your API key.');
            return $this->redirectToRoute('customer_login');
        }

        return $this->render('customer/profile/api_key.html.twig', [
            'credential' => $credentials->findOneByUser($user),
            'companyEnabled' => $user->getCompany()?->isApiEnabled() ?? false,
            'userEnabled' => $user->isApiEnabled(),
        ]);
    }

    /**
     * Generates a first key, or replaces an existing one. Regeneration reuses the row rather than
     * creating a second, because the unique constraint is the rule "one key per user" and the row
     * carries createdAt/lastUsedAt worth keeping continuous.
     */
    #[Route('/generate', name: 'customer_api_key_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        EntityManagerInterface $entityManager,
        ApiCredentialRepository $credentials,
        ApiKeyGenerator $keyGenerator,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage your API key.');
            return $this->redirectToRoute('customer_login');
        }

        // Checked on the way in as well as on every API request. Minting a key that the
        // authenticator would refuse on first use would just be a confusing way to say no.
        if (!$this->mayHoldAKey($user)) {
            $this->addFlash('error', 'API access is not enabled for your account. Please contact support.');
            return $this->redirectToRoute('customer_api_key');
        }

        $credential = $credentials->findOneByUser($user);
        $isNew = !$credential instanceof ApiCredential;

        if ($isNew) {
            $credential = (new ApiCredential())->setCustomerUser($user);
            $entityManager->persist($credential);
        }

        $credential->setApiKey($keyGenerator->generate())->setStatus(ApiCredential::STATUS_ACTIVE);
        $entityManager->flush();

        $this->addFlash('success', $isNew ? 'API key generated.' : 'API key regenerated. The previous key no longer works.');

        return $this->redirectToRoute('customer_api_key');
    }

    /**
     * Revokes without deleting. The row stays so the page can keep saying "you had a key and it is
     * off" rather than silently reverting to looking like a user who never had one, and so
     * re-enabling is a status change rather than a fresh grant.
     */
    #[Route('/revoke', name: 'customer_api_key_revoke', methods: ['POST'])]
    public function revoke(Request $request, EntityManagerInterface $entityManager, ApiCredentialRepository $credentials): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Please log in to manage your API key.');
            return $this->redirectToRoute('customer_login');
        }

        $credential = $credentials->findOneByUser($user);
        if ($credential instanceof ApiCredential) {
            $credential->setStatus(ApiCredential::STATUS_REVOKED);
            $entityManager->flush();
            $this->addFlash('success', 'API key revoked.');
        }

        return $this->redirectToRoute('customer_api_key');
    }

    /** Both gates, in the order the authenticator applies them. */
    private function mayHoldAKey(CustomerUser $user): bool
    {
        $company = $user->getCompany();

        return $company instanceof Company && $company->isApiEnabled() && $user->isApiEnabled();
    }
}
