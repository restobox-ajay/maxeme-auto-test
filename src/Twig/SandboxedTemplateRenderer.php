<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Environment;
use Twig\Extension\SandboxExtension;

/**
 * The single entry point for compiling Twig that came from the database rather than from the
 * repository — EmailTemplate subjects/bodies, TemplateOverride sources, and the admin template
 * preview. Every one of those is admin-authored, and {@see \App\Twig\Sandbox\UserTemplateSecurityPolicy}
 * explains why compiling them unsandboxed is remote code execution.
 *
 * Call this instead of `$twig->createTemplate(...)->render(...)`. There should be no remaining
 * direct createTemplate() call on a database-sourced string anywhere in the codebase; the
 * SandboxedTemplateRendererTest asserts exactly that.
 *
 * Implementation note: the sandbox is registered on the *main* environment in non-global mode and
 * toggled around the render, rather than run in a second Environment. A second Environment would
 * not carry the application's Twig extensions, so app_setting() and friends would break inside
 * emails. Because SandboxExtension's node visitor compiles a security check into every template,
 * enabling the sandbox here also covers the trusted layout and partials pulled in via
 * {% extends %} / {% include %} — those were checked against the policy and comply.
 */
final class SandboxedTemplateRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * Compile and render an untrusted template source under the sandbox policy.
     *
     * @param array<string, mixed> $context
     *
     * @throws \Twig\Sandbox\SecurityError when the source uses a construct the policy forbids
     * @throws \Twig\Error\Error           on ordinary syntax/runtime errors, as createTemplate() would
     */
    public function render(string $source, array $context = []): string
    {
        $sandbox = $this->twig->getExtension(SandboxExtension::class);

        // Compilation itself executes nothing, but the rendered output of {% extends %}/{% include %}
        // is produced during render(), so the sandbox has to be enabled across both.
        $sandbox->enableSandbox();

        try {
            return $this->twig->createTemplate($source)->render($context);
        } finally {
            // The environment is shared for the rest of the request, so this must run even when
            // the template throws — otherwise an admin's broken template would silently sandbox
            // every subsequent render on the same request.
            $sandbox->disableSandbox();
        }
    }
}
