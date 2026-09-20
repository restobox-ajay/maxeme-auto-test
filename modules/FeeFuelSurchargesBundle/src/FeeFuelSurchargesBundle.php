<?php

declare(strict_types=1);

namespace FeeFuelSurchargesBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class FeeFuelSurchargesBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
