<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

/**
 * The shop's permission names, one view/edit pair per area of the role matrix. A role grants them
 * in its own file under config/rbac/role_permissions/ (see RolePermissionFileLoader); `/area/*`
 * grants both halves of an area and `/*` grants everything.
 *
 * Checked by #[RequiresPermission] on every Maxeme action and by is_granted('/area/view') anywhere
 * else (PermissionVoter). Edit never implies view in the checker: an Edit role's file grants
 * `/area/*`, which covers both.
 */
final class Permission
{
    /** Schedule › Appointments: the calendar, booking and moving appointments, check-in. */
    public const APPOINTMENT_VIEW = '/appointment/view';
    public const APPOINTMENT_EDIT = '/appointment/edit';

    /** Schedule › Reminder: the Reminder Todo's list and resolving / delaying / declining one. */
    public const REMINDER_VIEW = '/reminder/view';
    public const REMINDER_EDIT = '/reminder/edit';

    /** Parts & Services › Services: the service catalogue. */
    public const SERVICE_VIEW = '/service/view';
    public const SERVICE_EDIT = '/service/edit';

    /** Parts & Services › Parts Inventory: parts, stock and restocking. */
    public const PARTS_VIEW = '/parts/view';
    public const PARTS_EDIT = '/parts/edit';

    /** Work orders: viewing and printing them (saved or blank), emailing them. */
    public const WORK_ORDER_VIEW = '/work-order/view';
    public const WORK_ORDER_EDIT = '/work-order/edit';

    /** Accounting: invoices (printable, PDF) and the summary report; edit is the invoice builder and emailing. */
    public const ACCOUNTING_VIEW = '/accounting/view';
    public const ACCOUNTING_EDIT = '/accounting/edit';

    /** People › Client List: clients and the client search. */
    public const PEOPLE_VIEW = '/people/view';
    public const PEOPLE_EDIT = '/people/edit';

    /** A client's vehicles. */
    public const CAR_VIEW = '/car/view';
    public const CAR_EDIT = '/car/edit';

    /**
     * Config › Manage Admins: the staff list; create adds an account of any role; edit changes one
     * (de-activate), and StaffAccountVoter keeps Super Admin accounts to Super Admins.
     */
    public const STAFF_VIEW = '/staff/view';
    public const STAFF_CREATE = '/staff/create';
    public const STAFF_EDIT = '/staff/edit';

    /** My Profile: the signed-in account's own details and password. Every role holds it. */
    public const ACCOUNT = '/account/manage';

    /** Config › Roles & Access: every role and the routes it reaches (read-only; the files are the source). */
    public const ROLE_VIEW = '/role/view';

    /** Each area (a permission's first segment) as the role matrix names it, in matrix order. */
    public const AREAS = [
        'appointment' => 'Schedule appointment',
        'reminder' => 'Schedule reminder',
        'service' => 'Service',
        'parts' => 'Parts and service',
        'work-order' => 'Work Order',
        'accounting' => 'Accounting',
        'people' => 'People',
        'car' => 'Car',
        'staff' => 'Manage Admins',
        'role' => 'Roles & Access',
        'account' => 'My Profile',
    ];

    /** '/parts/edit' => 'parts' */
    public static function areaOf(string $permission): string
    {
        return explode('/', trim($permission, '/'))[0];
    }

    /**
     * Every permission name, grouped by area in AREAS order.
     *
     * @return array<string, list<string>>
     */
    public static function byArea(): array
    {
        $grouped = array_fill_keys(array_keys(self::AREAS), []);
        foreach ((new \ReflectionClass(self::class))->getConstants() as $value) {
            if (is_string($value)) {
                $grouped[self::areaOf($value)][] = $value;
            }
        }

        return $grouped;
    }
}
