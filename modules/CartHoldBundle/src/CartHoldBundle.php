<?php

declare(strict_types=1);

namespace CartHoldBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class CartHoldBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
