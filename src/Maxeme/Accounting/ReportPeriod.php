<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use Symfony\Component\HttpFoundation\Request;

/**
 * A report's From and To (shop dates, whole days, both included), as its filter posts them
 * (?from=2026-10-01&to=2026-10-03). The preset dropdown only fills those two in the browser; the
 * report reads nothing else. Today when a date is blank or not a date (a page may default From
 * further back).
 */
final class ReportPeriod
{
    private const FORMAT = 'Y-m-d';

    private function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
    }

    /** @param string $defaultFrom when From is blank, e.g. '-29 days' (Today otherwise) */
    public static function fromRequest(Request $request, \DateTimeZone $timezone, string $defaultFrom = 'today'): self
    {
        $today = new \DateTimeImmutable('today', $timezone);
        $from = self::date((string) $request->query->get('from', ''), $timezone) ?? $today->modify($defaultFrom);
        $to = self::date((string) $request->query->get('to', ''), $timezone) ?? $today;

        return $to < $from ? new self($to, $from) : new self($from, $to);
    }

    /** The first instant of From, in UTC. */
    public function startUtc(): \DateTimeImmutable
    {
        return $this->from->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The last instant of To, in UTC. */
    public function endUtc(): \DateTimeImmutable
    {
        return $this->to->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array{from: string, to: string} the query that shows this period again (and exports it) */
    public function toQuery(): array
    {
        return ['from' => $this->from->format(self::FORMAT), 'to' => $this->to->format(self::FORMAT)];
    }

    /** "2026-10-01_2026-10-03", for an export's filename. */
    public function slug(): string
    {
        return $this->from->format(self::FORMAT) . '_' . $this->to->format(self::FORMAT);
    }

    private static function date(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, trim($value), $timezone);

        return $date !== false && $date->format(self::FORMAT) === trim($value) ? $date : null;
    }
}
