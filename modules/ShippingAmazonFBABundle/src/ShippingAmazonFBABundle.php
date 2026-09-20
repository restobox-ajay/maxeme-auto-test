<?php

declare(strict_types=1);

namespace ShippingAmazonFBABundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ShippingAmazonFBABundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
