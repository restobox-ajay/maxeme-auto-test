<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

use Symfony\Component\HttpFoundation\Request;

/** The Client Profile tabs, in order. */
enum ClientProfileTab: string
{
    case Info = 'info';
    case Addresses = 'addresses';
    case Vehicles = 'vehicles';
    case Appointments = 'appointments';
    case WorkOrders = 'work_orders';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Client Profile',
            self::Addresses => 'Address Book',
            self::Vehicles => 'Vehicles',
            self::Appointments => 'Appointments',
            self::WorkOrders => 'Work Orders',
        };
    }

    /** `?tab=`, or the legacy `?view=info|vehicle|appointments`; Client Profile when neither names a tab. */
    public static function fromRequest(Request $request): self
    {
        $tab = (string) $request->query->get('tab', $request->query->get('view', ''));

        return self::tryFrom($tab === 'vehicle' ? self::Vehicles->value : $tab) ?? self::Info;
    }
}
