<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class CustomHeaderFooterBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
