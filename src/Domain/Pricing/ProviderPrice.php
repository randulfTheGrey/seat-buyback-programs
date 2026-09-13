<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use Brick\Math\BigDecimal;

final readonly class ProviderPrice
{
    public function __construct(
        public int $providerInstanceId,
        public int $typeId,
        public BigDecimal $referencePrice,
    ) {
    }
}
