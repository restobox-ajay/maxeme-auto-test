<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The single source of truth for CSRF: one place that mints a token, one place that verifies it.
 *
 * Why this exists rather than framework config: `framework.csrf_protection` only makes the Symfony
 * Form component inject and validate a token, and this app does not use that component
 * (symfony/form is not installed; controllers read $request->request->get(...) directly). So the
 * codebase grew ~128 hand-written isCsrfTokenValid() calls across ~92 token ids, each needing to be
 * remembered and paired by hand with a matching csrf_token() in a template. Every endpoint where
 * someone forgot was simply unprotected.
 *
 * Naming follows Symfony's own conventions rather than inventing our own:
 *   FIELD  '_token'        - the Form component's default csrf_protection.field_name
 *   HEADER 'X-CSRF-TOKEN'  - what Symfony 7.2's stateless CSRF uses
 *   ID     'submit'        - Symfony 7.2's id for a generic form submission (its defaults are
 *                            ['submit', 'authenticate', 'logout']; 'authenticate' stays reserved
 *                            for the firewall's login CSRF, which this does not touch)
 *
 * One global token, not per-form: same approach as Rails, Django and Laravel. Cross-site protection
 * is identical either way — what a per-form token adds is scoping against same-site token reuse,
 * which realistically requires XSS, and an attacker with XSS can read any token anyway. If a
 * specific form ever warrants scoping, it is a small change: add an optional argument here and to
 * csrf_field(), since generation and verification each live in exactly one place.
 *
 * Callers should not use CsrfTokenManagerInterface directly. Generate via {{ csrf_field() }}
 * (App\Twig\CsrfExtension); verify via App\EventSubscriber\CsrfProtectionSubscriber.
 */
final class Csrf
{
    public const ID = 'submit';
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-TOKEN';

    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
    ) {
    }

    /** Raw token value, for the <meta> tag that the AJAX helper in app.js reads. */
    public function token(): string
    {
        return $this->tokenManager->getToken(self::ID)->getValue();
    }

    /** The hidden input for a form. THE generation point. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * Whether the request carries a valid token, in either the form field or the header.
     * THE verification point.
     *
     * The header exists for the admin's JS-only endpoints (delete buttons, price grid, reorder),
     * which have no form to put a field in. Ordinary forms need only the field, so a no-JS browser
     * is fully served without it.
     */
    public function isValidRequest(Request $request): bool
    {
        $submitted = $request->headers->get(self::HEADER)
            ?? $request->request->get(self::FIELD);

        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        return $this->tokenManager->isTokenValid(new CsrfToken(self::ID, $submitted));
    }
}
