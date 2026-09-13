<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Contracts;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;

interface SeatPriceProviderGateway
{
    /** @throws PriceProviderGatewayException */
    public function providerInstance(int $providerInstanceId): ?ProviderInstance;

    /**
     * @param list<int> $typeIds
     * @return array<int, ProviderPrice> prices keyed by type ID
     * @throws PriceProviderGatewayException
     */
    public function getPrices(int $providerInstanceId, array $typeIds): array;
}
