<?php

declare(strict_types=1);

namespace AdminMenuBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class AdminMenuBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
