<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Makes admin-written rich text (news posts) safe to print unescaped: an allowlist of formatting
 * tags, a few attributes, and links only to http(s), mailto, tel or a relative address.
 *
 * Without it, `{{ post.content|raw }}` let anyone who can edit a post put a <script>, an
 * onerror= or a javascript: link in front of every customer who opens it.
 *
 * - Unknown tags are unwrapped, so their text survives.
 * - Tags that are code or a page inside the page (script, style, iframe, form, svg …) are
 *   removed together with their content.
 * - Every attribute not listed is dropped. That includes style and every on*= handler.
 */
final class RichTextSanitizer
{
    /** tag => attributes it may keep */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [], 'div' => [], 'span' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'mark' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /** Removed with everything inside them. */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'textarea', 'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base',
        'head', 'title', 'video', 'audio', 'source', 'track', 'canvas', 'portal',
    ];

    private const URL_ATTRIBUTES = ['href', 'src'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // The XML prolog makes libxml read the text as UTF-8; the wrapper div gives one root to walk.
        $document->loadHTML('<?xml encoding="UTF-8"?><div id="rich-text-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('rich-text-root');
        if (!$root instanceof \DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $this->cleanChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    private function cleanChildren(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMText) {
                continue;
            }

            if (!$node instanceof \DOMElement) {
                // Comments, processing instructions, CDATA: never needed in a post.
                $parent->removeChild($node);
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROPPED, true)) {
                $parent->removeChild($node);
                continue;
            }

            $this->cleanChildren($node);

            if (!array_key_exists($tag, self::ALLOWED)) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->cleanAttributes($node, self::ALLOWED[$tag]);

            if ($tag === 'a' && $node->hasAttribute('href')) {
                $node->setAttribute('rel', 'noopener noreferrer nofollow');
            }
            if ($tag === 'img' && !$node->hasAttribute('src')) {
                $parent->removeChild($node);
            }
        }
    }

    /** @param list<string> $allowed */
    private function cleanAttributes(\DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && !$this->isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    private function isSafeUrl(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside a scheme ("java\tscript:"), so
        // they are removed before the scheme is read.
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';
        if ($compact === '') {
            return false;
        }

        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $compact, $m)) {
            // No scheme: relative, root-relative or a #fragment. A protocol-relative "//host" is
            // fine too; it uses the page's own scheme.
            return true;
        }

        return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true);
    }
}
