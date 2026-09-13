<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Pricing;

use Seat\Services\Contracts\IPriceable;

final class SeatPriceable implements IPriceable
{
    private ?float $price = null;

    public function __construct(private readonly int $typeId)
    {
    }

    public function getTypeID(): int
    {
        return $this->typeId;
    }

    public function getAmount(): int
    {
        return 1;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function price(): ?float
    {
        return $this->price;
    }
}
