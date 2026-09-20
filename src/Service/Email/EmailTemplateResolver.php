<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\EmailTemplate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single place that answers "what is template X" (#507).
 *
 * Resolution is the shipped definition from {@see ShippedEmailTemplates} with any email_template
 * row laid over it, field by field. A row exists only where an admin has changed something or
 * authored a template of their own; nothing else needs one, and nothing here ever writes one.
 *
 * The direction matters and is the whole point of the design. Shipped content is never stored, so a
 * deploy carries new defaults without touching a single row, and an admin's edit is never written
 * over because the only writer to that row is the admin. The alternative — a seeder that upserts
 * shipped rows on deploy — cannot have both of those at once, which is why the content was stuck in
 * the migration chain in the first place: a migration runs once, so it cannot re-clobber.
 *
 * Callers previously did their own findOneBy(['code' => …]) — fourteen of them, each with its own
 * answer for a missing row, ranging from a silent no-op to a flashed error. Going through here
 * means a code that ships can no longer be "missing", so most of those branches become unreachable
 * rather than subtly different from one another.
 *
 * Since #563 the shipped half comes from ShippedEmailTemplateCatalogue rather than the static
 * ShippedEmailTemplates directly, so a bundle's templates resolve, list, preview and revert on
 * exactly the same terms as core's. Nothing else about the design changed: shipped content is
 * still never stored, and the only writer to a row is still the admin.
 */
final class EmailTemplateResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShippedEmailTemplateCatalogue $catalogue,
    ) {
    }

    /**
     * The template to use for a code, or null when nothing ships under it AND no row carries it —
     * which now means a genuine typo rather than an un-seeded database.
     */
    public function resolve(string $code): ?ResolvedEmailTemplate
    {
        $shipped = $this->catalogue->get($code);
        $row = $this->entityManager->getRepository(EmailTemplate::class)->findOneBy(['code' => $code]);

        if ($shipped === null && !$row instanceof EmailTemplate) {
            return null;
        }

        return $this->combine($code, $shipped, $row);
    }

    /**
     * Every template the panel should list: the shipped catalogue, plus any rows for codes that do
     * not ship (templates an admin created).
     *
     * The catalogue leads rather than the table, because on a database with no rows at all the
     * table is the empty set and listing it would show an empty screen for 22 templates that all
     * exist and all send.
     *
     * @return list<ResolvedEmailTemplate>
     */
    public function all(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(EmailTemplate::class)->findBy([], ['id' => 'ASC']) as $row) {
            $rows[$row->getCode()] = $row;
        }

        $resolved = [];
        foreach ($this->catalogue->codes() as $code) {
            $resolved[] = $this->combine($code, $this->catalogue->get($code), $rows[$code] ?? null);
            unset($rows[$code]);
        }

        foreach ($rows as $code => $row) {
            $resolved[] = $this->combine($code, null, $row);
        }

        return $resolved;
    }

    /**
     * @param array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}|null $shipped
     */
    private function combine(string $code, ?array $shipped, ?EmailTemplate $row): ResolvedEmailTemplate
    {
        // Every field independently: null on the row means "inherit", so an admin who changed only
        // the subject keeps picking up shipped fixes to the body. A row that predates #507 has all
        // of them set, which resolves to exactly the behaviour it had before — the row wins outright
        // — so wiring this in changes nothing until something starts writing partial rows.
        $pick = static fn (string $field, ?string $rowValue): ?string => $rowValue ?? $shipped[$field] ?? null;

        $module = $pick('module', $row?->getModule());
        $sentTo = $pick('sentTo', $row?->getSentTo());
        $subject = $pick('subject', $row?->getSubject());
        $status = $pick('status', $row?->getStatus());
        $body = $pick('body', $row?->getBody());
        $description = $row?->getDescription() ?? $shipped['description'] ?? null;

        return new ResolvedEmailTemplate(
            code: $code,
            module: $module ?? $code,
            sentTo: $sentTo ?? 'Customer',
            subject: $subject ?? '',
            description: $description,
            status: $status ?? 'Active',
            body: $body ?? '',
            id: $row?->getId(),
            isShipped: $shipped !== null,
            isCustomized: $shipped !== null && $row instanceof EmailTemplate && $this->differs($shipped, $row),
        );
    }

    /**
     * @param array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string} $shipped
     */
    private function differs(array $shipped, EmailTemplate $row): bool
    {
        foreach (['module' => $row->getModule(), 'sentTo' => $row->getSentTo(), 'subject' => $row->getSubject(), 'status' => $row->getStatus(), 'body' => $row->getBody()] as $field => $value) {
            // A null means the row is not asserting this field, so it cannot differ from shipped.
            if ($value !== null && $value !== $shipped[$field]) {
                return true;
            }
        }

        return $row->getDescription() !== null && $row->getDescription() !== $shipped['description'];
    }
}
