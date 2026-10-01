<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AppSetting;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Part;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\TaxRate;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Service\InvoiceService;
use App\Maxeme\Service\ReminderGenerator;
use App\Maxeme\Service\StockLedger;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Saving an invoice: calculated totals, the appointment's status, and stock taken exactly once. */
final class InvoiceServiceTest extends DoctrineIntegrationTestCase
{
    private InvoiceService $invoices;
    private Appointment $appointment;
    private Part $part;
    private ServiceItem $service;
    private PaymentType $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $timezone = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => AppSettings::TIMEZONE_KEY])
            ?? (new AppSetting())->setSettingKey(AppSettings::TIMEZONE_KEY)->setName('Timezone');
        $timezone->setSettingValue('America/Vancouver');
        $this->em->persist($timezone);

        $client = (new Client())->setFirstName('Dan')->setLastName('Kovac');
        $vehicle = (new Vehicle($client))->setYear(2016)->setManufacturer('Honda')->setModel('Civic')->setMileage('100000');
        $this->appointment = new Appointment($vehicle, new \DateTimeImmutable('2026-10-02 16:00:00'), new \DateTimeImmutable('2026-10-02 18:00:00'));
        $this->part = (new Part())->setName('Oil filter')->setUnitPrice('4.00')->setSalePrice('9.50');
        $this->service = (new ServiceItem())->setName('Oil change')->setPrice('50.00');
        $this->credit = (new PaymentType())->setName('Credit Card');
        array_map($this->em->persist(...), [$client, $vehicle, $this->appointment, $this->part, $this->service, $this->credit,
            new TaxRate(TaxRate::GST, 'GST', 5), new TaxRate(TaxRate::PST, 'PST', 7)]);
        $this->em->flush();
        self::getContainer()->get(StockLedger::class)->setQuantity($this->part, 10, 'Opening stock');
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        $this->invoices = self::getContainer()->get(InvoiceService::class);
    }

    public function testTotalsAreCalculatedFromTheLines(): void
    {
        $invoice = $this->save(InvoiceSaveIntent::Save);

        // 50.00 service + 1 × 9.50 part − 10.00 discount = 49.50; GST 5% 2.48, PST 7% 3.47
        self::assertSame(['49.50', '5.95', '55.45', '-10.00'], [$invoice->getSubtotal(), $invoice->getSalesTax(), $invoice->getTotalPrice(), $invoice->getDiscountAmount()]);
        self::assertSame('12.00', $invoice->getExpenseAmount(), 'material cost: 3 parts × 4.00, the service\'s included');
        self::assertSame('37.50', $invoice->getNetAmount(), 'total − tax − cost; the discount is not counted twice');
        self::assertSame(AppointmentStatus::InProgress, $this->appointment->getStatus());
        self::assertSame('150000', $this->appointment->getVehicle()->getMileage(), 'the mileage typed on the invoice is kept on the vehicle');
        self::assertSame($this->credit, $invoice->getPaymentType());
        self::assertSame([5, 7], [$invoice->getGstRate(), $invoice->getPstRate()], 'the rates of Settings > Tax Rates');
    }

    public function testStockIsTakenOnceWhilePaidAndGivenBackWhenUnpaid(): void
    {
        $invoice = $this->save(InvoiceSaveIntent::Complete);
        self::assertSame(7, $this->part->getQuantity(), '2 used by the service + 1 billed');
        self::assertSame(AppointmentStatus::Complete, $this->appointment->getStatus());
        self::assertNotNull($invoice->getLastModified(), 'paying dates the invoice');

        $this->save(InvoiceSaveIntent::Complete, $invoice);
        self::assertSame(7, $this->part->getQuantity(), 'saving a paid invoice again takes nothing more');

        $this->save(InvoiceSaveIntent::Save, $invoice);
        self::assertSame(10, $this->part->getQuantity(), 'unpaid again: the parts are back');
    }

    public function testNoReminderWhileTheVehicleIsBookedAgain(): void
    {
        $generator = self::getContainer()->get(ReminderGenerator::class);
        $sixMonthsLater = new \DateTimeImmutable('2027-04-02 12:00:00', new \DateTimeZone('America/Vancouver'));

        self::assertSame(1, $generator->generate($sixMonthsLater), 'six months after the visit');
        self::assertSame(0, $generator->generate($sixMonthsLater), 'and only once');
    }

    private function save(InvoiceSaveIntent $intent, ?Invoice $invoice = null): Invoice
    {
        $invoice ??= $this->invoices->forAppointment($this->appointment);
        $this->invoices->save($invoice, InvoiceData::fromRequest(new Request(request: [
            'vehicle_mileage' => '150000',
            'discount_amount' => '10',
            'gst' => '5',
            'pst' => '7',
            'payment_type' => (string) $this->credit->getId(),
            'items' => [
                ['type' => 'Services', 'id' => $this->service->getId(), 'name' => 'Oil change', 'quantity' => '1', 'price' => '50.00',
                    'parts' => [['id' => $this->part->getId(), 'name' => 'Oil filter', 'quantity' => '2']]],
                ['type' => 'Parts', 'id' => $this->part->getId(), 'name' => 'Oil filter', 'quantity' => '1', 'price' => '9.50'],
            ],
        ])), $intent);

        return $invoice;
    }
}
