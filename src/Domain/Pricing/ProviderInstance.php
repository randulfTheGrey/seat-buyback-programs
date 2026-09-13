<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

final readonly class ProviderInstance
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
