<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #314's port of GuestCoverPageController::validate()'s two save-blocking checks: an empty
 * source, and a sandboxed render failure. The heuristic warnings (missing _username/_password
 * fields, missing csrf_token()/customer_login path calls) stay hand-rolled in the controller —
 * they never block a save, so they aren't validity checks in the sense this component models.
 * The sample render context is carried as a constraint option, following
 * ValidUserDefinedFeeRequest's #313 taxClasses option, since it's only ever known to the
 * controller that also uses it to render the reference variable list above the editor.
 */
#[\Attribute]
final class ValidGuestCoverPageTemplateSource extends Constraint
{
    public function __construct(
        public readonly array $sampleContext,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
