<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace the literal "expire in 1 hour" in the three token email_template rows with
 * {{ expiry_description }}.
 *
 * #450 fixed the file templates and #475 shipped a migration to fix these database rows, but the
 * rows are what actually get sent: UserController renders the file, then replaces the body with the
 * row when one exists. So the copy an admin reads still said one hour while an admin-issued reset
 * link lasted thirty days.
 *
 * #475's migration matched each row against its shipped original byte for byte before rewriting it,
 * so that wording an admin had customised at /admin/email-template could not be clobbered. That
 * guard was right, and it is exactly why these rows were skipped: they had already drifted from the
 * shipped text through an earlier unrelated change, so they looked hand-edited.
 *
 * This one therefore matches the SENTENCE rather than the document. It rewrites the single phrase
 * "expire in 1 hour" wherever it appears in those three rows and leaves every other byte alone —
 * markup, styling, greeting, signature, and any wording an admin really did customise. A row that
 * no longer contains the phrase is not touched, and neither is one that already carries the
 * variable, so this is idempotent and safe to replay.
 *
 * forgot_password is the row that forces the variable rather than a corrected literal: it serves
 * BOTH the self-service reset (password_reset_expiry_hours, default 1 hour) and the admin-issued
 * one (invite_token_expiry_days, default 30 days). There is no single number that is true for both,
 * which is the whole reason #475 introduced expiry_description.
 *
 * Both preconditions were verified before writing this, because getting either wrong renders
 * "expire in ." and is worse than the bug:
 *
 *   1. Every render path supplies the variable — Admin\AuthController:139 and
 *      Customer\AuthController:696 pass passwordResetExpiryDescription(); Admin\UserController:602
 *      and :650, Customer\CompanyUserController:318, CustomerInviteMailer:44 and
 *      Admin\CompanyController:1094 pass inviteTokenExpiryDescription().
 *   2. App\Twig\Sandbox\UserTemplateSecurityPolicy permits it — a bare variable is not a filter,
 *      function or method call, and rendering "expire in {{ expiry_description }}." through the
 *      real policy produces "expire in 30 days."
 *
 * Data only; no schema change, so doctrine:schema:validate is unaffected.
 */
final class Version20260807210000 extends AbstractMigration
{
    private const OLD = 'expire in 1 hour';
    private const NEW = 'expire in {{ expiry_description }}';

    /** @var list<string> */
    private const CODES = ['forgot_password', 'invite', 'new_user_invited'];

    public function getDescription(): string
    {
        return 'Replace the hardcoded "expire in 1 hour" in the forgot_password, invite and new_user_invited email_template rows with {{ expiry_description }}.';
    }

    public function up(Schema $schema): void
    {
        $this->rewrite(self::OLD, self::NEW);
    }

    /**
     * Deliberately reversible, and deliberately lossy in the same way up() is: it puts the literal
     * back. A row that never had the phrase is still not touched, so a down() on a database this
     * never applied to is a no-op rather than a corruption.
     */
    public function down(Schema $schema): void
    {
        $this->rewrite(self::NEW, self::OLD);
    }

    private function rewrite(string $from, string $to): void
    {
        $rewritten = 0;
        $skipped = [];

        foreach (self::CODES as $code) {
            /** @var array<int, array{id: int, body: string|null}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, body FROM email_template WHERE code = ?',
                [$code]
            );

            if ($rows === []) {
                $skipped[] = $code . ' (no row)';
                continue;
            }

            foreach ($rows as $row) {
                $body = (string) ($row['body'] ?? '');

                if (!str_contains($body, $from)) {
                    // Either already correct, or an admin has rewritten the sentence themselves.
                    // Both are reasons to leave it alone rather than impose the shipped wording.
                    $skipped[] = $code . ' (phrase not present)';
                    continue;
                }

                $this->connection->update(
                    'email_template',
                    ['body' => str_replace($from, $to, $body)],
                    ['id' => $row['id']]
                );
                $rewritten++;
            }
        }

        // #475 asked for this to be reported rather than silently counted, because "the migration
        // ran" and "the migration changed anything" were not the same thing there either.
        $this->write(sprintf('  rewrote %d email_template row(s)', $rewritten));

        if ($skipped !== []) {
            $this->write(sprintf('  left alone: %s', implode(', ', $skipped)));
        }
    }
}
