<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoiceServiceLine;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The edit page of an invoice issued from a repair order: its status (Issued / Paid / Cancelled),
 * date, payment and recommendations, the names, quantities and prices of its lines (a line left
 * out is removed, with the charge-through lines under it), and its custom fees and discounts.
 */
final class IssuedInvoiceForm
{
    #[Assert\NotBlank(message: 'Choose the status.')]
    #[Assert\Choice(callback: [self::class, 'statuses'], message: 'Choose the status from the list.')]
    public ?string $status = null;

    /** The invoice date, as a datetime-local input posts it (shop time). */
    #[Assert\Regex('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', message: 'Enter the invoice date as a date and time.')]
    public ?string $date = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose a payment type from the list.')]
    public ?string $paymentTypeId = null;

    #[Formatted(InputRule::Money)]
    public ?string $paymentAmount = null;

    #[Assert\Length(max: 5000)]
    public ?string $recommendations = null;

    /** @var list<array{id: string, name: ?string, quantity: ?string, price: ?string}> the lines kept, in order */
    public array $lines = [];

    /** @var list<DocumentChargeData> */
    public array $charges = [];

    public static function fromRequest(Request $request): self
    {
        $posted = $request->request->all();
        $form = new self();
        foreach (['status' => 'status', 'date' => 'date', 'payment_type_id' => 'paymentTypeId', 'payment_amount' => 'paymentAmount', 'recommendations' => 'recommendations'] as $field => $property) {
            $value = is_scalar($posted[$field] ?? null) ? trim((string) $posted[$field]) : '';
            $form->{$property} = $value !== '' ? $value : null;
        }
        foreach (is_array($posted['lines'] ?? null) ? $posted['lines'] : [] as $row) {
            if (!is_array($row) || !is_scalar($row['id'] ?? null)) {
                continue;
            }
            $value = static fn (string $key): ?string => is_scalar($row[$key] ?? null) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;
            $form->lines[] = ['id' => (string) $row['id'], 'name' => $value('name'), 'quantity' => $value('quantity'), 'price' => $value('price')];
        }
        $form->charges = DocumentChargeData::listFromRequest($posted['charges'] ?? []);

        return $form;
    }

    public static function fromEntity(Invoice $invoice, \DateTimeZone $timezone): self
    {
        $form = new self();
        $form->status = $invoice->getStatus()->value;
        $form->date = $invoice->getDocumentDate()->setTimezone($timezone)->format('Y-m-d\TH:i');
        $form->paymentTypeId = $invoice->getPaymentType()?->getId() !== null ? (string) $invoice->getPaymentType()->getId() : null;
        $form->paymentAmount = $invoice->getPaymentAmount();
        $form->recommendations = $invoice->getRecommendations();
        $form->lines = array_map(static fn (InvoiceServiceLine $line): array => [
            'id' => (string) $line->getId(),
            'name' => $line->getName(),
            'quantity' => (string) (float) $line->getQuantity(),
            'price' => $line->getSalePrice(),
        ], $invoice->getServiceLines()->toArray());
        $form->charges = array_map(DocumentChargeData::fromEntity(...), $invoice->getCharges());

        return $form;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases());
    }
}
