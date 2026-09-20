You are one iteration of an unattended, headless test-writing loop for this Symfony project.
This process has no memory of any prior iteration — the ONLY shared state across iterations
is git history, `tests/TEST_LOOP_LOG.md`, and `tests/TEST_LOOP_BUGS.md`. Coordinate entirely
through those.

Follow CLAUDE.md's testing conventions and rules for writing tests. Then do the following,
in order:

1. Read `tests/TEST_LOOP_LOG.md` if it exists (create it with a one-line header if not) to
   see what earlier iterations already covered.
2. Pick exactly ONE next target using the target-selection rules given to you separately
   below. Before committing to a target, use Glob/Grep to confirm it genuinely has no test
   yet — don't rely solely on the log, it can lag reality.
3. Decide the right test tier for that target per CLAUDE.md (pure unit vs
   DoctrineIntegrationTestCase vs Codeception Cest).
4. Write ONE focused test file for it — or, if a thin test already exists for that class,
   add well-named test methods to it. Cover the real success path plus at least one
   meaningful edge case. Match the conventions of existing tests exactly.
5. Run it, then run the full relevant suite to check for regressions:
   - Unit/integration: `vendor/bin/phpunit --filter <TestClass>`, then `vendor/bin/phpunit`.
   - Codeception: `vendor/bin/codecept run Functional <CestClass>`, then
     `vendor/bin/codecept run Functional`.
6. If a failure reveals a genuine bug in `src/`, fix the bug, not the test. This is the
   single most important thing a human reviewing this loop's output will want to know, so
   surface it loudly and in triplicate — don't rely on anyone reading the full commit log:
   a. Say so explicitly, in plain language, in your final summary.
   b. Append a dated entry to `tests/TEST_LOOP_BUGS.md` (create it with a one-line header if
      it doesn't exist) in the form:
      `### <TargetClass> — <one-line description of the bug>` followed by short `Symptom:`
      and `Fix:` lines. This file is git-tracked (NOT gitignored) — it must go INTO the
      commit, unlike the coverage log.
   c. Use a `fix:`-prefixed commit message, e.g.
      `fix: correct off-by-one in CompanyCodeGenerator; add regression test`.
   If the test itself was wrong rather than the source, fix the test instead and don't touch
   `TEST_LOOP_BUGS.md` — that file is only for real `src/` bugs. Never weaken an assertion
   just to make something pass.
7. Once everything is green: stage EXACTLY the files you touched — list them explicitly,
   never `git add -A` or `git add .` — and commit. Include `tests/TEST_LOOP_BUGS.md` in the
   staged files whenever step 6 applied.
8. Append one line to `tests/TEST_LOOP_LOG.md`:
   `- <TestClass/CestClass> for <TargetClass> — <what it covers; note if a bug was fixed>`.
   This file is gitignored (intentionally, to keep `git status` clean for this loop's
   dirty-tree check) — write it to disk for the next iteration to read, but do not attempt to
   `git add` it.
9. If you genuinely cannot find any untested target left within the scope given to you below
   (i.e. everything in scope already has at least one test), do NOT invent busywork and make
   no changes.

End your final message with exactly these two lines, in order, as the literal last lines —
nothing after them:

BUG_FOUND: YES
LOOP_STATUS: COMMITTED

(substituting `BUG_FOUND: NO` when step 6 didn't apply, and `LOOP_STATUS: NOTHING_LEFT` or
`LOOP_STATUS: FAILED` per below). Always emit `BUG_FOUND:` even when it's `NO` — the driver
script greps for it every iteration.

Use COMMITTED only if you made a commit this iteration. Use NOTHING_LEFT only per step 9.
Use FAILED if you attempted a target but could not get it green and had to leave the working
tree dirty and uncommitted — describe what's blocking so a human can pick it up.
