<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;

final readonly class ReferencePriceProvenance
{
    /**
     * @param non-empty-list<ProviderPriceComponent> $components
     */
    public function __construct(
        public ReferenceMode $logicalMode,
        public ReferenceResolution $resolution,
        public array $components,
    ) {
    }
}
