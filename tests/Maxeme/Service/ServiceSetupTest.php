<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\ServiceCategory;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceReminder;
use App\Maxeme\Fee\GovtFeeRule;
use App\Maxeme\Fee\GovtFeeRules;
use App\Maxeme\Repository\GovtFeeRepository;
use App\Maxeme\Repository\ServiceCategoryRepository;
use App\Maxeme\Security\StaffRole;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Config's service set-up: categories (tree, colour), service reminders, labour and government fees. */
final class ServiceSetupTest extends DoctrineIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $admin = (new AdminUser())->setEmail('admin@example.invalid')->setPassword('x')->setRoles([StaffRole::Admin->value]);
        $this->em->persist($admin);
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
    }

    public function testCategoriesFormATreeAndPassTheirColourDown(): void
    {
        $maintenance = (new ServiceCategory())->setName('Maintenance')->setColour('#1F77B4');
        $fluids = (new ServiceCategory())->setName('Fluids')->setParent($maintenance);
        $brakes = (new ServiceCategory())->setName('Brakes')->setColour('#d62728');
        $oil = (new ServiceCategory())->setName('Oil')->setParent($fluids);
        foreach ([$maintenance, $fluids, $brakes, $oil] as $category) {
            $this->em->persist($category);
        }
        $service = (new ServiceItem())->setName('Oil change')->setCategory($oil);
        $this->em->persist($service);
        $this->em->flush();

        self::assertSame('#1f77b4', $oil->getEffectiveColour(), 'from the nearest ancestor with one, lower-cased');
        self::assertSame('#1f77b4', $service->getColour());
        self::assertSame('Maintenance > Fluids > Oil', $oil->getPath());
        self::assertSame(2, $oil->getDepth());
        self::assertSame(['Brakes', 'Maintenance', 'Fluids', 'Oil'], array_map(
            static fn (ServiceCategory $category): string => $category->getName(),
            self::getContainer()->get(ServiceCategoryRepository::class)->findTree(),
        ), 'tree order: each category, then its subcategories, by name');
        self::assertSame([(int) $oil->getId() => 1], self::getContainer()->get(ServiceCategoryRepository::class)->serviceCounts());
    }

    public function testACategoryCannotSitUnderItsOwnSubcategory(): void
    {
        $parent = (new ServiceCategory())->setName('Parent');
        $child = (new ServiceCategory())->setName('Child')->setParent($parent);

        $this->expectException(\InvalidArgumentException::class);
        $parent->setParent($child);
    }

    public function testAServiceReminderIsDueItsDaysAfterTheInvoiceIsCompleted(): void
    {
        $reminder = (new ServiceReminder((new ServiceItem())->setName('Oil change')))->setReminderDays(90);

        self::assertSame('2026-12-30', $reminder->dueAfter(new \DateTimeImmutable('2026-10-01'))->format('Y-m-d'));
        self::assertSame(0, $reminder->getEmailsSent());
        $reminder->recordEmailSent();
        self::assertSame(1, $reminder->getEmailsSent());
    }

    public function testFeeRulesAddOnlyActiveFeesOnceEach(): void
    {
        $this->em->persist((new GovtFee())->setCode('tire-env')->setName('Tire levy')->setPrice('5.00'));
        $this->em->persist((new GovtFee())->setCode('BATT')->setName('Battery levy')->setPrice('3.00')->setActive(false));
        $this->em->flush();

        $rule = new class implements GovtFeeRule {
            public function feeCodesFor(Invoice $invoice): array
            {
                return ['TIRE-ENV', 'tire-env', 'BATT', 'NOPE'];
            }
        };
        $rules = new GovtFeeRules([$rule], self::getContainer()->get(GovtFeeRepository::class));

        self::assertSame(['TIRE-ENV'], array_map(static fn (GovtFee $fee): string => $fee->getCode(), $rules->feesFor(Invoice::forClient(new Client(), null, 5, 7))));
    }

    public function testTheNewRecordsAreInTheActivityLogUnderService(): void
    {
        $service = (new ServiceItem())->setName('Oil change');
        $this->em->persist($service);
        $this->em->persist((new ServiceCategory())->setName('Maintenance'));
        $this->em->persist((new ServiceReminder($service))->setReminderDays(90));
        $this->em->persist((new Labour())->setCode('LAB')->setName('Labour')->setPrice('120.00'));
        $this->em->persist((new GovtFee())->setCode('FEE')->setName('Fee')->setPrice('1.00'));
        $this->em->flush();

        foreach (['ServiceCategory', 'ServiceReminder', 'Labour', 'GovtFee'] as $type) {
            $log = $this->em->getRepository(AuditLog::class)->findOneBy(['entityType' => $type]);
            self::assertNotNull($log, $type . ' changes are logged');
            self::assertSame('Service', $log->getArea());
        }
    }
}
