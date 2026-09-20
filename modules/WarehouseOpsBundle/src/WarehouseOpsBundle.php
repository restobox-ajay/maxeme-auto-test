<?php

declare(strict_types=1);

namespace WarehouseOpsBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class WarehouseOpsBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
