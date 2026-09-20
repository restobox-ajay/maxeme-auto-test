<?php

declare(strict_types=1);

namespace FeeONFoodBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class FeeONFoodBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
