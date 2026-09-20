## Target selection scope: everything, core first

Pick the next target in this priority order, skipping anything already covered by an
existing test file:

1. Untested classes in `src/Service/**`, `src/EventSubscriber/**`, `src/Security/**`,
   `src/Doctrine/**`, `src/Twig/**`, `src/Command/**` — core business logic first.
2. Untested controllers in `src/Controller/**` — these need a Codeception Cest exercising
   the real route.
3. Untested classes in `modules/*/src/**`, one bundle at a time in alphabetical order —
   apply the same service-vs-controller tiering within each bundle as above.

Skip `src/Entity/**`, `src/Enum/**`, and `src/Contract/**` (interfaces/enums/entities aren't
unit-test targets on their own).
