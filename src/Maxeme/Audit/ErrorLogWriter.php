<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Entity\AdminUser;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes one row into core's error_log (the table Logs › Error Log reads) for errors that are not
 * an uncaught request exception, which core's ErrorLogSubscriber already records: errors only
 * logged (a failed email, a messenger failure), console command errors, and browser errors.
 *
 * Plain DBAL insert, never the ORM: an error can arrive mid-flush or with the EntityManager closed,
 * and flushing it here would also flush whatever the failing request had half-changed. Services
 * come from a locator because the logger decorating ErrorRecordingLogger is itself a dependency
 * of Doctrine and Security, so injecting them directly would be circular.
 */
final class ErrorLogWriter
{
    private const MAX_MESSAGE = 20000;

    public function __construct(
        #[AutowireLocator([
            'connection' => Connection::class,
            'security' => Security::class,
            'request_stack' => RequestStack::class,
        ])]
        private readonly ContainerInterface $services,
    ) {
    }

    /** @param array<string, mixed> $payload encoded as the row's message */
    public function write(string $level, string $area, array $payload): void
    {
        $request = $this->services->get('request_stack')->getMainRequest();
        $user = $this->currentUser();

        $this->services->get('connection')->insert('error_log', [
            'level' => mb_substr($level, 0, 32),
            'area' => mb_substr($area, 0, 80),
            'message' => mb_substr(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 0, self::MAX_MESSAGE),
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'ip_address' => $request?->getClientIp(),
            'user_type' => $user !== null ? 'staff' : ($request !== null ? 'guest' : null),
            'user_id' => $user?->getId(),
            'user_email' => $user?->getEmail(),
            'referrer' => $request !== null ? mb_substr((string) $request->headers->get('referer'), 0, 2048) ?: null : null,
        ]);
    }

    /** The main request's route, else "cli" (commands, workers). */
    public function area(): string
    {
        $request = $this->services->get('request_stack')->getMainRequest();
        if ($request === null) {
            return 'cli ' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2));
        }

        return (string) ($request->attributes->get('_route') ?: $request->getMethod() . ' ' . $request->getPathInfo());
    }

    private function currentUser(): ?AdminUser
    {
        try {
            $user = $this->services->get('security')->getUser();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof AdminUser ? $user : null;
    }
}
