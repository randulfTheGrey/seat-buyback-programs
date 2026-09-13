<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Pricing;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use RecursiveTree\Seat\PricesCore\Contracts\IPriceProviderManager;
use RecursiveTree\Seat\PricesCore\Exceptions\PriceProviderException;
use RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance as SeatProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;
use Throwable;

final readonly class SeatPricesCoreGateway implements SeatPriceProviderGateway
{
    public function __construct(
        private IPriceProviderManager $manager,
        private LoggerInterface $logger,
    ) {
    }

    public function providerInstance(int $providerInstanceId): ?ProviderInstance
    {
        try {
            $instance = SeatProviderInstance::query()->find($providerInstanceId);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to resolve a configured seat-prices-core instance.', [
                'provider_instance_id' => $providerInstanceId,
                'exception' => $exception,
            ]);
            throw new PriceProviderGatewayException(
                'Unable to resolve the configured price-provider instance.',
                previous: $exception,
            );
        }

        if ($instance === null) {
            return null;
        }

        return new ProviderInstance((int) $instance->getKey(), (string) $instance->name);
    }

    public function getPrices(int $providerInstanceId, array $typeIds): array
    {
        $priceables = [];

        foreach (array_values(array_unique($typeIds)) as $typeId) {
            $priceables[] = new SeatPriceable($typeId);
        }

        try {
            $this->manager->getPrices($providerInstanceId, new Collection($priceables));
        } catch (PriceProviderException $exception) {
            $this->logger->warning('A seat-prices-core request failed.', [
                'provider_instance_id' => $providerInstanceId,
                'type_ids' => $typeIds,
                'exception' => $exception,
            ]);
            throw new PriceProviderGatewayException(
                'The configured price-provider instance could not price the request.',
                previous: $exception,
            );
        }

        $prices = [];

        foreach ($priceables as $priceable) {
            if ($priceable->price() === null) {
                continue;
            }

            $prices[$priceable->getTypeID()] = new ProviderPrice(
                $providerInstanceId,
                $priceable->getTypeID(),
                BigDecimal::of((string) $priceable->price()),
            );
        }

        return $prices;
    }
}
