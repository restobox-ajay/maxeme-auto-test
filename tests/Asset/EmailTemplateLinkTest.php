<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Guards the call-to-action links in the transactional emails (#223).
 *
 * Every button in an email body is written `{{ some_url|default('#') }}` — the fallback exists so a
 * missing context key degrades to a dead anchor instead of a Twig error. That makes a forgotten
 * context key completely silent: the mail sends, the button renders, and it goes nowhere. It has
 * happened more than once, hence "scan all email templates for # link" on the issue.
 *
 * Nothing here renders Twig. These read the shipped template sources plus the migration-seeded
 * EmailTemplate bodies as text — the same approach CustomerCartJsTest takes — and assert the two
 * places that decide whether a link resolves actually know about every variable in use:
 * the admin template preview's mock context, and the templates themselves.
 */
final class EmailTemplateLinkTest extends TestCase
{
    private const EMAIL_TEMPLATE_DIR = __DIR__ . '/../../templates/emails';
    private const MIGRATIONS_DIR = __DIR__ . '/../../migrations';
    private const CONFIG_CONTROLLER = __DIR__ . '/../../src/Controller/Admin/ConfigController.php';

    /**
     * The admin previews a template against a fixed mock context (ConfigController::
     * getMockDataForTemplate). A link variable absent from it previews as the literal href="#",
     * which is exactly the symptom reported — with nothing wrong with the template itself.
     */
    public function testThePreviewMockContextSuppliesEveryLinkVariableTheTemplatesRead(): void
    {
        $mockKeys = $this->previewMockContextKeys();

        foreach ($this->linkVariablesByTemplate() as $source => $variables) {
            foreach ($variables as $variable) {
                self::assertContains(
                    $variable,
                    $mockKeys,
                    sprintf(
                        '%s links via {{ %s|default(\'#\') }}, but the admin template preview does '
                        . 'not supply %s — its button previews as href="#".',
                        $source,
                        $variable,
                        $variable,
                    ),
                );
            }
        }
    }

    /**
     * A hard-coded href="#" has no fallback semantics at all: it is a dead link by construction,
     * and no context key can rescue it. The variable form is the only acceptable one here.
     */
    public function testNoShippedEmailTemplateHardCodesADeadLink(): void
    {
        foreach ($this->emailTemplateFiles() as $path) {
            self::assertStringNotContainsString(
                'href="#"',
                (string) file_get_contents($path),
                sprintf('%s hard-codes href="#", which cannot resolve to anything.', basename($path)),
            );
        }
    }

    /** @return array<string, list<string>> template source => link variable names it reads */
    private function linkVariablesByTemplate(): array
    {
        $found = [];

        foreach ([...$this->emailTemplateFiles(), ...$this->migrationFiles()] as $path) {
            $markup = (string) file_get_contents($path);

            // Matches both `{{ order_url|default('#') }}` and the nested
            // `{{ account_url|default(login_url|default('#')) }}` the login email uses.
            if (preg_match_all('/([a-z_]+)\s*\|\s*default\(\s*(?:[a-z_]+\s*\|\s*default\(\s*)?\'#\'/', $markup, $matches) === 0) {
                continue;
            }

            $names = array_values(array_unique($matches[1]));
            if ($names !== []) {
                $found[basename($path)] = array_merge($found[basename($path)] ?? [], $names);
            }
        }

        self::assertNotSame([], $found, 'No email template link fallbacks were found — the scan is not looking where it thinks.');

        return $found;
    }

    /**
     * The link variables above degrade to a dead anchor when the preview forgets them. A variable
     * printed BARE degrades much worse: strict_variables (config/packages/twig.yaml) turns it into
     * a render error, so the admin sees "Template Render Error" on a template that is perfectly
     * fine and nothing tells them the preview context is what is missing.
     *
     * expiry_description is exactly that shape since #475 — forgot_password, invite and
     * new_user_invited all print it with no |default(), because one row serves two flows with
     * different lifetimes and can therefore name neither.
     */
    public function testThePreviewMockContextSuppliesEveryBareVariableTheTemplatesRead(): void
    {
        $mockKeys = $this->previewMockContextKeys();

        foreach ($this->bareVariablesByTemplate() as $source => $variables) {
            foreach ($variables as $variable) {
                self::assertContains(
                    $variable,
                    $mockKeys,
                    sprintf(
                        '%s prints {{ %s }} with no |default(), but the admin template preview does '
                        . 'not supply %s — previewing it fails with a Twig error.',
                        $source,
                        $variable,
                        $variable,
                    ),
                );
            }
        }
    }

    /**
     * Deliberately narrow: only the variables this test is prepared to make a promise about, read
     * out of the shipped sources so a template that stops printing one stops being checked. A blind
     * scan for every `{{ name }}` would sweep up block-local {% set %} variables and macro
     * arguments and turn into noise nobody reads.
     *
     * @return array<string, list<string>> template source => bare variable names it reads
     */
    private function bareVariablesByTemplate(): array
    {
        $watched = ['expiry_description'];
        $found = [];

        foreach ([...$this->emailTemplateFiles(), ...$this->migrationFiles()] as $path) {
            $markup = (string) file_get_contents($path);

            foreach ($watched as $variable) {
                if (preg_match('/{{\s*' . preg_quote($variable, '/') . '\s*}}/', $markup) === 1) {
                    $found[basename($path)] = array_values(array_unique(
                        array_merge($found[basename($path)] ?? [], [$variable])
                    ));
                }
            }
        }

        self::assertNotSame([], $found, 'No bare expiry_description was found — the scan is not looking where it thinks.');

        return $found;
    }

    /** @return list<string> */
    private function previewMockContextKeys(): array
    {
        $source = (string) file_get_contents(self::CONFIG_CONTROLLER);

        $start = strpos($source, 'private function getMockDataForTemplate(');
        self::assertNotFalse($start, 'ConfigController no longer has getMockDataForTemplate().');

        $end = strpos($source, "\n    }", $start);
        self::assertNotFalse($end);

        preg_match_all("/'([a-z_]+)'\s*=>/", substr($source, $start, $end - $start), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private function emailTemplateFiles(): array
    {
        return array_values((array) glob(self::EMAIL_TEMPLATE_DIR . '/*.html.twig'));
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return array_values((array) glob(self::MIGRATIONS_DIR . '/*.php'));
    }
}
