<?php

declare(strict_types=1);

namespace App\Maxeme\Twig;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Audit\ActivityLog;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Dto\ClientAddressData;
use App\Maxeme\Dto\ClientData;
use App\Maxeme\Dto\FormData;
use App\Maxeme\Dto\PartData;
use App\Maxeme\Dto\ServiceItemData;
use App\Maxeme\Dto\ServiceReminderData;
use App\Maxeme\Dto\LabourData;
use App\Maxeme\Dto\GovtFeeData;
use App\Maxeme\Dto\VehicleData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Part;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Entity\Technician;
use App\Maxeme\Dto\PaymentTypeData;
use App\Maxeme\Dto\TaxClassData;
use App\Maxeme\Dto\TechnicianData;
use App\Maxeme\Security\StaffRole;
use App\Menu\Admin\AdminMenuIconSet;
use Twig\Attribute\AsTwigFunction;

final class MaxemeExtension
{
    /** Entity => the FormData its edit form uses. */
    private const FORMS = [
        Client::class => ClientData::class,
        ClientAddress::class => ClientAddressData::class,
        Vehicle::class => VehicleData::class,
        Part::class => PartData::class,
        ServiceItem::class => ServiceItemData::class,
        ServiceReminder::class => ServiceReminderData::class,
        Labour::class => LabourData::class,
        GovtFee::class => GovtFeeData::class,
        TaxClass::class => TaxClassData::class,
        PaymentType::class => PaymentTypeData::class,
        Technician::class => TechnicianData::class,
    ];

    public function __construct(
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /** {{ doc_number(invoice) }} "INV-00001482"; {{ doc_number(invoice, kind) }} for the work order's "WO-00001482". */
    #[AsTwigFunction('doc_number')]
    public function docNumber(Invoice $invoice, DocumentKind $kind = DocumentKind::Invoice): string
    {
        return $this->numbers->number($invoice, $kind);
    }

    /** {{ staff_role(user).label }} */
    #[AsTwigFunction('staff_role')]
    public function staffRole(AdminUser $user): StaffRole
    {
        return StaffRole::of($user);
    }

    /**
     * {% set changes = audit_changes(entry) %}: an Activity Log row's changed fields (the first few).
     *
     * @return array{fields: array<string, array{0: mixed, 1: mixed}>, more: int}
     */
    #[AsTwigFunction('audit_changes')]
    public function auditChanges(AuditLog $entry): array
    {
        return ActivityLog::changes($entry);
    }

    /** {{ mx_icon('search') }}: an icon from the shared AdminMenuIconSet. */
    #[AsTwigFunction('mx_icon', isSafe: ['html'])]
    public function icon(string $name): string
    {
        return AdminMenuIconSet::svg($name) ?? '';
    }

    /**
     * data-form-values="{{ form_values(client)|json_encode }}": an edit form's pre-fill.
     *
     * @return array<string, ?string>
     */
    #[AsTwigFunction('form_values')]
    public function formValues(object $record): array
    {
        // instanceof, not ::class: a lazy-loaded record is a Doctrine proxy subclass.
        foreach (self::FORMS as $entity => $form) {
            if ($record instanceof $entity) {
                /** @var class-string<FormData> $form */
                return $form::fromEntity($record)->toFormValues();
            }
        }

        throw new \InvalidArgumentException(sprintf('No form is registered for %s.', $record::class));
    }
}
