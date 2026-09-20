<?php

declare(strict_types=1);

namespace FeeABFoodBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class FeeABFoodBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
