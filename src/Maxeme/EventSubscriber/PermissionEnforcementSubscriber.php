<?php

declare(strict_types=1);

namespace App\Maxeme\EventSubscriber;

use App\Maxeme\Security\Attribute\PubliclyAccessible;
use App\Maxeme\Security\Attribute\RequiresPermission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Secure by default: every action of an App\Maxeme\Controller must carry #[RequiresPermission]
 * or #[PubliclyAccessible] (on the method or its class), and an unmarked action is refused, so a
 * new screen fails closed rather than open.
 *
 * Throws HttpKernel's AccessDeniedHttpException, not Security's AccessDeniedException: the firewall
 * turns the latter into a login redirect for a remember-me session, which would hide a real denial.
 */
final class PermissionEnforcementSubscriber implements EventSubscriberInterface
{
    private const CONTROLLER_NAMESPACE = 'App\\Maxeme\\Controller\\';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        [$object, $method] = is_array($controller) ? $controller : [$controller, '__invoke'];

        if (!is_object($object) || !str_starts_with($object::class, self::CONTROLLER_NAMESPACE)) {
            return;
        }

        if (self::attribute($object, $method, PubliclyAccessible::class) !== null) {
            return;
        }

        $required = self::attribute($object, $method, RequiresPermission::class);
        if ($required === null) {
            throw new AccessDeniedHttpException(sprintf('%s::%s has no #[RequiresPermission] or #[PubliclyAccessible].', $object::class, $method));
        }

        if (!$this->security->isGranted($required->permission)) {
            throw new AccessDeniedHttpException(sprintf('Missing permission "%s".', $required->permission));
        }
    }

    /**
     * The attribute on the method, else on its class.
     *
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     *
     * @return T|null
     */
    public static function attribute(object|string $controller, string $method, string $attributeClass): ?object
    {
        $class = new \ReflectionClass($controller);
        $attributes = $class->hasMethod($method) ? $class->getMethod($method)->getAttributes($attributeClass) : [];
        $attributes = $attributes ?: $class->getAttributes($attributeClass);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
