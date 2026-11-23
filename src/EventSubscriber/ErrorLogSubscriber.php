<?php

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records an uncaught request exception in error_log (Logs › Error Log).
 *
 * Plain DBAL insert, never the ORM: flushing the EntityManager here would also commit whatever the
 * failing request had persisted or changed before it threw (a repair order half-saved without its
 * appointments, for one), and the EntityManager may be closed after a failed flush anyway. The same
 * rule as App\Maxeme\Audit\ErrorLogWriter.
 */
final class ErrorLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $exception = $event->getThrowable();

        $route = (string) ($request->attributes->get('_route') ?? '');
        $area = $route !== '' ? $route : ($request->getMethod() . ' ' . $request->getPathInfo());

        $payload = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $route,
            'ip' => $request->getClientIp(),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => substr($exception->getTraceAsString(), 0, 20000),
        ];

        try {
            [$userType, $userId, $userEmail] = $this->resolveUser();

            $this->connection->insert('error_log', [
                'level' => 'error',
                'area' => mb_substr($area, 0, 80),
                'message' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: $exception->getMessage(),
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'ip_address' => $request->getClientIp(),
                'user_type' => $userType,
                'user_id' => $userId,
                'user_email' => $userEmail,
                'referrer' => $request->headers->get('referer'),
            ]);
        } catch (\Throwable) {
            // Avoid cascading failures if DB is down or logging itself errors.
        }
    }

    /** @return array{0: ?string, 1: ?int, 2: ?string} */
    private function resolveUser(): array
    {
        $user = $this->security->getUser();

        if ($user instanceof AdminUser) {
            return ['staff', $user->getId(), $user->getEmail()];
        }

        if ($user instanceof CustomerUser) {
            return ['customer', $user->getId(), $user->getEmail()];
        }

        return ['guest', null, null];
    }
}
