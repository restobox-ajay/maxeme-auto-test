<?php

declare(strict_types=1);

namespace WooCommerceBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class WooCommerceBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
