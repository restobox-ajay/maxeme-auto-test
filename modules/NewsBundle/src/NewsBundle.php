<?php

declare(strict_types=1);

namespace NewsBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class NewsBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
