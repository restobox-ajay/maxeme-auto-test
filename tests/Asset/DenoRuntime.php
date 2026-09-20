<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\Assert;

/**
 * Locates the one JavaScript runtime this project is allowed to shell out to.
 *
 * Project policy is that Deno replaces node: node, npm and everything that comes with them (a
 * package.json, a node_modules tree, third-party packages borrowed from elsewhere on the machine)
 * are banned outright. The tests in this directory that need to parse or execute JavaScript
 * therefore go through here, and every one of them runs Deno under its default sandbox — `deno run`
 * with no `--allow-*` flags at all, which denies filesystem, environment and network access. That
 * sandbox is the entire reason for the choice, so nothing here may weaken it: no `deno eval`, which
 * runs with FULL permissions, and no permission flags "just to read one file". Anything a harness
 * needs must be inlined into the harness itself, since reading its own entrypoint is the one piece
 * of filesystem access the sandbox grants.
 *
 * Deno is not on PATH in non-interactive shells (a login shell picks it up from ~/.deno/env, cron
 * and IDE-spawned test runs do not), so the binary is resolved explicitly: PATH first, then the
 * conventional install location.
 *
 * When it cannot be found the callers FAIL rather than skip. A skip-guarded test reports green
 * while providing zero coverage, which is exactly how the regressions these tests exist to catch
 * would slip through unnoticed — a stray `});` in app.js, or a client-side price that disagrees
 * with the server's. Failing makes the dependency explicit and non-optional instead of quietly
 * degrading to nothing on any machine that happens to lack the runtime.
 */
final class DenoRuntime
{
    /** Where Deno's own installer puts it, and where it will be if it is not on PATH. */
    private const FALLBACK = '/.deno/bin/deno';

    /** Resolution shells out, and the asset tests ask repeatedly; the answer cannot change mid-run. */
    private static ?string $resolved = null;

    /**
     * The absolute path to the deno binary, or a test failure if there is not one.
     *
     * Never returns null: a caller that got a null back would have to decide what to do about it,
     * and the only tempting answer is a skip.
     */
    public static function binary(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $onPath = trim((string) shell_exec('command -v deno 2>/dev/null'));
        if ($onPath !== '' && is_executable($onPath)) {
            return self::$resolved = $onPath;
        }

        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        $fallback = $home . self::FALLBACK;
        if ($home !== '' && is_executable($fallback)) {
            return self::$resolved = $fallback;
        }

        Assert::fail(
            "Deno was not found, and this test fails rather than skips.\n\n"
            . "Deno is this project's only sanctioned JavaScript runtime — node and npm are banned "
            . "by project policy — and the tests in tests/Asset/ use it to parse and execute the "
            . "shipped assets. Skipping when it is missing would report green while covering "
            . "nothing, so the dependency is enforced instead.\n\n"
            . "Looked for `deno` on PATH, then at ~" . self::FALLBACK . ". Install Deno "
            . '(https://deno.com) so that one of those two resolves.',
        );
    }

    /**
     * Runs a self-contained JavaScript program under the sandbox and returns what it printed.
     *
     * The program is piped to `deno run -`, which reads it from stdin: nothing is written to disk to
     * get it there, so the run leaves nothing behind, cannot collide with a concurrent run over a
     * shared temporary path, and — the point — needs no filesystem permission at all. There are no
     * `--allow-*` flags, deliberately, so the executing chunk of application JavaScript can reach
     * neither the filesystem, the environment nor the network. `deno eval` would hand it full
     * permissions instead and is never used here.
     *
     * Self-contained is a real constraint on callers, not a figure of speech: with no filesystem
     * access the program cannot read its inputs, so anything it needs must be inlined into its own
     * source before it gets here.
     */
    public static function run(string $source): string
    {
        // Resolved before anything else: if Deno is missing this fails here, with an explanation,
        // rather than surfacing as an unreadable non-zero exit from proc_open() below.
        $deno = self::binary();

        $process = proc_open(
            [$deno, 'run', '--quiet', '--no-config', '--no-lock', '-'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        Assert::assertIsResource($process, 'Deno could not be started.');

        // Deno reads the program until stdin reaches EOF, so the pipe is closed before a single
        // byte of output is read back: reading first would leave each side waiting for the other.
        fwrite($pipes[0], $source);
        fclose($pipes[0]);

        [$stdout, $stderr] = self::drain($pipes[1], $pipes[2]);

        // The exit status comes from proc_close() itself; there is no earlier value to trust.
        $exitCode = proc_close($process);

        // Both streams go into the failure message, as a `2>&1` would: a broken harness reports on
        // stderr, and a half-printed result on stdout is just as much of a clue.
        Assert::assertSame(0, $exitCode, "The Deno harness failed:\n" . rtrim($stdout . $stderr));

        // Only stdout is returned as data. Keeping the streams apart means a stray warning on
        // stderr can no longer be spliced into the middle of the caller's JSON.
        return $stdout;
    }

    /**
     * Reads both of a child's output pipes to EOF without either one stalling the other.
     *
     * Draining stdout to completion first would deadlock as soon as Deno wrote more to stderr than
     * the pipe buffer holds — which is precisely what a failing harness does, so the deadlock would
     * appear only on the runs whose diagnostics matter.
     *
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{0: string, 1: string}
     */
    private static function drain($stdout, $stderr): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $open = ['out' => $stdout, 'err' => $stderr];
        $buffers = ['out' => '', 'err' => ''];

        while ($open !== []) {
            $read = array_values($open);
            $write = $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $ready) {
                $name = (string) array_search($ready, $open, true);
                $chunk = fread($ready, 8192);

                if ($chunk === false || $chunk === '') {
                    if (feof($ready)) {
                        unset($open[$name]);
                    }

                    continue;
                }

                $buffers[$name] .= $chunk;
            }
        }

        fclose($stdout);
        fclose($stderr);

        return [$buffers['out'], $buffers['err']];
    }
}
