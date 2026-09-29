<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Enum\PaymentMethod;
use App\Maxeme\Validation\Money;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The invoice builder (legacy invoice_form.html.twig): the client and vehicle snapshot, the item
 * table, discount, taxes, payment and recommendations. The totals are not posted: they are
 * calculated (InvoiceCalculator).
 */
final class InvoiceData
{
    /** The legacy date format of "Invoice created on:", shop time. */
    public const DATE_FORMAT = 'm/d/Y h:i a';

    /** header field => Invoice property, in form order */
    public const SNAPSHOT_FIELDS = [
        'client_first_name' => 'clientFirstName',
        'client_last_name' => 'clientLastName',
        'client_preferred_name' => 'clientPreferredName',
        'client_home_number' => 'clientHomeNumber',
        'client_address' => 'clientAddress',
        'client_note' => 'clientNote',
        'vehicle_year' => 'vehicleYear',
        'vehicle_manufacturer' => 'vehicleManufacturer',
        'vehicle_model' => 'vehicleModel',
        'vehicle_license' => 'vehicleLicense',
        'vehicle_vin' => 'vehicleVin',
        'vehicle_mileage' => 'vehicleMileage',
        'note' => 'note',
        'recommendations' => 'recommendations',
    ];

    /** @var array<string, ?string> Invoice property => value */
    #[Assert\All([new Assert\Length(max: 65535)])]
    public array $snapshot = [];

    #[Assert\DateTime(format: self::DATE_FORMAT, message: 'Enter the invoice date as mm/dd/yyyy hh:mm am.')]
    public ?string $invoiceDate = null;

    /** @var list<InvoiceItemData> */
    #[Assert\Valid]
    public array $items = [];

    /** Typed positive or negative; always applied as a discount (legacy JS forced it negative). */
    #[Assert\Regex(pattern: '/^-?\d{1,8}(\.\d{1,2})?$/', message: 'Enter the discount in dollars, e.g. 10.00.')]
    public ?string $discount = null;

    public int $gstRate = 0;

    public int $pstRate = 0;

    #[Assert\NotNull(message: 'Choose a payment method.')]
    public ?PaymentMethod $paymentMethod = PaymentMethod::Cash;

    #[Money]
    public ?string $paymentAmount = null;

    /** The Paid checkbox (it only decides the status when saving for the work order). */
    public bool $paid = false;

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        $post = $request->request;

        foreach (self::SNAPSHOT_FIELDS as $field => $property) {
            $value = trim((string) $post->get($field, ''));
            $data->snapshot[$property] = $value !== '' ? $value : null;
        }

        $date = trim((string) $post->get('invoice_date', ''));
        $data->invoiceDate = $date !== '' ? strtolower($date) : null;
        $discount = trim((string) $post->get('discount_amount', ''));
        $data->discount = $discount !== '' ? $discount : null;
        $data->gstRate = $post->getInt('gst');
        $data->pstRate = $post->getInt('pst');
        $data->paymentMethod = PaymentMethod::tryFrom((string) $post->get('payment_method', ''));
        $amount = trim((string) $post->get('payment_amount', ''));
        $data->paymentAmount = $amount !== '' ? $amount : null;
        $data->paid = $post->getBoolean('paid');

        foreach ($post->all('items') as $row) {
            $item = InvoiceItemData::fromArray(is_array($row) ? $row : []);
            if (!$item->isEmpty()) {
                $data->items[] = $item;
            }
        }

        return $data;
    }

    /** The discount as stored: never positive. */
    public function negativeDiscount(): string
    {
        return number_format(-abs((float) ($this->discount ?? 0)), 2, '.', '');
    }
}
