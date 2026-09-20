<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use App\Repository\DbConsoleSessionRepository;
use App\Security\ConsoleCookie;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Revokes an admin's database-console sessions when they log out.
 *
 * The console gateway (public/db-admin.php) runs outside the kernel and never reads the Symfony
 * session — it authorises purely against a db_console_session row. So logging out is invisible to it:
 * without this, the token in the browser keeps working until its own expiry lapses, and "log out"
 * quietly fails to end the one session that reaches raw SQL.
 *
 * Deleting the rows is what actually revokes; clearing the cookie is tidiness, since a copied token
 * value would work regardless of what the browser still holds.
 *
 * Runs at the default priority, i.e. after Symfony's DefaultLogoutListener (64) has put a response on
 * the event, so there is something to attach the cookie-clearing header to. The deletion happens
 * either way if that response is ever absent.
 */
final class DbConsoleLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DbConsoleSessionRepository $sessions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        // Only admins ever hold a console session; customers log out through the same event.
        if (!$user instanceof AdminUser || $user->getId() === null) {
            return;
        }

        $this->sessions->deleteForAdmin($user->getId());

        // Expire the cookie on the path it was scoped to when minted, or the browser keeps sending a
        // token that no longer resolves.
        $event->getResponse()?->headers->setCookie(
            Cookie::create(ConsoleCookie::COOKIE_NAME, '', 1, '/db-admin.php'),
        );
    }
}
