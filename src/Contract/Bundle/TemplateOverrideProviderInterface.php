<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

interface TemplateOverrideProviderInterface
{
    public function getPoint(): string;

    public function getTemplate(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /**
     * Raw Twig source to render instead of loading getTemplate() from disk (e.g. an
     * admin-edited override stored in the database), or null to just use getTemplate().
     * Most providers return null here.
     */
    public function getTemplateSource(): ?string;
}
