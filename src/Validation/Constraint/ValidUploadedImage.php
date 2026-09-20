<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of the three near-duplicate MIME/size checks
 * ConfigController::brandingSettings() ran by hand for the logo, favicon, and product-image-
 * placeholder uploads. The validated value is the submitted UploadedFile; the allowed MIME types,
 * size cap, and both messages are constraint options because the three call sites each differ
 * (favicon additionally allows .ico and has a smaller cap, and each names itself in its own
 * message) — unlike the other two, this is deliberately non-blocking at the call site: an invalid
 * upload is skipped with a flashed message while the rest of the form still saves, exactly as
 * brandingSettings() behaved before this migration.
 */
#[\Attribute]
final class ValidUploadedImage extends Constraint
{
    public function __construct(
        public readonly array $allowedMimes,
        public readonly int $maxBytes,
        public readonly string $invalidTypeMessage,
        public readonly string $tooLargeMessage,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
