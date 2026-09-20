<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every registered OnboardingCheckInterface and returns them ready to render, per #426:
 * "each item done is green, not done is red with an explanation ... not done items float on top".
 *
 * Adding a check is registering nothing here — see OnboardingCheckInterface's docblock for the
 * extensibility mechanism. This class only orders and defensively runs whatever the container
 * hands it.
 */
final class OnboardingChecklistService
{
    /** @param iterable<OnboardingCheckInterface> $checks */
    public function __construct(
        #[AutowireIterator('app.onboarding_check')]
        private readonly iterable $checks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{key: string, group: string, label: string, passed: bool, message: string}>
     *         Failed checks first, then passed checks — each half ordered by group, then
     *         getSortOrder(), then label, so rendering never depends on container iteration order.
     */
    public function run(): array
    {
        $rows = [];

        foreach ($this->checks as $check) {
            if (!$check instanceof OnboardingCheckInterface) {
                continue;
            }

            $rows[] = [
                'key'       => $check->getKey(),
                'group'     => $check->getGroup(),
                'label'     => $check->getLabel(),
                'sortOrder' => $check->getSortOrder(),
                'result'    => $this->runOneSafely($check),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            // Failed (not done) always floats above passed, regardless of group.
            $passedCmp = ($a['result']->passed ? 1 : 0) <=> ($b['result']->passed ? 1 : 0);
            if ($passedCmp !== 0) {
                return $passedCmp;
            }

            return [$a['group'], $a['sortOrder'], $a['label']] <=> [$b['group'], $b['sortOrder'], $b['label']];
        });

        return array_map(static fn (array $row): array => [
            'key'     => $row['key'],
            'group'   => $row['group'],
            'label'   => $row['label'],
            'passed'  => $row['result']->passed,
            'message' => $row['result']->message,
        ], $rows);
    }

    /**
     * A single misbehaving check (a bug, a missing table on a fresh install, a stale mock) must
     * never blank the whole onboarding page for every other check alongside it — report it as
     * failed with a generic message instead of letting the exception propagate. The real
     * exception is still logged so it isn't invisible to whoever maintains this check.
     */
    private function runOneSafely(OnboardingCheckInterface $check): OnboardingCheckResult
    {
        try {
            return $check->run();
        } catch (\Throwable $e) {
            $this->logger->error('Onboarding check "{key}" threw during run(): {message}', [
                'key' => $check->getKey(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            // The exception itself is logged above, not shown here: this page is visible to every
            // admin (not just Tech Support), and an exception message can carry a DSN, a file
            // path, or other detail that has no business being on a checklist screen.
            return OnboardingCheckResult::fail('This check could not run. See the error log for details.');
        }
    }
}
