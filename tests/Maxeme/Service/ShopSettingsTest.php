<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Document\DocPrefixSettings;
use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Entity\TaxRate;
use App\Maxeme\Entity\Technician;
use App\Maxeme\Listing\SearchTerm;
use App\Maxeme\Repository\PaymentTypeRepository;
use App\Maxeme\Repository\TaxClassRepository;
use App\Maxeme\Security\StaffRole;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Config › Settings: tax rates and classes, document prefixes, payment types and technicians. */
final class ShopSettingsTest extends DoctrineIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $admin = (new AdminUser())->setEmail('super@example.invalid')->setPassword('x')->setRoles([StaffRole::SuperAdmin->value]);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
    }

    public function testInvoicesChargeTheSettingsTaxRates(): void
    {
        $settings = self::getContainer()->get(InvoiceSettings::class);
        self::assertSame([0, 0], [$settings->gstRate(), $settings->pstRate()], 'no rate set up, none charged');

        $gst = new TaxRate(TaxRate::GST, 'GST', 5);
        $this->em->persist($gst);
        $this->em->persist(new TaxRate(TaxRate::PST, 'PST', 7));
        $this->em->flush();
        self::assertSame([5, 7], [$settings->gstRate(), $settings->pstRate()]);

        $gst->setRate(6);
        $this->em->flush();
        self::assertSame([6, 0], [$settings->gst(5), $settings->pst(0)], 'the builder switches each tax on (any rate) or off (0)');
    }

    public function testATaxClassChargesItsRatesAndIsCountedWhereUsed(): void
    {
        $gst = new TaxRate(TaxRate::GST, 'GST', 5);
        $pst = new TaxRate(TaxRate::PST, 'PST', 7);
        $both = (new TaxClass())->setCode('s')->setName('GST+PST')->setRates([$pst, $gst]);
        $exempt = (new TaxClass())->setCode('E')->setName('Exempt');
        array_map($this->em->persist(...), [$gst, $pst, $both, $exempt]);
        $this->em->persist((new Labour())->setCode('LAB')->setName('Labour')->setPrice('120.00')->setTaxClass($both));
        $this->em->flush();
        $this->em->clear();

        $repository = self::getContainer()->get(TaxClassRepository::class);
        $both = $repository->findOneByCode('S');
        self::assertSame('GST + PST', $both->getRatesLabel(), 'code upper-cased, taxes by code');
        self::assertSame('None', $repository->findOneByCode('e')->getRatesLabel());
        self::assertSame(1, $repository->countUses($both));
        self::assertSame(0, $repository->countUses($repository->findOneByCode('E')));
    }

    public function testDocumentsAreNumberedWithTheirPrefix(): void
    {
        $invoice = Invoice::forClient(new Client(), null, 5, 7);
        $numbers = self::getContainer()->get(DocumentNumbers::class);
        self::assertSame('', $numbers->number($invoice), 'an unsaved invoice has no number');

        (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, 1482);
        self::assertSame('INV-00001482', $numbers->number($invoice), "core's default invoice prefix");
        self::assertSame('WO-00001482', $numbers->number($invoice, DocumentKind::WorkOrder));
        self::assertSame('WO-00001482.pdf', $numbers->filename($invoice, DocumentKind::WorkOrder));
        self::assertSame('Invoice INV-00001482', $numbers->emailSubject($invoice, DocumentKind::Invoice));

        $prefixes = self::getContainer()->get(DocPrefixSettings::class);
        self::assertSame(['repair_order_number_prefix' => 'RO-', 'work_order_number_prefix' => 'WO-', 'invoice_number_prefix' => 'INV-', 'quote_number_prefix' => 'QO-'], $prefixes->values());

        self::assertNotNull($prefixes->save(['invoice_number_prefix' => 'IN V', 'work_order_number_prefix' => 'W-']), 'one bad prefix');
        self::assertSame('WO-', $prefixes->values()['work_order_number_prefix'], '...saves none');

        self::assertNull($prefixes->save(['invoice_number_prefix' => 'mx-', 'work_order_number_prefix' => 'WO-']));
        self::assertSame('MX-00001482', $numbers->number($invoice));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => ActivityRecorder::SETTINGS_CHANGED]);
        self::assertNotNull($log, 'core does not audit app settings, so the change is recorded here');
        self::assertSame('Settings', $log->getArea());
        self::assertSame('Super Admin', $log->getActorRole());
        self::assertStringContainsString('invoice_number_prefix INV- → MX-', $log->getSummary());
        self::assertStringNotContainsString('work_order', $log->getSummary(), 'only what changed');
    }

    public function testTheInvoiceBoxFindsANumberWithItsPrefix(): void
    {
        foreach (['15836', '00015836', '#15836', 'INV-00015836', 'wo-15836', 'RO/15836'] as $typed) {
            self::assertSame(15836, SearchTerm::of($typed)->invoiceNumber(), $typed);
        }
        foreach (['abc', 'INV-', '0', 'INV-12a', '12 34'] as $typed) {
            self::assertNull(SearchTerm::of($typed)->invoiceNumber(), $typed);
        }
    }

    public function testPaymentTypesAreOfferedActiveAndInOrder(): void
    {
        $this->em->persist((new PaymentType())->setName('Debit')->setPosition(2));
        $this->em->persist((new PaymentType())->setName('Cash 1 (NT)')->setPosition(1));
        $this->em->persist((new PaymentType())->setName('Visa')->setPosition(3)->setActive(false));
        $this->em->flush();

        $repository = self::getContainer()->get(PaymentTypeRepository::class);
        $names = static fn (array $types): array => array_map(static fn (PaymentType $type): string => $type->getName(), $types);
        self::assertSame(['Cash 1 (NT)', 'Debit'], $names($repository->findActive()));
        self::assertSame(['Cash 1 (NT)', 'Debit', 'Visa'], $names($repository->findAllOrdered()), 'the report keeps a tab for an inactive one');
        self::assertSame(4, $repository->nextPosition());
    }

    public function testTheSettingsRecordsAreInTheActivityLogUnderSettings(): void
    {
        $this->em->persist(new TaxRate(TaxRate::GST, 'GST', 5));
        $this->em->persist((new TaxClass())->setCode('G')->setName('GST only'));
        $this->em->persist((new PaymentType())->setName('IOT'));
        $this->em->persist((new Technician())->setName('Ken'));
        $this->em->flush();

        foreach (['TaxRate', 'TaxClass', 'PaymentType', 'Technician'] as $type) {
            $log = $this->em->getRepository(AuditLog::class)->findOneBy(['entityType' => $type]);
            self::assertNotNull($log, $type . ' changes are logged');
            self::assertSame('Settings', $log->getArea());
        }
    }
}
