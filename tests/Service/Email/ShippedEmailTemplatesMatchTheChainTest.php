<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Service\Email\ShippedEmailTemplates;
use PHPUnit\Framework\TestCase;

/**
 * Proves that moving the 22 shipped email templates out of the migration chain (#507) did not
 * change a single byte of any of them.
 *
 * It does NOT constrain the catalogue's size. Templates written after the extraction have no chain
 * counterpart and are outside what this test can or should say anything about — it compares the
 * overlap, not the totals.
 *
 * This is the whole safety argument for that move, and it is not a formality. The templates are
 * customer-facing email — an invoice, an order confirmation, a password reset. If one character of
 * one body drifted during the extraction, the email would still render, still send, and still look
 * broadly right; nothing else in either suite compares its content to anything. The failure would
 * be found by a customer.
 *
 * So the comparison is byte equality against the ACTUAL OUTPUT OF THE MIGRATION CHAIN, replayed
 * into a scratch database, rather than against a fixture someone typed. That is the same technique
 * the #367 baseline used to verify itself ("verified by replaying the old chain into a scratch
 * database and diffing the result against this migration's own output"), for the same reason.
 *
 * When the chain is re-baselined WITHOUT the email_template seed, this test stops having a chain to
 * compare against and should be deleted along with the seed — at that point ShippedEmailTemplates
 * is the source of truth outright and there is no second copy to disagree with it. Until then it is
 * the thing standing between an extraction typo and a customer.
 *
 * ## Changing a shipped body on purpose
 *
 * Byte equality against a frozen chain and a catalogue that is allowed to evolve cannot both be
 * absolute, and the first deliberate edit — the `|amount` adoption below — is where that showed.
 * The migration chain is history and is not rewritten, so a template the application has changed
 * on purpose has to be declared: see DELIBERATE_CHANGES_SINCE_THE_EXTRACTION, which records each
 * edit as the exact substitution it made rather than exempting the template from the comparison.
 * Everything an entry does not name is still compared character for character.
 */
final class ShippedEmailTemplatesMatchTheChainTest extends TestCase
{
    /**
     * Edits made to a shipped body AFTER the #507 extraction, keyed by code and field, each as the
     * exact text the chain produces and the exact text that ships today.
     *
     * ## Why an allowance exists at all, and why it is shaped like this
     *
     * The chain is history. An `email_template` seed inside a migration that has already run on
     * every install cannot be corrected in place, and a NEW migration that rewrites those rows is
     * the thing #507 exists to stop — the rows are admin-editable, so a migration editing them
     * either clobbers somebody's copy or matches nothing and cannot tell which it did
     * (Version20260805020000 is the scar). So when a shipped body legitimately changes, the chain
     * and the catalogue diverge permanently and one of them has to say why.
     *
     * The alternative to this list is worse in both directions. Exempting a TEMPLATE would mean a
     * typo anywhere else in invoice_customer.html.twig stops being caught, in the one file where
     * "still renders, still sends, still looks broadly right" is the entire failure mode. Dropping
     * the comparison and re-pinning to whatever ships today would delete the only evidence that the
     * extraction was faithful.
     *
     * So an entry is a SUBSTITUTION, applied to the chain text before the comparison, and both
     * halves are asserted to still be real below: the chain must still contain the `from`, and the
     * shipped file must still contain the `to`. Revert the template and this file fails; re-baseline
     * the chain and this file fails; retype one other word of the body and this file fails naming
     * the line. An allowance that has stopped being true is worse than no allowance, which is the
     * same habit MoneyAndQuantityAreFormattedByTheFilterTest's NOT_YET_ADOPTED keeps.
     *
     * @var array<string, array<string, array{from: string, to: string, why: string}>>
     */
    private const DELIBERATE_CHANGES_SINCE_THE_EXTRACTION = [
        'invoice_customer' => [
            'body' => [
                'from' => '<strong>${{ invoice.total|default(0)|number_format(2) }}</strong>',
                'to' => '<strong>${{ invoice.total|default(0)|amount }}</strong>',
                'why' => 'The grand total joined the one place that decides what a number looks like'
                    . ' (495eaf78). |amount IS number_format(2) for money — App\Service\DisplayNumber'
                    . ' renders a money total at exactly two decimals with a comma separator, so this'
                    . ' email\'s figure is byte-identical before and after — and the point of moving it'
                    . ' is that the next change to how money prints reaches this email along with the'
                    . ' rest of the application instead of leaving it behind. Not optional, either:'
                    . ' MoneyAndQuantityAreFormattedByTheFilterTest scans templates/ including this'
                    . ' directory, and reverting this line makes that file red naming it.'
                    . ' Re-pointed from order.total to invoice.total in the same commit that made the'
                    . ' whole body invoice-first (Version20260919180000) — the entry moved with the'
                    . ' line it describes rather than gaining a second one beside it.',
            ],
        ],
        'invoice_self' => [
            'body' => [
                'from' => '<strong>${{ invoice.total|default(0)|number_format(2) }}</strong>',
                'to' => '<strong>${{ invoice.total|default(0)|amount }}</strong>',
                'why' => 'The internal copy of the same document, changed in the same commit for the'
                    . ' same reason. Listed separately rather than folded in with the customer copy'
                    . ' because they are two files and either could be reverted on its own.'
                    . ' Re-pointed from order.total to invoice.total alongside its customer-copy twin,'
                    . ' same commit (Version20260919180000).',
            ],
        ],
    ];

