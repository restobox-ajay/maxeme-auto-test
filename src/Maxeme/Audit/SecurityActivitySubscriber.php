<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Entity\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Staff sign-in, sign-out, failed sign-ins and refused admin pages, into the Activity Log. */
final class SecurityActivitySubscriber implements EventSubscriberInterface
{
    private const FIREWALL = 'admin';

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            // Before the firewall's own exception listener turns the denial into a response.
            KernelEvents::EXCEPTION => ['onException', 10],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() === self::FIREWALL && $event->getUser() instanceof AdminUser) {
            $this->activity->signedIn();
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::FIREWALL) {
            return;
        }

        $identifier = (string) $event->getRequest()->request->get('_username', '');
        $this->activity->signInFailed($identifier, $event->getException()->getMessageKey());
    }

    public function onLogout(LogoutEvent $event): void
    {
        if ($event->getToken()?->getUser() instanceof AdminUser) {
            $this->activity->signedOut();
        }
    }

    /** A signed-in staff member refused a page (not a signed-out visitor sent to the login form). */
    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AccessDeniedHttpException && !$exception instanceof AccessDeniedException) {
            return;
        }
        if (!$this->security->getUser() instanceof AdminUser) {
            return;
        }

        $request = $event->getRequest();
        $this->activity->accessDenied($request->getMethod(), $request->getPathInfo(), $exception->getMessage() ?: 'Access denied');
    }
}
