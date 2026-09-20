<?php

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use App\Entity\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ErrorLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

            $log = (new ErrorLog())
                ->setLevel('error')
                ->setArea($area)
                ->setMessage(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $exception->getMessage())
                ->setIpAddress($request->getClientIp())
                ->setUserType($userType)
                ->setUserId($userId)
                ->setUserEmail($userEmail)
                ->setReferrer($request->headers->get('referer'));

            $this->entityManager->persist($log);
            $this->entityManager->flush();
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
