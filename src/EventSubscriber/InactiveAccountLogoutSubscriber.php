<?php

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use App\Security\AccountStatusResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class InactiveAccountLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AccountStatusResolver $accountStatusResolver,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isIgnoredPath($request)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$user instanceof UserInterface) {
            return;
        }

        $message = $this->accountStatusResolver->getBlockMessage($user);
        if ($message === null) {
            return;
        }

        $this->tokenStorage->setToken(null);

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session->isStarted()) {
                $session->invalidate();
            }
        }

        $event->setResponse($this->buildResponse($request, $user, $message));
    }

    private function isIgnoredPath(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/assets')
            || str_starts_with($path, '/build');
    }

    private function buildResponse(Request $request, UserInterface $user, string $message): RedirectResponse|JsonResponse
    {
        $loginRoute = $user instanceof AdminUser ? 'admin_login' : 'customer_login';
        $redirectUrl = $this->router->generate($loginRoute);

        if ($this->expectsJson($request)) {
            $response = new JsonResponse([
                'ok' => false,
                'message' => $message,
                'redirect' => $redirectUrl,
            ], 403);
        } else {
            $response = new RedirectResponse($redirectUrl);
        }

        foreach ($this->rememberMeCookiesFor($user) as [$name, $path]) {
            $response->headers->setCookie(Cookie::create($name, '', 1, $path));
        }

        return $response;
    }

    private function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function rememberMeCookiesFor(UserInterface $user): array
    {
        if ($user instanceof AdminUser) {
            return [
                ['REMEMBERME', '/admin'],
                ['remember_me', '/admin'],
            ];
        }

        if ($user instanceof CustomerUser) {
            return [
                ['REMEMBERME', '/'],
                ['remember_me', '/'],
            ];
        }

        return [];
    }
}
