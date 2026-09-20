<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\SandboxedTemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityError;

/**
 * Guards the boundary that stops an admin-authored EmailTemplate/TemplateOverride from becoming
 * OS command execution. Uses the real container Twig environment so the assertions cover the
 * policy as it is actually wired, not a hand-built environment that could drift from production.
 */
final class SandboxedTemplateRendererTest extends KernelTestCase
{
    private function renderer(): SandboxedTemplateRenderer
    {
        self::bootKernel();

        return self::getContainer()->get(SandboxedTemplateRenderer::class);
    }

    /**
     * The headline case: every filter that accepts a callable is the standard Twig sandbox escape.
     *
     * @return iterable<string, array{string}>
     */
    public static function codeExecutionAttemptProvider(): iterable
    {
        yield 'map with callable string'    => ["{{ ['id']|map('system')|join }}"];
        yield 'filter with callable string' => ["{{ ['id']|filter('system')|join }}"];
        yield 'reduce with callable string' => ["{{ ['id']|reduce('system') }}"];
        yield 'sort with callable string'   => ["{{ ['id']|sort('system')|join }}"];
        yield 'constant()'                  => ["{{ constant('PHP_OS') }}"];
        yield 'source() file read'          => ["{{ source('../.env') }}"];
        yield 'attribute() escape hatch'    => ["{{ attribute('system', 'x', []) }}"];
        yield 'autoescape disabled'         => ['{% autoescape false %}{{ evil }}{% endautoescape %}'];
    }

    #[DataProvider('codeExecutionAttemptProvider')]
    public function testCodeExecutionVectorsAreRejected(string $source): void
    {
        $this->expectException(SecurityError::class);

        $this->renderer()->render($source, ['evil' => '<b>x</b>']);
    }

    /**
     * template_from_string() is the other classic sink, but this application never registers it
     * (it ships with twig/extra-bundle, not StringLoaderExtension), so it currently fails at parse
     * time as an unknown function rather than at the policy. Asserted as the base Twig error type
     * so the test states what actually happens; it is absent from
     * UserTemplateSecurityPolicy::ALLOWED_FUNCTIONS, so adding the extension later cannot quietly
     * open it.
     */
    public function testTemplateFromStringIsUnavailable(): void
    {
        $this->expectException(\Twig\Error\Error::class);

        $this->renderer()->render("{{ include(template_from_string('hacked')) }}");
    }

    public function testTemplateCannotReachInfrastructureObjectsFromContext(): void
    {
        $this->expectException(SecurityError::class);

        // A non-entity object in the context must not be walkable — this is what keeps a template
        // from stepping off a context value into the container or the kernel.
        $this->renderer()->render('{{ svc.getExtension("x") }}', [
            'svc' => self::getContainer()->get(Environment::class),
        ]);
    }

    public function testLegitimateEmailTemplateStillRenders(): void
    {
        $rendered = $this->renderer()->render(
            '{% extends \'emails/layout.html.twig\' %}{% block body %}Hello {{ name|default(\'there\') }}, total {{ amount|number_format(2) }}{% endblock %}',
            ['name' => 'Ada', 'amount' => 1234.5],
        );

        self::assertStringContainsString('Hello Ada, total 1,234.50', $rendered);
        // Proves the trusted layout — which calls app_setting(), site_name() and the date filter —
        // also passes the policy, since {% extends %} is rendered with the sandbox still enabled.
        // Asserted on the footer copyright line, which is independent of whatever store name resolves.
        self::assertStringContainsString('All rights reserved.', $rendered);
    }

    public function testOutputIsStillAutoescaped(): void
    {
        $rendered = $this->renderer()->render('{{ name }}', ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $rendered);
    }

    public function testSandboxIsDisabledAgainAfterATemplateThrows(): void
    {
        $sandbox = self::getContainer()->get(Environment::class)->getExtension(SandboxExtension::class);

        try {
            $this->renderer()->render("{{ ['id']|map('system')|join }}");
        } catch (SecurityError) {
            // expected
        }

        // The environment is shared for the rest of the request; leaving the sandbox on would
        // silently sandbox every subsequent repository template render.
        self::assertFalse($sandbox->isSandboxed(), 'Sandbox must be switched off even when a template throws.');
    }

    public function testNoDirectCreateTemplateCallSurvivesOutsideThisRenderer(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];

        foreach ([$root . '/src', $root . '/modules'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                if (str_ends_with($path, 'src/Twig/SandboxedTemplateRenderer.php')) {
                    continue;
                }

                if (str_contains((string) file_get_contents($path), 'createTemplate(')) {
                    $offenders[] = substr($path, \strlen($root) + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Database-sourced Twig must be rendered through SandboxedTemplateRenderer, not createTemplate():\n"
            . implode("\n", $offenders),
        );
    }
}
