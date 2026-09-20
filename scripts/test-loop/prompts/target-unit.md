## Target selection scope: core unit/integration only

Pick the next untested class from `src/Service/**`, `src/EventSubscriber/**`,
`src/Security/**`, `src/Doctrine/**`, `src/Twig/**`, or `src/Command/**` — core business
logic, not controllers, not bundle modules. Skip `src/Entity/**`, `src/Enum/**`, and
`src/Contract/**`.

The resulting test must be tier 1 (pure PHPUnit unit test) or tier 2
(`DoctrineIntegrationTestCase`) per CLAUDE.md — never a Codeception Cest in this mode.
