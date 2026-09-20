<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\AdminUser;
use App\Entity\Warehouse;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\EmailNotifier;
use App\Service\Inventory\InventoryBucketAuditLogger;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;
use App\Twig\SandboxedTemplateRenderer;
use Twig\Environment;

/**
 * Uses a real EmailNotifier (real Twig rendering, real EntityManager) with a mocked
 * MailerInterface — EmailNotifier is final so it can't be doubled directly, same rationale as
 * SalesDocumentNotifierTest.
 *
 * Only discrepancies are covered here now. The four logChange() tests that used to sit above went
 * with the method in #582: bucket CHANGES are written by App\EventSubscriber\
 * InventoryBucketChangeLogger from the Doctrine changeset, and what replaced these is
 * App\Tests\EventSubscriber\InventoryBucketChangeLoggerTest — which exercises the same
 * behaviours (no row when nothing moved, the actor falling back to the logged-in user, then to
 * 'System') against a real bucket write rather than against a hand-made call.
 */
final class InventoryBucketAuditLoggerTest extends DoctrineIntegrationTestCase
{
    /** @return array{0: InventoryBucketAuditLogger, 1: \ArrayObject<int, Email>} */
    private function loggerCapturingSentEmails(): array
    {
        $sent = new \ArrayObject();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use ($sent): void {
            $sent[] = $email;
        });

        $emailNotifier = new EmailNotifier(
            $this->em,
            $mailer,
            self::getContainer()->get(Environment::class),
            new EmailTemplateRenderer(new EmailTemplateResolver($this->em, self::getContainer()->get(ShippedEmailTemplateCatalogue::class)), self::getContainer()->get(SandboxedTemplateRenderer::class)),
            self::getContainer()->get(AppSettings::class),
        );

        return [new InventoryBucketAuditLogger($this->em, $emailNotifier), $sent];
    }

    private function newProduct(string $sku = 'SKU-1'): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        return $product;
    }

    private function newWarehouse(string $name = 'West'): Warehouse
    {
        $warehouse = (new Warehouse())->setName($name);
        $this->em->persist($warehouse);

        return $warehouse;
    }

    public function testLogDiscrepancyNoOpsWhenCachedAndRecomputedAgree(): void
    {
        [$logger, $sent] = $this->loggerCapturingSentEmails();
        $product = $this->newProduct();
        $warehouse = $this->newWarehouse();
        $this->em->flush();

        $logger->logDiscrepancy($product, $warehouse, InventoryBucketChangeLog::BUCKET_APPROVED, 4, 4, 'inventory_recalc_cron');
        $this->em->flush();

        self::assertSame([], $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll());
        self::assertCount(0, $sent);
    }

    public function testLogDiscrepancyPersistsAndEmailsActiveAdminsWhenMismatched(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));

        [$logger, $sent] = $this->loggerCapturingSentEmails();
        $product = $this->newProduct('SKU-9');
        $warehouse = $this->newWarehouse('East');
        $this->em->flush();

        $logger->logDiscrepancy($product, $warehouse, InventoryBucketChangeLog::BUCKET_APPROVED, 8, 5, 'inventory_recalc_cron');
        $this->em->flush();

        $entries = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame($product->getId(), $entry->getProduct()->getId());
        self::assertSame($warehouse->getId(), $entry->getWarehouse()->getId());
        self::assertSame(InventoryBucketChangeLog::BUCKET_APPROVED, $entry->getBucket());
        self::assertSame('8.0000', $entry->getCachedQuantity());
        self::assertSame('5.0000', $entry->getRecomputedQuantity());
        self::assertSame('inventory_recalc_cron', $entry->getSource());

        self::assertCount(1, $sent);
        $email = $sent[0];
        self::assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Inventory discrepancy found: SKU-9 @ East', $email->getSubject());
        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString('SKU-9', $body);
        self::assertStringContainsString('East', $body);
    }

    /**
     * The fallback the method name promises, actually exercised (#594).
     *
     * This used to persist no AdminUser, so there were no recipients, no email was ever composed,
     * and `assertCount(0, $sent)` was true by construction — the subject line the test is named
     * after was never built. A subject rendered as "Inventory discrepancy found:  @ West", or a
     * throw while composing it against the blank SKU, both passed.
     */
    public function testLogDiscrepancySubjectFallsBackToProductIdWhenSkuBlank(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin-blank-sku@example.com')->setPassword('x'));

        [$logger, $sent] = $this->loggerCapturingSentEmails();
        $product = $this->newProduct('');
        $warehouse = $this->newWarehouse();
        $this->em->flush();
        $productId = $product->getId();

        $logger->logDiscrepancy($product, $warehouse, InventoryBucketChangeLog::BUCKET_CART_HOLD, 1, 2, 'inventory_recalc_cron');
        $this->em->flush();

        $entries = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame($productId, $entries[0]->getProduct()->getId());

        self::assertCount(1, $sent);
        self::assertStringContainsString(
            'Inventory discrepancy found: #' . $productId . ' @ West',
            (string) $sent[0]->getSubject(),
            'with no SKU to name the product by, the subject falls back to the id rather than a blank',
        );
    }
}
