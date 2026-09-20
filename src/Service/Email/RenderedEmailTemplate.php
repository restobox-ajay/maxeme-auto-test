<?php

declare(strict_types=1);

namespace App\Service\Email;

/** A resolved template with its subject and body already rendered against a context. */
final readonly class RenderedEmailTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {}
}
