<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use Symfony\Component\HttpFoundation\Request;

/** Config › Logs › Activity Log's filter bar: `role`, `user`, `area`, `action`, `from`, `to` (shop dates). */
final class ActivityLogFilter
{
    /** The role filter's value for rows nobody signed in wrote (imports, commands, sign-in failures). */
    public const NO_ROLE = '__none';

    private function __construct(
        public readonly string $role,
        public readonly ?int $userId,
        public readonly string $area,
        public readonly string $action,
        public readonly ?\DateTimeImmutable $from,
        public readonly ?\DateTimeImmutable $to,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $userId = $request->query->getInt('user');

        return new self(
            role: trim((string) $request->query->get('role', '')),
            userId: $userId > 0 ? $userId : null,
            area: trim((string) $request->query->get('area', '')),
            action: trim((string) $request->query->get('action', '')),
            from: self::date((string) $request->query->get('from', '')),
            to: self::date((string) $request->query->get('to', '')),
        );
    }

    /** @return array<string, string> the filters in effect, for links that keep them */
    public function toQuery(): array
    {
        return array_filter([
            'role' => $this->role,
            'user' => $this->userId !== null ? (string) $this->userId : '',
            'area' => $this->area,
            'action' => $this->action,
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to?->format('Y-m-d') ?? '',
        ], static fn (string $value): bool => $value !== '');
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false ? $date : null;
    }
}
