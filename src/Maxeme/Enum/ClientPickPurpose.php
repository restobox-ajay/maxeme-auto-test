<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

use App\Maxeme\Security\Permission;

/**
 * What a sidebar "+" is creating when it needs a client first (Choose a client): the screen the
 * chosen client opens, and the permission that screen needs.
 */
enum ClientPickPurpose: string
{
    case Appointment = 'appointment';
    case RepairOrder = 'repair_order';
    case Reminder = 'reminder';

    public function title(): string
    {
        return match ($this) {
            self::Appointment => 'New appointment',
            self::RepairOrder => 'New repair order',
            self::Reminder => 'New reminder',
        };
    }

    public function eyebrow(): string
    {
        return 'Schedule';
    }

    /** The route the chosen client opens, taking the client's `id`. */
    public function route(): string
    {
        return match ($this) {
            self::Appointment => 'maxeme_appointment_new',
            self::RepairOrder => 'maxeme_repair_order_new',
            self::Reminder => 'maxeme_reminder_new',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::Appointment => Permission::APPOINTMENT_EDIT,
            self::RepairOrder => Permission::WORK_ORDER_EDIT,
            self::Reminder => Permission::REMINDER_EDIT,
        };
    }
}
