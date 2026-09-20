<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Service\Onboarding\OnboardingChecklistService;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * OnboardingChecklistService is the seam that makes #426 "very extensible" — a check is added
 * just by implementing OnboardingCheckInterface (proven end-to-end by
 * `debug:container --tag=app.onboarding_check` picking up all real checks with zero manual
 * wiring). This test proves the two behaviors that belong to the service itself: ordering
 * ("not done items float on top") and defensive isolation between checks.
 */
final class OnboardingChecklistServiceTest extends TestCase
{
    private function fakeCheck(string $key, string $group, string $label, int $sortOrder, OnboardingCheckResult $result): OnboardingCheckInterface
    {
        return new class($key, $group, $label, $sortOrder, $result) implements OnboardingCheckInterface {
            public function __construct(
                private readonly string $key,
                private readonly string $group,
                private readonly string $label,
                private readonly int $sortOrder,
                private readonly OnboardingCheckResult $result,
            ) {
            }

            public function getKey(): string { return $this->key; }
            public function getGroup(): string { return $this->group; }
            public function getLabel(): string { return $this->label; }
            public function getSortOrder(): int { return $this->sortOrder; }
            public function run(): OnboardingCheckResult { return $this->result; }
        };
    }

    private function throwingCheck(string $key): OnboardingCheckInterface
    {
        return new class($key) implements OnboardingCheckInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function getKey(): string { return $this->key; }
            public function getGroup(): string { return 'Broken'; }
            public function getLabel(): string { return 'Throws'; }
            public function getSortOrder(): int { return 0; }

            public function run(): OnboardingCheckResult
            {
                throw new \RuntimeException('boom: mailer.dsn=smtp://user:supersecret@host');
            }
        };
    }

    public function testNoChecksReturnsAnEmptyList(): void
    {
        $service = new OnboardingChecklistService([], new NullLogger());

        self::assertSame([], $service->run());
    }

    public function testFailedChecksFloatAboveAllPassedChecksRegardlessOfGroup(): void
    {
        $service = new OnboardingChecklistService([
            $this->fakeCheck('a_zzz_group_passed', 'Zzz Group', 'A', 0, OnboardingCheckResult::pass()),
            $this->fakeCheck('b_aaa_group_failed', 'Aaa Group', 'B', 0, OnboardingCheckResult::fail('nope')),
            $this->fakeCheck('c_mmm_group_passed', 'Mmm Group', 'C', 0, OnboardingCheckResult::pass()),
            $this->fakeCheck('d_ooo_group_failed', 'Ooo Group', 'D', 0, OnboardingCheckResult::fail('nope either')),
        ], new NullLogger());

        $rows = $service->run();
        $passedFlags = array_column($rows, 'passed');

        self::assertCount(4, $rows);
        // Both failed rows (regardless of their group's alphabetical position) must appear
        // before both passed rows.
        self::assertSame([false, false, true, true], $passedFlags);
    }

    public function testWithinTheSamePassedStateOrderingIsGroupThenSortOrderThenLabelNotRegistrationOrder(): void
    {
        $service = new OnboardingChecklistService([
            $this->fakeCheck('z', 'B Group', 'Z Label', 5, OnboardingCheckResult::pass()),
            $this->fakeCheck('y', 'A Group', 'Y Label', 1, OnboardingCheckResult::pass()),
            $this->fakeCheck('x', 'A Group', 'X Label', 0, OnboardingCheckResult::pass()),
        ], new NullLogger());

        $labels = array_column($service->run(), 'label');

        self::assertSame(['X Label', 'Y Label', 'Z Label'], $labels);
    }

    /**
     * The interface's docblock says a check "must never throw", but a real check can still have
     * a bug or hit an unexpected environment (missing table, permission error). One broken check
     * must not blank the whole page for every other check.
     */
    public function testAThrowingCheckIsReportedAsFailedInsteadOfBreakingTheWholeList(): void
    {
        $service = new OnboardingChecklistService([
            $this->fakeCheck('ok_one', 'Group', 'OK One', 0, OnboardingCheckResult::pass()),
            $this->throwingCheck('broken_check'),
            $this->fakeCheck('ok_two', 'Group', 'OK Two', 0, OnboardingCheckResult::pass()),
        ], new NullLogger());

        $rows = $service->run();

        self::assertCount(3, $rows, 'all three checks must still appear, including the one that threw');

        $broken = array_values(array_filter($rows, static fn (array $r) => $r['key'] === 'broken_check'))[0];
        self::assertFalse($broken['passed']);
    }

    /**
     * A check's exception message can contain sensitive detail (a DSN, credentials, a stack
     * frame). The page-facing message for a check that threw must never echo that back — it
     * must be logged, not displayed, since this page is visible to every admin.
     */
    public function testAThrowingChecksExceptionMessageIsNeverShownOnThePage(): void
    {
        $service = new OnboardingChecklistService([$this->throwingCheck('broken_check')], new NullLogger());

        $rows = $service->run();

        self::assertStringNotContainsString('supersecret', $rows[0]['message']);
        self::assertStringNotContainsString('mailer.dsn', $rows[0]['message']);
    }

    public function testANonOnboardingCheckInstanceInTheIterableIsSkippedRatherThanCrashing(): void
    {
        // #[AutowireIterator] is typed to the interface so this should not happen in practice,
        // but the service iterates a plain `iterable` parameter at runtime with no enforced
        // type — a stray non-conforming value must be skipped defensively, not fatal the page.
        $service = new OnboardingChecklistService([
            $this->fakeCheck('ok', 'Group', 'OK', 0, OnboardingCheckResult::pass()),
            new \stdClass(),
        ], new NullLogger());

        $rows = $service->run();

        self::assertCount(1, $rows);
        self::assertSame('ok', $rows[0]['key']);
    }
}
