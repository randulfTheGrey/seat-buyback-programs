<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use Brick\Math\BigDecimal;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;

final readonly class ProviderPriceComponent
{
    public function __construct(
        public ReferenceMode $mode,
        public int $providerInstanceId,
        public string $providerInstanceName,
        public BigDecimal $referencePrice,
    ) {
    }
}
