<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AppSetting;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\TaxRate;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Service\InvoiceService;
use App\Maxeme\Service\ReminderGenerator;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Saving an invoice: calculated totals, the appointment's status, and parts as catalogue products. */
final class InvoiceServiceTest extends DoctrineIntegrationTestCase
{
    private InvoiceService $invoices;
    private Appointment $appointment;
    private ProductCore $part;
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
        $this->part = (new ProductCore())->setSku('OF-1')->setName('Oil filter')->setCostPrice('4.000000')->setDefaultPrice('9.500000');
        $this->service = (new ServiceItem())->setName('Oil change')->setPrice('50.00');
        $this->credit = (new PaymentType())->setName('Credit Card');
        array_map($this->em->persist(...), [$client, $vehicle, $this->appointment, $this->part, $this->service, $this->credit,
            new TaxRate(TaxRate::GST, 'GST', 5), new TaxRate(TaxRate::PST, 'PST', 7)]);
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

    public function testPartLinesAreCatalogueProductsAndMoveNoStockYet(): void
    {
        $invoice = $this->save(InvoiceSaveIntent::Complete);
        self::assertSame(AppointmentStatus::Complete, $this->appointment->getStatus());
        self::assertNotNull($invoice->getLastModified(), 'paying dates the invoice');

        foreach ($invoice->getPartLines() as $line) {
            self::assertSame($this->part, $line->getProduct());
            self::assertSame('4.00', $line->getUnitPrice(), "the product's cost, to the cent");
        }
        self::assertSame(0, $this->em->getRepository(ProductInventory::class)->count([]), 'stock is held by repair order status, not built yet');
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
