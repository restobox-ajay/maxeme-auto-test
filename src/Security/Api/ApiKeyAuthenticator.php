<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\ApiProblem;
use App\Entity\ApiCredential;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Repository\ApiCredentialRepository;
use App\Security\AccountStatusResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Authenticates /api/* requests from the X-Api-Key header — never a query string, so keys cannot
 * leak into browser history, referrers or access logs.
 *
 * The one thing to understand here: **this authenticates as a real CustomerUser.** Every customer
 * controller opens with `$user instanceof CustomerUser`, so authenticating as anything else makes
 * all of them unreachable — which is exactly what happened before, and why the old API bundle had
 * to reimplement pricing, visibility and category scoping and then watched all three copies drift.
 * Because the token carries the credential's owner, forwarding an API request into
 * CatalogController (or any other customer controller) simply works, and every rule those
 * controllers enforce applies unchanged.
 *
 * Three refusals are deliberately distinguishable, so a caller can tell "you typed the key wrong"
 * from "your company was switched off" from "your own key was revoked":
 *
 *   401  unknown key, revoked key, or an owner who can no longer log in
 *   403  the company's API access is off
 *   403  this user's API access is off
 *
 * The two 403s are checked on every request rather than only at key creation, so re-enabling a
 * company or a user makes existing keys work again with nothing to regenerate.
 */
final class ApiKeyAuthenticator implements AuthenticatorInterface
{
    public const HEADER = 'X-Api-Key';

    /** How stale last_used_at is allowed to get before it is worth a write. See recordUsage(). */
    private const LAST_USED_RESOLUTION_SECONDS = 300;

    public const COMPANY_DISABLED_MESSAGE = "Your company's API access has been disabled, please contact support to re-enable.";
    public const USER_DISABLED_MESSAGE = 'Your API access has been disabled by an administrator, please contact your company owner or support.';

    /**
     * The three 401 messages this class itself throws with a fixed, known wording — as opposed to
     * $blockMessage below, which comes back from AccountStatusResolver and varies by reason. Named
     * here, once, so onAuthenticationFailure() can classify them into a stable `code` (#715) by
     * comparing against the same constant the throw site used, instead of restating the string.
     */
    public const MISSING_KEY_MESSAGE = 'Missing ' . self::HEADER . ' header.';
    public const INVALID_KEY_MESSAGE = 'Invalid API key.';
    public const REVOKED_KEY_MESSAGE = 'This API key has been revoked.';

    public function __construct(
        private readonly ApiCredentialRepository $apiCredentialRepository,
        private readonly RateLimiterFactoryInterface $apiCatalogLimiter,
        private readonly RateLimiterFactoryInterface $apiUnauthenticatedLimiter,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountStatusResolver $accountStatus,
    ) {}

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = trim((string) $request->headers->get(self::HEADER, ''));
        if ($apiKey === '') {
            throw new CustomUserMessageAuthenticationException(self::MISSING_KEY_MESSAGE);
        }

