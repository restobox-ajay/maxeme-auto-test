<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\AbstractServiceLine;
use App\Maxeme\Entity\TaxClass;
use App\Maxeme\Enum\ServiceLineType;

/**
 * Which of the two sales taxes a line carries, from its Tax Class (Config › Settings › Tax Classes):
 * GST when the class has the GST rate, PST when it has the PST rate. A line without a class is taxed
 * both (as everything was before per-line tax classes).
 *
 *   service                  its service's class
 *   labour / govt fee line   its labour's / fee's class
 *   part line                both (products have no class)
 *   sublet / discount line   its service's class
 *   custom fee or discount, the invoice builder's discount, a standalone part: both
 *
 * The rates themselves stay the ones copied onto the document (its GST % and PST %).
 */
final class LineTax
{
    public const GST = 'GST';
    public const PST = 'PST';

    public function __construct(
        public readonly bool $gst = true,
        public readonly bool $pst = true,
    ) {
    }

    public static function standard(): self
    {
        return new self();
    }

    public static function of(?TaxClass $class): self
    {
        return $class === null ? self::standard() : new self($class->charges(self::GST), $class->charges(self::PST));
    }

    /** A service line's taxes; $service is the taxes of the service it is under. */
    public static function ofLine(AbstractServiceLine $line, self $service): self
    {
        return match ($line->getType()) {
            ServiceLineType::Labour => self::of($line->getLabour()?->getTaxClass()),
            ServiceLineType::GovtFee => self::of($line->getGovtFee()?->getTaxClass()),
            ServiceLineType::Part => self::standard(),
            ServiceLineType::Sublet, ServiceLineType::Discount => $service,
        };
    }

    public function isStandard(): bool
    {
        return $this->gst && $this->pst;
    }

    /** What a printed line says when it isn't taxed both: "GST only", "PST only", "Tax exempt"; null when it is. */
    public function note(): ?string
    {
        return match (true) {
            $this->gst && $this->pst => null,
            $this->gst => 'GST only',
            $this->pst => 'PST only',
            default => 'Tax exempt',
        };
    }

    /** @return array{gst: bool, pst: bool} for the pages' live totals */
    public function toArray(): array
    {
        return ['gst' => $this->gst, 'pst' => $this->pst];
    }
}
