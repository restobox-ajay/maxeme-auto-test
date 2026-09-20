<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Twig\SandboxedTemplateRenderer;

/**
 * Resolves a template by code and renders its subject and body against a context (#507).
 *
 * This exists because the twelve lines below were copy-pasted at eight call sites — admin and
 * customer password reset, both invite paths, registration, email-change, order status update and
 * order message — each with its own findOneBy(), its own trim(), its own copy of the wrapping
 * heuristic and its own decision about what to do when the row was missing. Eight copies of a
 * heuristic is eight chances for one of them to drift, and no test compared them to each other.
 *
 * Callers now ask for a code and get finished text, or null when nothing resolves — at which point
 * they fall back to their shipped .twig view exactly as they did before.
 */
final class EmailTemplateRenderer
{
    public function __construct(
        private readonly EmailTemplateResolver $resolver,
        private readonly SandboxedTemplateRenderer $templateRenderer,
    ) {}

    /**
     * Null when the code resolves to nothing at all — which after #507 means a typo rather than an
     * un-seeded database, since every shipped code resolves from code with no row present.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $code, array $context): ?RenderedEmailTemplate
    {
        $template = $this->resolver->resolve($code);

        if ($template === null) {
            return null;
        }

        return new RenderedEmailTemplate(
            $this->templateRenderer->render($template->subject, $context),
            $this->templateRenderer->render($this->wrap($template->body), $context),
        );
    }

    /**
     * Give a body that is not a whole Twig document the layout it needs.
     *
     * An admin editing a template in the panel can quite reasonably type only the paragraph they
     * want sent, without {% extends %} or {% block %}. Rendering that as-is would produce a bare
     * fragment with no layout, styling or footer. So a body that is not already a full document
     * gets wrapped in the standard email layout — and any stray leading {% extends %} is stripped
     * first, because a body carrying an extends but no block would otherwise be wrapped into a
     * document with two of them.
     *
     * Moved here verbatim from the eight places that each had their own copy; deliberately not
     * "improved" in the move, so this commit changes no rendered output.
     */
    private function wrap(string $body): string
    {
        $body = trim($body);

        if (str_contains($body, '{% extends') && str_contains($body, '{% block')) {
            return $body;
        }

        $body = preg_replace('/^{%\s*extends\s+[^%]+%}\s*/i', '', $body);

        return "{% extends 'emails/layout.html.twig' %}\n{% block body %}\n" . trim((string) $body) . "\n{% endblock %}";
    }
}