        // Throttled per IP BEFORE the key is looked up, because every check below costs a query and
        // every one of them throws on failure — so a caller hammering an invalid or revoked key
        // would never reach the per-credential limiter at the bottom and would be unthrottled
        // entirely. This is the /api/ equivalent of login_throttling on the login forms.
        $ipLimit = $this->apiUnauthenticatedLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$ipLimit->isAccepted()) {
            throw new RateLimitExceededAuthenticationException((int) $ipLimit->getRetryAfter()->getTimestamp() - time());
        }

        $credential = $this->apiCredentialRepository->findOneByApiKey($apiKey);
        if (!$credential instanceof ApiCredential) {
            throw new CustomUserMessageAuthenticationException(self::INVALID_KEY_MESSAGE);
        }

        if (!$credential->isActive()) {
            // The owner revoked this themselves — deliberately distinct from an unknown key, and
            // from an administrator switching their access off.
            throw new CustomUserMessageAuthenticationException(self::REVOKED_KEY_MESSAGE);
        }

        $user = $credential->getCustomerUser();

        // The key is the user, so anything that stops them logging in stops the key too — and that
        // rule already exists, in full, in AccountStatusResolver: no company, company pending or
        // under review, company not active, user not active, all case-insensitively. Re-deriving
        // any part of it here is how the API ends up disagreeing with the site about who is allowed
        // in, which is the exact mistake this whole branch removed. It also has to run BEFORE the
        // request reaches the kernel, or InactiveAccountLogoutSubscriber catches it later and
        // answers a JSON client with a 302 to an HTML login page.
        $blockMessage = $this->accountStatus->getBlockMessage($user);
        if ($blockMessage !== null) {
            throw new CustomUserMessageAuthenticationException($blockMessage);
        }

        $company = $user->getCompany();
        if (!$company instanceof Company || !$company->isApiEnabled()) {
            throw new ApiAccessDisabledException(self::COMPANY_DISABLED_MESSAGE);
        }

        if (!$user->isApiEnabled()) {
            throw new ApiAccessDisabledException(self::USER_DISABLED_MESSAGE);
        }

        $limit = $this->apiCatalogLimiter->create((string) $credential->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new RateLimitExceededAuthenticationException((int) $limit->getRetryAfter()->getTimestamp() - time());
        }

        $this->recordUsage($credential);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): CustomerUser => $user)
        );
    }

    /**
     * Stamps last_used_at, coarsely and without ever being able to fail the request.
     *
     * Three things were wrong with doing this as a plain markUsed() + flush() on every call:
     *
     *  - it turned every read-only GET into a write. On SQLite with busy_timeout at 500ms
     *    (SqliteWalMiddleware), concurrent callers queue past that and the flush throws
     *    'database is locked' — on a request that only wanted to read.
     *  - a DBAL exception is not an AuthenticationException, so onAuthenticationFailure() never saw
     *    it and the caller got an HTML 500 instead of the JSON error contract.
     *  - it emitted an audit_log row per request, burying the events that matter (a key being
     *    minted, rotated or revoked) under pure telemetry.
     *
     * So: written at most once per RESOLUTION rather than per request — "last used" answers "is this
     * integration still running", which does not need second precision — issued as one direct
     * statement rather than through the unit of work, so it neither disturbs the EntityManager
     * mid-authentication nor trips the audit subscriber; and wrapped, because failing to record that
     * a valid key was used is not a reason to reject it.
     */
    private function recordUsage(ApiCredential $credential): void
    {
        $lastUsedAt = $credential->getLastUsedAt();
        $now = new \DateTimeImmutable();

        if ($lastUsedAt !== null && ($now->getTimestamp() - $lastUsedAt->getTimestamp()) < self::LAST_USED_RESOLUTION_SECONDS) {
            return;
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE api_credential SET last_used_at = :now WHERE id = :id',
                ['now' => $now->format('Y-m-d H:i:s'), 'id' => $credential->getId()],
            );
        } catch (\Throwable) {
            // Deliberately swallowed, and deliberately not logged: the failure this guards against
            // is write contention on the database, so recording it there would queue behind the
            // same lock. The request is authenticated either way.
        }
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        /** @var CustomerUser $user */
        $user = $passport->getUser();

        // The user's own roles are what the forwarded customer controllers check, so they have to
        // travel. ROLE_API_CLIENT is added only to satisfy the ^/api/ access_control rule and
        // grants nothing by itself — a staff member's key stays a staff member's key.
        return new PostAuthenticationToken($user, $firewallName, [...$user->getRoles(), 'ROLE_API_CLIENT']);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof RateLimitExceededAuthenticationException) {
            return (new ApiProblem('rate_limited', 'Rate limit exceeded', $exception->getMessageKey(), Response::HTTP_TOO_MANY_REQUESTS))
                ->toResponse(['Retry-After' => (string) max(1, $exception->getRetryAfterSeconds())]);
        }

        // A switched-off company or user is a valid key being refused, not a failure to identify —
        // 403, and with the reason, so support is not guessing. Two distinct codes because a caller
        // acts on them differently: one is "ask your company owner", the other is "ask support".
        if ($exception instanceof ApiAccessDisabledException) {
            $reason = $exception->getMessageKey();
            $code = $reason === self::COMPANY_DISABLED_MESSAGE ? 'company_api_disabled' : 'user_api_disabled';
            $title = $reason === self::COMPANY_DISABLED_MESSAGE ? 'Company API access disabled' : 'User API access disabled';

            return (new ApiProblem($code, $title, $reason, Response::HTTP_FORBIDDEN))->toResponse();
        }

        $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Authentication failed.';
        $code = match ($message) {
            self::MISSING_KEY_MESSAGE => 'missing_api_key',
            self::INVALID_KEY_MESSAGE => 'invalid_api_key',
            self::REVOKED_KEY_MESSAGE => 'api_key_revoked',
            // Everything else here is AccountStatusResolver::getBlockMessage()'s own wording, which
            // varies by reason (no company, company pending, user inactive, ...) — one stable code
            // covers all of them; the reason itself still travels, unchanged, in `detail`.
            default => 'account_access_blocked',
        };
        $title = match ($code) {
            'missing_api_key' => 'Missing API key',
            'invalid_api_key' => 'Invalid API key',
            'api_key_revoked' => 'API key revoked',
            'account_access_blocked' => 'Account access blocked',
            default => 'Authentication failed',
        };

        return (new ApiProblem($code, $title, $message, Response::HTTP_UNAUTHORIZED))->toResponse();
    }
}
