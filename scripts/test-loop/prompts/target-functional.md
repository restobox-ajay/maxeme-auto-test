## Target selection scope: controllers only (Codeception)

Pick the next untested controller from `src/Controller/**` (including `src/Controller/Admin`
and `src/Controller/Customer`) that has no Cest exercising its route(s) yet.

The resulting test must be tier 3: a Codeception Cest under `tests/Functional/`, using the
`FunctionalTester` actor, following the conventions in existing Cests (e.g.
`tests/Functional/CartHoldCest.php`) — build real entities via `$I->haveInRepository(...)`,
log in via `$I->amLoggedInAs(...)` when the route requires auth, then hit the real route and
assert on the real HTTP-level outcome (response content, redirect, persisted state).
