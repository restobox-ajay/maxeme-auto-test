<?php

declare(strict_types=1);

namespace Number1CustomerImportBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Number1CustomerImportBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
