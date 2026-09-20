<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Pins every process — the web front controller, bin/console, and the messenger worker all
     * construct this Kernel — to UTC before anything else runs. Storage must stay UTC forever
     * (see AppSettings::timezone() / DisplayTimezoneSubscriber for the read-side conversion), and
     * the only way to guarantee that for every bare `new \DateTimeImmutable()` across the app —
     * present and future, without auditing every entity — is to fix the ambient default here,
     * once, rather than at each of the dozens of call sites.
     */
    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);

        date_default_timezone_set('UTC');

        // Same argument as the timezone above: fix the ambient default once, here, rather than at
        // every call site — and for the same reason it cannot live in box config.
        //
        // -1 is PHP's own default since 7.1 and means "print the shortest string that reads back as
        // the identical double". Both cPanel boxes ship 100, which prints the exact binary expansion
        // instead, so every float in every JSON response comes out like this:
        //
        //     100  {"companyPrice":125.099999999999994315658113919198513031005859375}
        //     -1   {"companyPrice":125.1}
        //
        // It is the same number — 125.10 has no exact binary representation, so the long form IS the
        // value, and any conforming parser reads it back identically. Rounding cannot change it
        // (round($v, 2) === $v); only the printing was ever wrong. But the customer API publishes
        // money as JSON numbers (docs/api/openapi.yaml), and ~40 bytes per price instead of 5 is
        // both ugly to integrate against and wasteful.
        //
        // Set here, in application code, deliberately. The correct layer is the box's php.ini, but
        // this stack loses box config: the docroot's .user.ini is ignored because LiteSpeed's LSAPI
        // does not implement it (verified on dev), which leaves cPanel's per-account INI editor —
        // a GUI setting that no git bundle carries to the next instance. serialize_precision is
        // PHP_INI_ALL, so this reaches the web SAPI, bin/console and the messenger worker alike,
        // and travels with the code.
        ini_set('serialize_precision', '-1');
    }
}
