<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/app.js must at least parse.
 *
 * Nothing else in the suite executes this file — Codeception drives the Symfony kernel, never a
 * browser, and the other tests in this directory only pattern-match its text — so a stray or missing
 * brace from a hand-edited diff (an unbalanced `});` from a removed block, for one real case) shipped
 * silently until someone loaded the page and found every handler on it dead.
 *
 * The parser is Deno's, not node's: node and npm are banned by project policy and Deno replaces
 * them. `deno check` is the subcommand that reproduces `node --check`'s contract, and the
 * alternatives do not:
 *
 *   - `deno lint` parses too, but it also enforces style. On this file it reports several hundred
 *     violations (no-var, no-window, no-unused-vars, no-inner-declarations) for a jQuery-era browser
 *     asset that is not going to be rewritten to satisfy them, so it can never be green and would
 *     say nothing about syntax.
 *   - `deno fmt --check` reports formatting differences, which is a different question again.
 *   - `deno run` would execute the file, and it wants a DOM.
 *
 * `deno check` type-checks TypeScript, but TypeScript's checkJs is off by default and this is a
 * plain `.js` file with no `// @ts-check`, so what it actually reports on it is parse errors and
 * nothing else — verified against a copy full of undefined globals, bad argument counts and a
 * string assigned to a `@type {number}`, all of which it passes. `--no-config` keeps that true no
 * matter what a future deno.json in the repo root might turn on; `--no-lock` keeps the run from
 * wanting to write one. Deliberate corruptions of a copy (an extra `});`, and a removed one) are
 * both reported as SyntaxError with a non-zero exit, so this is at least as strong as `node --check`
 * was.
 */
final class AppJsSyntaxTest extends TestCase
{
    private const APP_JS = __DIR__ . '/../../public/assets/js/app.js';

    public function testAppJsParses(): void
    {
        exec(
            escapeshellarg(DenoRuntime::binary())
            . ' check --no-config --no-lock '
            . escapeshellarg(self::APP_JS) . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, "app.js failed to parse:\n" . implode("\n", $output));
    }
}
