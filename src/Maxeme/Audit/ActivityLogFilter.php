<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use Symfony\Component\HttpFoundation\Request;

/**
 * Config › Logs › Activity Log's filter bar: `role`, `user`, `area`, `action`, `record`, `from`, `to`
 * (shop dates), plus `id` with `record` for one record's history (every Logs button links there).
 */
final class ActivityLogFilter
{
    /** The role filter's value for rows nobody signed in wrote (imports, commands, sign-in failures). */
    public const NO_ROLE = '__none';

    private function __construct(
        public readonly string $role,
        public readonly ?int $userId,
        public readonly string $area,
        public readonly string $action,
        public readonly string $record,
        public readonly ?int $recordId,
        public readonly ?\DateTimeImmutable $from,
        public readonly ?\DateTimeImmutable $to,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $userId = $request->query->getInt('user');
        $record = trim((string) $request->query->get('record', ''));
        $recordId = $request->query->getInt('id');

        return new self(
            role: trim((string) $request->query->get('role', '')),
            userId: $userId > 0 ? $userId : null,
            area: trim((string) $request->query->get('area', '')),
            action: trim((string) $request->query->get('action', '')),
            record: $record,
            recordId: $record !== '' && $recordId > 0 ? $recordId : null,
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
            'record' => $this->record,
            'id' => $this->recordId !== null ? (string) $this->recordId : '',
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to?->format('Y-m-d') ?? '',
        ], static fn (string $value): bool => $value !== '');
    }

    /** One record's history (a Logs button), not a filtered list. */
    public function isRecordHistory(): bool
    {
        return $this->recordId !== null;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false ? $date : null;
    }
}