    private static ?string $databaseFile = null;

    /** @var array<string, array<string, string|null>>|null */
    private static ?array $chainRows = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$databaseFile !== null && is_file(self::$databaseFile)) {
            @unlink(self::$databaseFile);
        }

        self::$databaseFile = null;
        self::$chainRows = null;
    }

    public function testEveryShippedCodeIsInTheChain(): void
    {
        $chain = $this->chainRows();

        self::assertSame(
            array_values(array_diff(array_keys($chain), ShippedEmailTemplates::codes())),
            [],
            'the migration chain seeds a template that ShippedEmailTemplates does not ship',
        );
        // Deliberately one-directional. What #507 has to prove is that nothing was LOST or CHANGED
        // in the extraction, which is the assertion above plus the byte comparison below. A
        // template the catalogue ships and the chain does not seed is not a failure — it is a
        // template written after the extraction, which by definition has no chain counterpart to
        // have drifted from.
        //
        // The previous version asserted set EQUALITY and a hard count of 22, which made this test
        // read as "the catalogue may never grow". That was never the safety argument, and it cost
        // a real feature: #555's purchase-order email could not be registered here at all, so it
        // ships as a fallback view and does not appear in Settings > Email Templates.
        self::assertGreaterThanOrEqual(
            count($chain),
            count(ShippedEmailTemplates::codes()),
            'the catalogue must ship at least everything the chain seeds',
        );
    }

    /**
     * Byte equality, field by field, so a failure names the template AND the field rather than
     * dumping two 2kB blobs side by side.
     *
     * Every mismatch in the catalogue is collected before anything is asserted, deliberately. The
     * previous version asserted inside the loop, so the first bad field ended the test and the
     * report said "invoice_customer.body" when TWO templates had drifted — the second one, the
     * internal copy of the same document, was invisible until the first was dealt with. A drift
     * that happens to a body usually happened to its neighbours in the same edit, so stopping at
     * one is the least useful place to stop.
     */
    public function testEveryShippedTemplateMatchesTheChainByte(): void
    {
        $mismatches = [];

        foreach ($this->chainRows() as $code => $row) {
            $shipped = ShippedEmailTemplates::get($code);
            self::assertIsArray($shipped, sprintf('nothing ships under code "%s"', $code));

            foreach (['module', 'sentTo', 'subject', 'description', 'status', 'body'] as $field) {
                $chainValue = $this->withDeliberateChangesApplied($code, $field, $row[$field]);

                if ($chainValue !== $shipped[$field]) {
                    $mismatches[] = self::describe($code, $field, $chainValue, $shipped[$field]);
                }
            }
        }

        self::assertSame([], $mismatches, sprintf(
            "These shipped templates no longer match what the migration chain produces:\n\n%s\n\n"
            . "If the difference was NOT intended, the shipped file is wrong: a changed byte here is a"
            . " changed customer-facing email that still renders, still sends and still looks broadly"
            . " right, and nothing else in either suite compares its content to anything.\n\n"
            . "If it WAS intended, say so in DELIBERATE_CHANGES_SINCE_THE_EXTRACTION at the top of this"
            . " file — the exact text the chain produces, the exact text that ships, and why. Do not"
            . " re-pin this test to whatever ships today: that deletes the only evidence that the #507"
            . " extraction was faithful.",
            implode("\n\n", $mismatches),
        ));
    }

    /**
     * The other half of the allowance: every declared change must still be a real, live change.
     *
     * Without this, an entry outlives what it describes. Revert the template and the substitution
     * quietly matches nothing while the comparison above still passes, so the file would claim a
     * deliberate edit that is no longer in the tree — a considered decision recorded about
     * something that is no longer the case, which reads as more trustworthy than a plain gap and is
     * not. Both halves are checked, because the entry can go stale from either end: the chain no
     * longer producing the `from` (a re-baseline) and the catalogue no longer shipping the `to` (a
     * revert) are different events that both make the entry a lie.
     */
    public function testEveryDeclaredChangeIsStillARealOne(): void
    {
        $chain = $this->chainRows();
        $stale = [];

        foreach (self::DELIBERATE_CHANGES_SINCE_THE_EXTRACTION as $code => $fields) {
            foreach ($fields as $field => $change) {
                self::assertNotSame('', trim($change['why']), sprintf('%s.%s is allowed with no reason beside it', $code, $field));

                $chainValue = (string) ($chain[$code][$field] ?? '');
                $shippedValue = (string) (ShippedEmailTemplates::get($code)[$field] ?? '');

                if (!str_contains($chainValue, $change['from'])) {
                    $stale[] = sprintf(
                        '%s.%s — the chain no longer produces the text this entry replaces:%s%s',
                        $code,
                        $field,
                        "\n    ",
                        $change['from'],
                    );
                }

                if (!str_contains($shippedValue, $change['to'])) {
                    $stale[] = sprintf(
                        '%s.%s — the shipped template no longer carries the text this entry allows:%s%s',
                        $code,
                        $field,
                        "\n    ",
                        $change['to'],
                    );
                }
            }
        }

        sort($stale);

        self::assertSame([], $stale, sprintf(
            "These DELIBERATE_CHANGES_SINCE_THE_EXTRACTION entries no longer describe the tree:\n\n  %s\n\n"
            . "If the shipped template was reverted, delete its entry in the same commit so the body"
            . " goes back to being byte-pinned to the chain. If the chain was re-baselined without the"
            . " email_template seed, this whole file has nothing left to compare against and should go"
            . " with the seed.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * The rtrim() in ShippedEmailTemplates::body() is load-bearing, and silently so: if the files
     * were ever stored WITHOUT their trailing newline it would still pass every other assertion
     * here, right up until an editor added one back. This pins the file-on-disk convention itself,
     * so the next person to wonder why the loader trims gets an answer rather than a guess.
     */
    public function testTheFilesOnDiskCarryATrailingNewlineTheLoaderStrips(): void
    {
        $directory = \dirname(__DIR__, 3) . '/templates/emails/shipped';

        foreach (ShippedEmailTemplates::codes() as $code) {
            $raw = file_get_contents($directory . '/' . $code . '.html.twig');

            self::assertIsString($raw, sprintf('%s.html.twig is missing', $code));
            self::assertStringEndsWith("\n", $raw, sprintf('%s.html.twig should end with a newline', $code));
            self::assertStringEndsNotWith("\n", ShippedEmailTemplates::get($code)['body'], sprintf('%s body should not', $code));
        }
    }

    /**
     * The chain's text for one field with any declared post-extraction edit applied to it.
     *
     * Applied to the CHAIN side rather than tolerated on the shipped side on purpose: after this
     * the comparison is still plain byte equality over the whole field, so every character the
     * entry does not name is held to the chain exactly as before.
     */
    private function withDeliberateChangesApplied(string $code, string $field, ?string $chainValue): ?string
    {
        $change = self::DELIBERATE_CHANGES_SINCE_THE_EXTRACTION[$code][$field] ?? null;

        if ($change === null || $chainValue === null) {
            return $chainValue;
        }

        return str_replace($change['from'], $change['to'], $chainValue);
    }

    /**
     * One mismatch, as the line it happens on rather than as two 2kB blobs.
     *
     * A shipped body is 20-40 lines of near-identical HTML, and a whole-field diff of one is
     * unreadable — the reason the original test compared field by field in the first place. This
     * keeps that and goes one further: the first line that actually differs, both sides, with its
     * number, so the failure is something you can act on without opening either file.
     *
     * The left-hand side is labelled "expected" rather than "chain" because by this point it is the
     * chain's text with any declared substitution already applied — for a template named in
     * DELIBERATE_CHANGES_SINCE_THE_EXTRACTION the two are not the same string, and calling it the
     * chain would be reporting something the chain does not actually contain.
     */
    private static function describe(string $code, string $field, ?string $expected, ?string $shipped): string
    {
        $chainLines = explode("\n", (string) $expected);
        $shippedLines = explode("\n", (string) $shipped);

        foreach ($chainLines as $index => $line) {
            if (($shippedLines[$index] ?? null) !== $line) {
                return sprintf(
                    "  %s.%s, line %d:\n    expected: %s\n    shipped:  %s",
                    $code,
                    $field,
                    $index + 1,
                    $line,
                    $shippedLines[$index] ?? '<the shipped value ends here>',
                );
            }
        }

        return sprintf(
            "  %s.%s, line %d:\n    expected: <the expected value ends here>\n    shipped:  %s",
            $code,
            $field,
            count($chainLines) + 1,
            $shippedLines[count($chainLines)] ?? '',
        );
    }

    /**
     * Replays the whole chain into a throwaway SQLite file and reads email_template back out of it.
     *
     * Deliberately shells out to the real console rather than booting a kernel and pointing it at a
     * different database: the migrations are what is under test, and running them through anything
     * other than doctrine:migrations:migrate would be testing a reimplementation of the thing that
     * matters.
     *
     * @return array<string, array<string, string|null>>
     */
    private function chainRows(): array
    {
        if (self::$chainRows !== null) {
            return self::$chainRows;
        }

        $projectDir = \dirname(__DIR__, 3);
        self::$databaseFile = sys_get_temp_dir() . '/shipped-email-chain-' . getmypid() . '.sqlite';
        @unlink(self::$databaseFile);

        $command = sprintf(
            'APP_ENV=dev DATABASE_URL=%s %s %s doctrine:migrations:migrate --no-interaction --quiet 2>&1',
            escapeshellarg('sqlite:///' . self::$databaseFile),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($projectDir . '/bin/console'),
        );

        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, "replaying the migration chain failed:\n" . implode("\n", $output));

        $pdo = new \PDO('sqlite:' . self::$databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT code, module, sent_to, subject, body, description, status FROM email_template ORDER BY code');

        $rows = [];
        foreach ($statement as $row) {
            $rows[$row['code']] = [
                'module' => $row['module'],
                'sentTo' => $row['sent_to'],
                'subject' => $row['subject'],
                'description' => $row['description'],
                'status' => $row['status'],
                'body' => $row['body'],
            ];
        }

        self::assertNotEmpty($rows, 'the chain produced no email_template rows at all');

        return self::$chainRows = $rows;
    }
}
