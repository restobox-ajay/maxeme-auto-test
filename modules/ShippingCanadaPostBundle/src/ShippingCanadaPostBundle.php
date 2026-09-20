<?php

declare(strict_types=1);

namespace ShippingCanadaPostBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ShippingCanadaPostBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
