<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffRole;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Stamps every audit_log row core writes (its automatic entity diffs and every AuditLogger::log())
 * with the actor's shop role at that moment, and files the shop's own records under their area of
 * the role matrix instead of core's catch-all "System", so the Activity Log can filter by both.
 */
#[AsEntityListener(event: Events::prePersist, entity: AuditLog::class)]
final class AuditLogEnricher
{
    /** Entity short name (AuditLog::entityType) => Permission::AREAS key. */
    private const ENTITY_AREAS = [
        'Appointment' => 'appointment',
        'Reminder' => 'reminder',
        'ServiceItem' => 'service',
        'ServiceLine' => 'service',
        'ServiceCategory' => 'service',
        'ServiceReminder' => 'service',
        'ServiceReminderTemplate' => 'service',
        'Labour' => 'service',
        'GovtFee' => 'service',
        'Part' => 'parts',
        'InventoryHistory' => 'parts',
        'Invoice' => 'accounting',
        'InvoicePartLine' => 'accounting',
        'InvoiceServiceLine' => 'accounting',
        'Client' => 'people',
        'ClientAddress' => 'people',
        'ClientNote' => 'people',
        'Vehicle' => 'car',
        'AdminUser' => 'staff',
        'TaxRate' => 'settings',
        'TaxClass' => 'settings',
        'PaymentType' => 'settings',
        'Technician' => 'settings',
    ];

    /** The area core gives an automatic entity diff. */
    private const CORE_AREA = 'System';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function prePersist(AuditLog $log): void
    {
        $user = $this->security->getUser();
        if ($log->getActorRole() === null && $user instanceof AdminUser) {
            $log->setActorRole(StaffRole::of($user)?->label());
        }

        $area = self::areaFor($log->getEntityType());
        if ($area !== null && $log->getArea() === self::CORE_AREA) {
            $log->setArea($area);
        }
    }

    /** The Activity Log area a record type is filed under, or null when it is not one of the shop's. */
    public static function areaFor(string $entityType): ?string
    {
        $key = self::ENTITY_AREAS[$entityType] ?? null;

        return $key !== null ? Permission::AREAS[$key] : null;
    }
}
