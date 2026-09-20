<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Tests\DoctrineIntegrationTestCase;
use Twig\Environment;

/**
 * Issue #351, items 2-4: what the shipped emails may no longer say, and proof they still compile.
 *
 *   - the "Best regards, / The Wholesale Catalog Team" sign-off goes from every email;
 *   - "Wholesale Catalog" is one store's name and must never be shipped as everyone's — site_name()
 *     is the mechanism for that (#118);
 *   - the "wc" tile in the email header was fixed initials for that same store's name.
 *
 * Both the shipped templates under templates/emails and the EmailTemplate bodies the migrations seed
 * are covered, because the DB row is what OrderController::sendInvoice() and EmailNotifier actually
 * render — a clean file with a stale seed would still put the sign-off in a customer's inbox.
 *
 * The render half exists because everything above is a text edit to Twig source, and the test
 * environment has strict_variables on: a template left with a dangling tag or a variable the layout
 * no longer supplies fails here rather than at send time inside EmailNotifier's catch-all.
 */
final class EmailTemplateContentTest extends DoctrineIntegrationTestCase
{
    private const EMAIL_TEMPLATE_DIR = __DIR__ . '/../../templates/emails';
    private const MIGRATIONS_DIR = __DIR__ . '/../../migrations';

    public function testNoShippedEmailTemplateStillCarriesTheSignOff(): void
    {
        foreach ($this->emailTemplateSources() as $name => $source) {
            self::assertStringNotContainsString(
                'Best regards',
                $source,
                sprintf('%s still signs off; issue #351 asks for the sign-off to be gone from all emails.', $name),
            );
        }
    }

    public function testNoShippedEmailTemplateNamesTheOriginalStore(): void
    {
        foreach ($this->emailTemplateSources() as $name => $source) {
            self::assertStringNotContainsString(
                'Wholesale Catalog',
                $source,
                sprintf('%s hard-codes a store name. Use site_name(), which the email sandbox allows.', $name),
            );
        }
    }

    public function testTheEmailHeaderNoLongerPrintsTheWcBrandMark(): void
    {
        $layout = (string) file_get_contents(self::EMAIL_TEMPLATE_DIR . '/layout.html.twig');

        self::assertStringNotContainsString('logo-icon', $layout);
        self::assertStringNotContainsString('>wc<', $layout);
        // The store name itself stays — removing the tile must not leave the header blank.
        self::assertStringContainsString('{{ site_name() }}', $layout);
    }

    /**
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    public static function renderableTemplates(): iterable
    {
        $order = [
            'orderNumber' => 'SO5',
            'documentDate' => '2026-08-03',
            'total' => '1250.00',
            'companyIdentity' => ['name' => 'Northfield Restaurant Supply'],
        ];

        yield 'invoice_customer' => ['emails/invoice_customer.html.twig', [
            'order' => $order,
            'order_url' => 'https://shop.example/orders/5',
        ]];
        yield 'invoice_self' => ['emails/invoice_self.html.twig', [
            'order' => $order,
            'admin_url' => 'https://admin.example/admin/invoice/print/5',
            'generatedBy' => 'Dana Admin',
        ]];
        yield 'register' => ['emails/register.html.twig', [
            'user_email' => 'buyer@northfield.example',
            'login_url' => 'https://shop.example/login',
        ]];
        yield 'email_changed' => ['emails/email_changed.html.twig', [
            'old_email' => 'old@northfield.example',
            'new_email' => 'new@northfield.example',
        ]];
        yield 'login' => ['emails/login.html.twig', [
            'user_email' => 'buyer@northfield.example',
            'account_url' => 'https://shop.example/profile',
        ]];
    }

    /** @param array<string, mixed> $context */
    #[\PHPUnit\Framework\Attributes\DataProvider('renderableTemplates')]
    public function testTheEditedTemplatesStillRender(string $view, array $context): void
    {
        $html = self::getContainer()->get(Environment::class)->render($view, $context);

        self::assertStringContainsString('<html', $html);
        self::assertStringNotContainsString('Best regards', $html);
        self::assertStringNotContainsString('>wc<', $html);
    }

    /** The login notice printed an invented address when the context had no user_email. */
    public function testTheLoginNoticeDoesNotFallBackToAnInventedAddress(): void
    {
        $html = self::getContainer()->get(Environment::class)->render('emails/login.html.twig', [
            'account_url' => 'https://shop.example/profile',
        ]);

        self::assertStringNotContainsString('customer@example.test', $html);
    }

    /** @return array<string, string> source name => Twig/PHP source */
    private function emailTemplateSources(): array
    {
        $sources = [];

        foreach ((array) glob(self::EMAIL_TEMPLATE_DIR . '/*.html.twig') as $path) {
            $sources[basename((string) $path)] = (string) file_get_contents((string) $path);
        }

        foreach ((array) glob(self::MIGRATIONS_DIR . '/*.php') as $path) {
            $sources[basename((string) $path)] = (string) file_get_contents((string) $path);
        }

        self::assertNotSame([], $sources, 'The scan is not looking where it thinks it is.');

        return $sources;
    }
}
