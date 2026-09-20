<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Twig\SandboxedTemplateRenderer;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Twig\Error\Error as TwigError;

/**
 * Line-for-line port of the checks GuestCoverPageController::validate() used to run by hand.
 * Renders with the app.user global forced to null, same as — and for the same reason as —
 * GuestCoverPageController::renderAsGuest(): both callers run under the admin firewall, so
 * app.user would otherwise resolve to the current AdminUser, which crashes against
 * customer/_main/layout.html.twig's CustomerUser assumptions.
 */
final class ValidGuestCoverPageTemplateSourceValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SandboxedTemplateRenderer $templateRenderer,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidGuestCoverPageTemplateSource) {
            throw new UnexpectedTypeException($constraint, ValidGuestCoverPageTemplateSource::class);
        }

        $source = (string) $value;

        if (trim($source) === '') {
            $this->context->buildViolation('Template source cannot be empty.')->addViolation();

            return;
        }

        $originalToken = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(null);

        try {
            $this->templateRenderer->render($source, $constraint->sampleContext);
        } catch (TwigError $e) {
            $this->context->buildViolation('Twig error on line ' . $e->getTemplateLine() . ': ' . $e->getRawMessage())->addViolation();
        } finally {
            $this->tokenStorage->setToken($originalToken);
        }
    }
}
