<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

use Brick\Math\BigDecimal;

final readonly class CalculatedBuybackPrice
{
    public function __construct(
        public BigDecimal $rawFinalUnitPrice,
        public BigDecimal $finalUnitPrice,
        public BigDecimal $lineTotal,
    ) {
    }
}
