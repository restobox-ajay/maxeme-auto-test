<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Every log record at error level or above also lands in Logs › Error Log, from anywhere in the app
 * (services, console commands, the messenger worker), then goes on to the real logger unchanged.
 *
 * Skips "Uncaught PHP Exception" during a request: HttpKernel logs those too, and core's
 * ErrorLogSubscriber has already written that row with the full request context. Recording must
 * never break the caller, so a failure to write is swallowed, and a write that itself logs an
 * error is not recorded again.
 */
#[AsDecorator('logger')]
final class ErrorRecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    private const RECORDED_LEVELS = [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];
    private const UNCAUGHT_PREFIX = 'Uncaught PHP Exception';

    private bool $writing = false;

    public function __construct(
        #[AutowireDecorated]
        private readonly LoggerInterface $inner,
        private readonly ErrorLogWriter $errors,
    ) {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);

        if ($this->writing || !in_array($level, self::RECORDED_LEVELS, true)) {
            return;
        }

        $area = $this->errors->area();
        if (str_starts_with((string) $message, self::UNCAUGHT_PREFIX) && !str_starts_with($area, 'cli')) {
            return;
        }

        $this->writing = true;
        try {
            $this->errors->write((string) $level, $area, self::payload((string) $message, $context));
        } catch (\Throwable) {
            // The database may be the thing that failed; the inner logger already has the record.
        } finally {
            $this->writing = false;
        }
    }

    /** @param array<string, mixed> $context */
    private static function payload(string $message, array $context): array
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        $payload = ['message' => strtr($message, $replacements), 'context' => array_map(
            static fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : get_debug_type($value),
            $context,
        )];

        if ($exception instanceof \Throwable) {
            $payload += [
                'exception' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => mb_substr($exception->getTraceAsString(), 0, 15000),
            ];
        }

        return $payload;
    }
}
