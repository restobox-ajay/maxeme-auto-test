<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Number1GuestCoverPageBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
