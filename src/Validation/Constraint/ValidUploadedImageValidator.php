<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the three MIME/size checks ConfigController::brandingSettings() used to run by hand. */
final class ValidUploadedImageValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUploadedImage) {
            throw new UnexpectedTypeException($constraint, ValidUploadedImage::class);
        }

        if (!$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        $mime = $value->getMimeType();
        if (!in_array($mime, $constraint->allowedMimes, true)) {
            $this->context->buildViolation($constraint->invalidTypeMessage)->addViolation();
        } elseif ($value->getSize() > $constraint->maxBytes) {
            $this->context->buildViolation($constraint->tooLargeMessage)->addViolation();
        } elseif ($mime === 'image/svg+xml' && !self::isSafeSvg((string) @file_get_contents($value->getPathname()))) {
            $this->context->buildViolation($constraint->unsafeSvgMessage)->addViolation();
        }
    }

    /**
     * An SVG is a document, not a picture: opened on its own (the upload's public URL) it runs
     * whatever script it carries, on this site's origin. Anything that can run code or load
     * something from elsewhere is refused: script, event handlers, javascript: URLs, embedded
     * HTML, entity declarations (XXE / "billion laughs"), CSS @import, and links that are not
     * in-file (#id) or an embedded raster image. A plain logo export (Inkscape, Figma, or
     * Illustrator without "Preserve Illustrator Editing") contains none of these.
     */
    public static function isSafeSvg(string $svg): bool
    {
        if ($svg === '') {
            return false;
        }

        $forbidden = [
            '/<\s*script/i',
            '/<\s*(foreignObject|iframe|embed|object|audio|video|handler|listener)\b/i',
            '/\son[a-z]+\s*=/i',
            '/javascript\s*:/i',
            '/<!ENTITY/i',
            '/@import/i',
            '/\b(?:xlink:)?href\s*+=\s*+["\']?+\s*+(?!#|data:image\/(?:png|jpe?g|gif|webp)[;,])/i',
        ];
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $svg)) {
                return false;
            }
        }

        return true;
    }
}
