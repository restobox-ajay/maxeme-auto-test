<?php

declare(strict_types=1);

namespace ShippingBulkBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ShippingBulkBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
