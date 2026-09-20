<?php

declare(strict_types=1);

namespace InventoryDepthBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class InventoryDepthBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
