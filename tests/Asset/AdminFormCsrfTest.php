<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Every admin POST form must render a CSRF token.
 *
 * CsrfProtectionSubscriber is deny-by-default for every non-safe request, and Csrf::isValidRequest()
 * accepts exactly two carriers: the X-CSRF-TOKEN header, or a _token field in the body. app.js
 * attaches that header to $.ajax and fetch() only — a NATIVE form submit sends neither. So a form
 * that renders no token is not "unprotected", it is unusable: the admin fills it in, submits, and
 * gets "your session expired or the form was stale" with everything they typed discarded.
 *
 * The functional suite cannot catch this. Tests\Support\Helper\Functional::csrfToken() scrapes the
 * <meta name="csrf-token"> tag "exactly as a curl or Guzzle client would" and each call site passes
 * it explicitly, so the suite simulates a non-browser client supplying a token the form itself never
 * renders. Green tests, broken browser — which is exactly how the order, quote and company forms all
 * shipped without one. This test reads the templates instead, which is where the gap actually lives.
 */
final class AdminFormCsrfTest extends TestCase
{
    /**
     * Login is the one legitimate exemption: the firewall enforces CSRF itself
     * (security.yaml firewalls.admin.form_login: enable_csrf, csrf_token_id 'authenticate') and
     * Admin\AuthController carries #[CsrfExempt] to say so. Demanding a second token there would
     * turn a wrong password into a CSRF error.
     */
    private const EXEMPT = [
        'templates/admin/auth/login.html.twig',
    ];

    public function testEveryAdminPostFormRendersACsrfToken(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $checked = 0;

        foreach ($this->adminTemplates($root . '/templates/admin') as $path) {
            $relative = str_replace($root . '/', '', $path);
            if (in_array($relative, self::EXEMPT, true)) {
                continue;
            }

            $markup = (string) file_get_contents($path);

            $forms = preg_match_all('/<form[^>]*method="post"/i', $markup);
            if ($forms === 0) {
                continue;
            }

            // Counted rather than matched per-form body: a form here can wrap hundreds of lines and
            // several nested partials, so pairing each opening tag with its own </form> is more
            // fragile than the thing it would be guarding. A file carrying at least one token per
            // POST form is what actually matters, and it fails loudly the moment a form is added
            // without one.
            $tokens = preg_match_all('/csrf_field\(\)|name="_token"/', $markup);

            $checked += $forms;
            if ($tokens < $forms) {
                $offenders[] = sprintf('%s (%d POST form(s), %d token(s))', $relative, $forms, $tokens);
            }
        }

        self::assertGreaterThan(0, $checked, 'Found no admin POST forms to check — the glob is wrong.');
        self::assertSame(
            [],
            $offenders,
            "These admin forms render no CSRF token, so a real browser submit is rejected and the "
            . "admin loses what they typed. Add {{ csrf_field() }} inside the <form>:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /** @return list<string> */
    private function adminTemplates(string $dir): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
