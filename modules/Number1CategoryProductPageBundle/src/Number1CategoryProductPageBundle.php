<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Number1CategoryProductPageBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
