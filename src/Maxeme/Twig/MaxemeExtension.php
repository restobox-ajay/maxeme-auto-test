<?php

declare(strict_types=1);

namespace App\Maxeme\Twig;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Accounting\TaxMap;
use App\Maxeme\Audit\ActivityLog;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Dto\ClientAddressData;
use App\Maxeme\Dto\ClientData;
use App\Maxeme\Dto\FormData;
use App\Maxeme\Dto\ServiceItemData;
use App\Maxeme\Dto\ServiceReminderData;
use App\Maxeme\Dto\LabourData;
use App\Maxeme\Dto\GovtFeeData;
use App\Maxeme\Dto\VehicleData;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Dto\PaymentTypeData;
use App\Maxeme\Dto\TaxClassData;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Validation\ContactFormat;
use App\Maxeme\Validation\InputRule;
use App\Menu\Admin\AdminMenuIconSet;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class MaxemeExtension
{
    /** Entity => the FormData its edit form uses. */
    private const FORMS = [
        Client::class => ClientData::class,
        ClientAddress::class => ClientAddressData::class,
        Vehicle::class => VehicleData::class,
        ServiceItem::class => ServiceItemData::class,
        ServiceReminder::class => ServiceReminderData::class,
        Labour::class => LabourData::class,
        GovtFee::class => GovtFeeData::class,
        TaxClass::class => TaxClassData::class,
        PaymentType::class => PaymentTypeData::class,
    ];

    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly TaxMap $taxMap,
        private readonly \App\Maxeme\Repository\TaxClassRepository $taxClasses,
        private readonly \App\Maxeme\Service\InvoicePayments $payments,
    ) {
    }

    /**
     * {% set due = invoice_balance(invoice) %}: what its payments add up to and what is still due, as
     * the Payments page counts them, and what it was paid with (each method and its amount), for the
     * older invoice print.
     *
     * @return array{paidSoFar: string, balance: string, methods: list<array{type: string, amount: string}>}
     */
    #[AsTwigFunction('invoice_balance')]
    public function invoiceBalance(Invoice $invoice): array
    {
        return [
            'paidSoFar' => \App\Maxeme\Accounting\Money::fromCents($this->payments->paidCents($invoice)),
            'balance' => \App\Maxeme\Accounting\Money::fromCents($this->payments->balanceCents($invoice)),
            'methods' => array_map(static fn (array $method): array => ['type' => (string) $method['type']?->getName(), 'amount' => \App\Maxeme\Accounting\Money::fromCents($method['cents'])], $this->payments->methodsByInvoice([$invoice])[$invoice->getId()] ?? []),
        ];
    }

    /**
     * {{ tax_class_labels() }}: code => its label ("S - GST+PST", TaxClass::getLabel()) for every tax
     * class (Settings › Tax Classes), in their order; the product form's Sales Tax Code choices (mockup).
     *
     * @return array<string, string>
     */
    #[AsTwigFunction('tax_class_labels')]
    public function taxClassLabels(): array
    {
        $labels = [];
        foreach ($this->taxClasses->findAllOrdered() as $class) {
            $labels[$class->getCode()] = $class->getLabel();
        }

        return $labels;
    }

    /** {{ tax_map()|json_encode }}: the catalogue items not taxed both GST and PST, for live totals (TaxMap). */
    #[AsTwigFunction('tax_map')]
    public function taxMap(): array
    {
        return $this->taxMap->get();
    }

    /** {{ doc_number(invoice) }} "INV-001482"; {{ doc_number(invoice, kind) }} for the work order's "WO-001482". */
    #[AsTwigFunction('doc_number')]
    public function docNumber(Invoice $invoice, DocumentKind $kind = DocumentKind::Invoice): string
    {
        return $this->numbers->number($invoice, $kind);
    }

    /** {{ staff_role(user).label }} */
    /** {{ ro_number(repairOrder) }}: "RO-000042"; with a DocumentKind, its quote's or work order's number. */
    #[AsTwigFunction('ro_number')]
    public function repairOrderNumber(RepairOrder $repairOrder, ?DocumentKind $kind = null): string
    {
        return $this->numbers->repairOrderNumber($repairOrder, $kind);
    }

    /** {{ number|phone }}: "604-285-8200" (ContactFormat); text that is not a phone as it is. */
    #[AsTwigFilter('phone')]
    public function phone(?string $value): string
    {
        return ContactFormat::phone($value);
    }

    /** {% for role in staff_roles(user) %}: every shop role the account holds, highest first. */
    #[AsTwigFunction('staff_roles')]
    public function staffRoles(AdminUser $user): array
    {
        return StaffRole::allOf($user);
    }

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

    /**
     * {{ path('maxeme_activity_log_index', audit_record(client)) }}: the Activity Log filter for one
     * record's history — its short class name, which is what audit_log stores, and its id.
     *
     * @return array{record: string, id: int|null}
     */
    #[AsTwigFunction('audit_record')]
    public function auditRecord(object $record): array
    {
        $id = method_exists($record, 'getId') ? $record->getId() : null;

        return ['record' => (new \ReflectionClass($record))->getShortName(), 'id' => is_int($id) ? $id : null];
    }

    /**
     * <input type="text" name="phone_1" {{ input_rule('phone') }}>: the browser half of an InputRule
     * (pattern, the message as its tooltip, and the keyboard to show), the server half being
     * #[Formatted(InputRule::Phone)] on the field.
     */
    #[AsTwigFunction('input_rule', isSafe: ['html'])]
    public function inputRule(string $name): string
    {
        $rule = InputRule::from($name);
        $escape = static fn (string $value): string => htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $attributes = sprintf('pattern="%s" title="%s"', $escape($rule->pattern()), $escape($rule->message()));

        return $rule->inputMode() !== null ? $attributes . sprintf(' inputmode="%s"', $rule->inputMode()) : $attributes;
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
