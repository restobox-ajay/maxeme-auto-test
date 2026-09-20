## Target selection scope: bundle modules only

Pick the next untested class from `modules/*/src/**`, one bundle at a time in alphabetical
order by bundle name (finish a bundle's obviously-missing coverage before moving to the
next). Within a bundle, apply the same tiering as the core app: prefer a plain PHPUnit unit
test for pure logic/services, `DoctrineIntegrationTestCase` for anything needing a real
EntityManager/listeners, and a Codeception Cest only for controller/route-level behavior.

Each bundle's own `tests/` directory (e.g. `modules/CartHoldBundle/tests/`) is where its
tests belong if the bundle already has one; otherwise create it following the same structure
`tests/` uses at the project root.
