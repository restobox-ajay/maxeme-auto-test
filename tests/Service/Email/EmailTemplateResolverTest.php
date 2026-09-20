<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Entity\EmailTemplate;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;
use App\Service\Email\ShippedEmailTemplates;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The resolver is what makes #507's central claim true: a deploy carries new shipped defaults
 * without writing to anyone's row, and an admin's edit survives it because the only writer to that
 * row is the admin.
 *
 * A real EntityManager rather than a mock, because the interesting cases are all about what happens
 * when a row is present, absent, or partial, and a mock would only assert that the resolver calls
 * findOneBy() — which is not the part that can be wrong.
 *
 * Schema here comes from SchemaTool (entity metadata), so the table starts EMPTY — no migration
 * runs and nothing seeds it. That is not a limitation of the test; it is the post-#507 shape of a
 * fresh install, and every assertion below about a template resolving from an empty table is
 * asserting the thing that actually changed.
 */
final class EmailTemplateResolverTest extends DoctrineIntegrationTestCase
{
    private EmailTemplateResolver $resolver;
    private ShippedEmailTemplateCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        // Constructed rather than pulled from the container: its only dependency is the
        // EntityManager, and until the call sites inject it the container inlines it away.
        $this->catalogue = self::getContainer()->get(ShippedEmailTemplateCatalogue::class);
        $this->resolver = new EmailTemplateResolver($this->em, $this->catalogue);
    }

    public function testEveryShippedTemplateResolvesFromAnEmptyTable(): void
    {
        self::assertSame(0, $this->em->getRepository(EmailTemplate::class)->count([]));

        foreach (ShippedEmailTemplates::codes() as $code) {
            $resolved = $this->resolver->resolve($code);

            self::assertNotNull($resolved, sprintf('%s should resolve with no row present', $code));
            self::assertSame($code, $resolved->code);
            self::assertNotSame('', $resolved->body, sprintf('%s resolved to an empty body', $code));
            self::assertNotSame('', $resolved->subject, sprintf('%s resolved to an empty subject', $code));
            self::assertNull($resolved->id, 'nothing should have been written to resolve a shipped template');
            self::assertTrue($resolved->isShipped);
            self::assertFalse($resolved->isCustomized, 'a template with no row cannot be customized');
        }
    }

    public function testResolvingNeverWritesARow(): void
    {
        foreach (ShippedEmailTemplates::codes() as $code) {
            $this->resolver->resolve($code);
        }
        $this->resolver->all();

        self::assertSame(
            0,
            $this->em->getRepository(EmailTemplate::class)->count([]),
            'resolution must never create rows — that is the property that keeps a deploy from clobbering edits',
        );
    }

    public function testAnUnknownCodeResolvesToNull(): void
    {
        self::assertNull($this->resolver->resolve('no_such_template_anywhere'));
    }

    public function testARowOverridesTheShippedBody(): void
    {
        $this->persistRow('invoice_customer', ['body' => 'MY OWN INVOICE BODY']);

        $resolved = $this->resolver->resolve('invoice_customer');

        self::assertSame('MY OWN INVOICE BODY', $resolved->body);
        self::assertTrue($resolved->isCustomized);
        self::assertNotNull($resolved->id);
    }

    /**
     * The point of resolving field by field rather than row-or-shipped: an admin who rewrote the
     * subject still receives shipped corrections to the body. Under the old design the row carried
     * every field, so touching one froze all of them.
     */
    public function testAnOverriddenSubjectStillInheritsTheShippedBody(): void
    {
        $shipped = ShippedEmailTemplates::get('order_received');
        $this->persistRow('order_received', ['subject' => 'We got your order']);

        $resolved = $this->resolver->resolve('order_received');

        self::assertSame('We got your order', $resolved->subject);
        self::assertSame($shipped['body'], $resolved->body, 'the body was not overridden, so it should still be the shipped one');
        self::assertTrue($resolved->isCustomized);
    }

    /** A row that happens to hold exactly what ships is not a customization, and must not read as one. */
    public function testARowIdenticalToShippedIsNotCustomized(): void
    {
        $shipped = ShippedEmailTemplates::get('order_approved');
        $this->persistRow('order_approved', [
            'module' => $shipped['module'],
            'sentTo' => $shipped['sentTo'],
            'subject' => $shipped['subject'],
            'description' => $shipped['description'],
            'status' => $shipped['status'],
            'body' => $shipped['body'],
        ]);

        $resolved = $this->resolver->resolve('order_approved');

        self::assertFalse($resolved->isCustomized);
        self::assertNotNull($resolved->id, 'the row still exists — it is just not a modification');
    }

    public function testAdminCreatedTemplatesResolveAndAreNotMarkedShipped(): void
    {
        $this->persistRow('my_own_template', [
            'module' => 'My Own Template',
            'subject' => 'Hello',
            'body' => 'Body',
        ]);

        $resolved = $this->resolver->resolve('my_own_template');

        self::assertNotNull($resolved);
        self::assertSame('Body', $resolved->body);
        self::assertFalse($resolved->isShipped);
        self::assertFalse($resolved->isCustomized, 'a template with nothing shipped under it is custom, not customized');
    }

    /**
     * Counted against the catalogue rather than a literal 22 (#563). The catalogue is core's own
     * templates plus every Active bundle's, so a hardcoded number here would assert "no bundle may
     * ever ship a template" — which is the assumption #563 exists to remove, and the same one that
     * was already lifted out of ShippedEmailTemplatesMatchTheChainTest.
     *
     * What is actually being tested survives intact: all() lists the WHOLE catalogue, in order,
     * from a table with nothing in it.
     */
    public function testAllListsTheWholeCatalogueOnAnEmptyTable(): void
    {
        $all = $this->resolver->all();

        self::assertCount(\count($this->catalogue->codes()), $all);
        self::assertSame($this->catalogue->codes(), array_map(static fn ($t) => $t->code, $all));
        self::assertGreaterThanOrEqual(22, \count($all), 'core ships 22; the catalogue may only grow');
    }

    /** Admin-authored templates appear after the catalogue rather than being dropped from the list. */
    public function testAllIncludesAdminCreatedTemplatesAfterTheCatalogue(): void
    {
        $this->persistRow('my_own_template', ['module' => 'Mine', 'subject' => 'S', 'body' => 'B']);

        $codes = array_map(static fn ($t) => $t->code, $this->resolver->all());
        $shipped = $this->catalogue->codes();

        self::assertCount(\count($shipped) + 1, $codes);
        self::assertSame('my_own_template', $codes[\count($shipped)], 'the admin-authored one comes last');
        self::assertSame($shipped, \array_slice($codes, 0, \count($shipped)));
    }

    /** A row for a shipped code must not appear twice — once as catalogue and once as a stray row. */
    public function testAllDoesNotDuplicateACustomizedShippedTemplate(): void
    {
        $this->persistRow('login', ['body' => 'changed']);

        $all = $this->resolver->all();
        $codes = array_map(static fn ($t) => $t->code, $all);

        self::assertCount(\count($this->catalogue->codes()), $all, 'a customized shipped template is still one template');
        self::assertCount(1, array_keys($codes, 'login'), 'login should appear exactly once');
    }

    /** @param array<string, string|null> $fields */
    private function persistRow(string $code, array $fields): EmailTemplate
    {
        // Only what the caller names. Everything else stays NULL, which is what "inherit" is —
        // a helper that defaulted the rest would make every row a full override and quietly
        // destroy the distinction these tests exist to check.
        $template = (new EmailTemplate())->setCode($code);
        $template
            ->setModule($fields['module'] ?? null)
            ->setSentTo($fields['sentTo'] ?? null)
            ->setSubject($fields['subject'] ?? null)
            ->setBody($fields['body'] ?? null)
            ->setDescription($fields['description'] ?? null)
            ->setStatus($fields['status'] ?? null);

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }
}
