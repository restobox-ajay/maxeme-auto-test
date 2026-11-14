<?php

declare(strict_types=1);

namespace App\Maxeme\Schedule;

use Symfony\Component\HttpFoundation\Request;

/** The calendar's view, under the legacy `view` names (`?view=agendaDay`), and FullCalendar's. */
enum CalendarView: string
{
    case Month = 'month';
    case Week = 'agendaWeek';
    case Day = 'agendaDay';
    /** A table of appointments, paged and filtered (mockup deba1ef); not a FullCalendar view. */
    case List = 'list';

    public function fullCalendarView(): string
    {
        return match ($this) {
            self::Month => 'dayGridMonth',
            self::Week => 'timeGridWeek',
            self::Day, self::List => 'timeGridDay',
        };
    }

    public static function fromRequest(Request $request): self
    {
        return self::tryFrom((string) $request->query->get('view', '')) ?? self::Day;
    }
}
