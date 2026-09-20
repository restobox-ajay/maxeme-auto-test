<?php

declare(strict_types=1);

namespace Number1RimImportBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Number1RimImportBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
