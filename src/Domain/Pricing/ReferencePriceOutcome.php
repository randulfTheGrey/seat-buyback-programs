<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use Brick\Math\BigDecimal;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferencePriceStatus;

final readonly class ReferencePriceOutcome
{
    private function __construct(
        public int $typeId,
        public ReferenceMode $logicalMode,
        public ReferencePriceStatus $status,
        public ?BigDecimal $referencePrice = null,
        public ?ReferencePriceProvenance $provenance = null,
    ) {
    }

    public static function priced(
        int $typeId,
        ReferenceMode $mode,
        BigDecimal $price,
        ReferencePriceProvenance $provenance,
    ): self {
        return new self($typeId, $mode, ReferencePriceStatus::PRICED, $price, $provenance);
    }

    public static function failed(
        int $typeId,
        ReferenceMode $mode,
        ReferencePriceStatus $status,
    ): self {
        return new self($typeId, $mode, $status);
    }
}
