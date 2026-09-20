<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the rules RedirectController::validateRequest() used to run against the raw
 * request before an entity existed; this runs the same rules against the entity after
 * RedirectController::applyRequest() has populated it. The DB-dependent checks (source path
 * uniqueness, redirect-loop detection) are why this is a service rather than a #[Assert\Callback] on
 * the entity itself — a Callback closure has no constructor and so no way to receive
 * RedirectRepository.
 */
final class ValidRedirectValidator extends ConstraintValidator
{
    public function __construct(
        private readonly RedirectRepository $redirectRepo,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRedirect) {
            throw new UnexpectedTypeException($constraint, ValidRedirect::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof Redirect) {
            throw new UnexpectedValueException($value, Redirect::class);
        }

        $this->validateSourcePath($value);
        $this->validateDestination($value);
    }

    private function validateSourcePath(Redirect $redirect): void
    {
        $sourcePath = $redirect->getSourcePath();

        if ($sourcePath === '') {
            $this->context->buildViolation('Source URL is required.')
                ->atPath('sourcePath')->addViolation();

            return;
        }

        if (!str_starts_with($sourcePath, '/')) {
            $this->context->buildViolation('Source URL must be a path starting with "/".')
                ->atPath('sourcePath')->addViolation();

            return;
        }

        if ($sourcePath === '/admin' || str_starts_with($sourcePath, '/admin/')) {
            $this->context->buildViolation('Source URL cannot start with /admin — admin paths are never checked for redirects.')
                ->atPath('sourcePath')->addViolation();

            return;
        }

        if (str_contains($sourcePath, '?') || str_contains($sourcePath, '#')) {
            $this->context->buildViolation('Source URL must be a path only, without a query string or fragment.')
                ->atPath('sourcePath')->addViolation();

            return;
        }

        if (strlen($sourcePath) > 500) {
            $this->context->buildViolation('Source URL is too long (500 characters max).')
                ->atPath('sourcePath')->addViolation();

            return;
        }

        $duplicate = $this->redirectRepo->findOneBySourcePath($sourcePath);
        if ($duplicate instanceof Redirect && $duplicate->getId() !== $redirect->getId()) {
            $this->context->buildViolation(sprintf('A redirect from "%s" already exists.', $sourcePath))
                ->atPath('sourcePath')->addViolation();
        }
    }

    private function validateDestination(Redirect $redirect): void
    {
        if ($redirect->getDestinationType() !== Redirect::DESTINATION_TYPE_URL) {
            if ($redirect->getDestinationType() === Redirect::DESTINATION_TYPE_CATEGORY && $redirect->getDestinationCategory() === null) {
                $this->context->buildViolation('A valid destination category is required.')
                    ->atPath('destinationCategory')->addViolation();
            }

            return;
        }

        $destinationUrl = $redirect->getDestinationUrl() ?? '';
        if ($destinationUrl === '') {
            $this->context->buildViolation('Destination URL is required.')
                ->atPath('destinationUrl')->addViolation();

            return;
        }

        if (!str_starts_with($destinationUrl, '/') && !str_starts_with($destinationUrl, 'http://') && !str_starts_with($destinationUrl, 'https://')) {
            $this->context->buildViolation('Destination URL must be a relative path starting with "/" or an absolute http(s) URL.')
                ->atPath('destinationUrl')->addViolation();

            return;
        }

        if (strlen($destinationUrl) > 2048) {
            $this->context->buildViolation('Destination URL is too long (2048 characters max).')
                ->atPath('destinationUrl')->addViolation();

            return;
        }

        $sourcePath = $redirect->getSourcePath();
        if ($sourcePath !== '' && self::destinationLoopsToSource($destinationUrl, $sourcePath)) {
            $this->context->buildViolation('Destination URL cannot point back at the source URL — that would create a redirect loop.')
                ->atPath('destinationUrl')->addViolation();
        }
    }

    /** Verbatim from RedirectController — see that copy for the reasoning behind path-only comparison. */
    private static function destinationLoopsToSource(string $destinationUrl, string $sourcePath): bool
    {
        if (!str_starts_with($destinationUrl, '/') || str_starts_with($destinationUrl, '//')) {
            return false;
        }

        return substr($destinationUrl, 0, strcspn($destinationUrl, '?#')) === $sourcePath;
    }
}
