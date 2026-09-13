<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing;

use Brick\Math\BigDecimal;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReference;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReferenceConfiguration;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPriceComponent;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceProvenance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceRequirement;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePricingResult;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferencePriceStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;

final readonly class ReferencePriceCoordinator
{
    private const RECOVERY_FAILURE_LIMIT = 3;

    public function __construct(private SeatPriceProviderGateway $gateway)
    {
    }

    /** @param iterable<ReferencePriceRequirement> $requirements */
    public function price(
        iterable $requirements,
        ProgramReferenceConfiguration $configuration,
    ): ReferencePricingResult {
        $requirements = $this->normalizeRequirements($requirements);
        $outcomes = [];
        $requirementComponents = [];
        $channelNeeds = [];
        $channelReferences = [];

        foreach ($requirements as $key => $requirement) {
            if ($requirement->mode !== ReferenceMode::SPLIT) {
                $reference = $this->directReference($configuration, $requirement->mode);

                if ($reference === null) {
                    $outcomes[$key] = $this->failure($requirement, ReferencePriceStatus::REFERENCE_MISCONFIGURED);
                    continue;
                }

                $channelReferences[$requirement->mode->value] = $reference;
                $channelNeeds[$requirement->mode->value][$requirement->typeId] = true;
                $requirementComponents[$key] = [$requirement->mode];
                continue;
            }

            $split = $configuration->get(ReferenceMode::SPLIT);

            if ($split === null) {
                $outcomes[$key] = $this->failure($requirement, ReferencePriceStatus::REFERENCE_MISCONFIGURED);
                continue;
            }

            if ($split->resolution === ReferenceResolution::PROVIDER) {
                $reference = $this->validProviderReference($split);

                if ($reference === null) {
                    $outcomes[$key] = $this->failure($requirement, ReferencePriceStatus::REFERENCE_MISCONFIGURED);
                    continue;
                }

                $channelReferences[ReferenceMode::SPLIT->value] = $reference;
                $channelNeeds[ReferenceMode::SPLIT->value][$requirement->typeId] = true;
                $requirementComponents[$key] = [ReferenceMode::SPLIT];
                continue;
            }

            if ($split->resolution !== ReferenceResolution::DERIVED_MIDPOINT) {
                $outcomes[$key] = $this->failure($requirement, ReferencePriceStatus::REFERENCE_MISCONFIGURED);
                continue;
            }

            $buy = $this->directReference($configuration, ReferenceMode::BUY);
            $sell = $this->directReference($configuration, ReferenceMode::SELL);

            if ($buy === null || $sell === null) {
                $outcomes[$key] = $this->failure($requirement, ReferencePriceStatus::REFERENCE_MISCONFIGURED);
                continue;
            }

            $channelReferences[ReferenceMode::BUY->value] = $buy;
            $channelReferences[ReferenceMode::SELL->value] = $sell;
            $channelNeeds[ReferenceMode::BUY->value][$requirement->typeId] = true;
            $channelNeeds[ReferenceMode::SELL->value][$requirement->typeId] = true;
            $requirementComponents[$key] = [ReferenceMode::BUY, ReferenceMode::SELL];
        }

        $streams = $this->buildStreams($channelNeeds, $channelReferences);
        $streamResults = $this->priceStreams($streams);

        foreach ($requirements as $key => $requirement) {
            if (isset($outcomes[$key])) {
                continue;
            }

            $components = [];
            $failureStatuses = [];

            foreach ($requirementComponents[$key] as $componentMode) {
                $reference = $channelReferences[$componentMode->value];
                $stream = $streamResults[$reference->providerInstanceId];

                if ($stream['status'] !== null) {
                    $failureStatuses[] = $stream['status'];
                    continue;
                }

                $providerPrice = $stream['prices'][$requirement->typeId] ?? null;

                if ($providerPrice === null) {
                    $failureStatuses[] = ReferencePriceStatus::UNPRICED;
                    continue;
                }

                $components[] = new ProviderPriceComponent(
                    $componentMode,
                    $stream['instance']->id,
                    $stream['instance']->name,
                    $providerPrice->referencePrice,
                );
            }

            if ($failureStatuses !== []) {
                $outcomes[$key] = $this->failure(
                    $requirement,
                    $this->mostSpecificFailure($failureStatuses),
                );
                continue;
            }

            if ($requirement->mode === ReferenceMode::SPLIT && count($components) === 2) {
                $price = $components[0]->referencePrice
                    ->plus($components[1]->referencePrice)
                    ->exactlyDividedBy(BigDecimal::of(2));
                $resolution = ReferenceResolution::DERIVED_MIDPOINT;
            } else {
                $price = $components[0]->referencePrice;
                $resolution = ReferenceResolution::PROVIDER;
            }

            $outcomes[$key] = ReferencePriceOutcome::priced(
                $requirement->typeId,
                $requirement->mode,
                $price,
                new ReferencePriceProvenance($requirement->mode, $resolution, $components),
            );
        }

        return new ReferencePricingResult($outcomes);
    }

    /**
     * @param iterable<ReferencePriceRequirement> $requirements
     * @return array<string, ReferencePriceRequirement>
     */
    private function normalizeRequirements(iterable $requirements): array
    {
        $normalized = [];

        foreach ($requirements as $requirement) {
            $normalized[$requirement->key()] = $requirement;
        }

        ksort($normalized);

        return $normalized;
    }

    private function directReference(
        ProgramReferenceConfiguration $configuration,
        ReferenceMode $mode,
    ): ?ProgramReference {
        $reference = $configuration->get($mode);

        if ($reference === null || $reference->resolution !== ReferenceResolution::PROVIDER) {
            return null;
        }

        return $this->validProviderReference($reference);
    }

    private function validProviderReference(ProgramReference $reference): ?ProgramReference
    {
        if ($reference->providerInstanceId === null || $reference->providerInstanceId <= 0) {
            return null;
        }

        return $reference;
    }

    /**
     * @param array<string, array<int, true>> $channelNeeds
     * @param array<string, ProgramReference> $channelReferences
     * @return array<int, array{channels: array<string, true>, types: array<int, true>}>
     */
    private function buildStreams(array $channelNeeds, array $channelReferences): array
    {
        $streams = [];

        foreach ($channelNeeds as $channel => $types) {
            $providerId = $channelReferences[$channel]->providerInstanceId;
            $streams[$providerId]['channels'][$channel] = true;

            foreach ($types as $typeId => $_) {
                $streams[$providerId]['types'][$typeId] = true;
            }
        }

        ksort($streams);

        foreach ($streams as &$stream) {
            ksort($stream['types']);
        }
        unset($stream);

        return $streams;
    }

    /**
     * @param array<int, array{channels: array<string, true>, types: array<int, true>}> $streams
     * @return array<int, array{instance: ?\RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance, status: ?ReferencePriceStatus, prices: array<int, ProviderPrice>}>
     */
    private function priceStreams(array $streams): array
    {
        $results = [];

        foreach ($streams as $providerId => $stream) {
            $typeIds = array_keys($stream['types']);

            try {
                $instance = $this->gateway->providerInstance($providerId);
            } catch (PriceProviderGatewayException) {
                $results[$providerId] = [
                    'instance' => null,
                    'status' => ReferencePriceStatus::REFERENCE_UNAVAILABLE,
                    'prices' => [],
                ];
                continue;
            }

            if ($instance === null) {
                $results[$providerId] = [
                    'instance' => null,
                    'status' => ReferencePriceStatus::REFERENCE_MISCONFIGURED,
                    'prices' => [],
                ];
                continue;
            }

            try {
                $prices = $this->normalizeProviderPrices(
                    $providerId,
                    $typeIds,
                    $this->gateway->getPrices($providerId, $typeIds),
                );
                $results[$providerId] = [
                    'instance' => $instance,
                    'status' => null,
                    'prices' => $prices,
                ];
            } catch (PriceProviderGatewayException) {
                $results[$providerId] = $this->recoverStream($providerId, $instance, $typeIds);
            }
        }

        return $results;
    }

    /**
     * @param list<int> $typeIds
     * @return array{instance: \RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance, status: ?ReferencePriceStatus, prices: array<int, ProviderPrice>}
     */
    private function recoverStream(int $providerId, ProviderInstance $instance, array $typeIds): array
    {
        $prices = [];
        $consecutiveFailures = 0;

        foreach ($typeIds as $typeId) {
            try {
                $recovered = $this->normalizeProviderPrices(
                    $providerId,
                    [$typeId],
                    $this->gateway->getPrices($providerId, [$typeId]),
                );
            } catch (PriceProviderGatewayException) {
                $recovered = [];
            }

            if (isset($recovered[$typeId])) {
                $prices[$typeId] = $recovered[$typeId];
                $consecutiveFailures = 0;
                continue;
            }

            $consecutiveFailures++;

            if ($consecutiveFailures === self::RECOVERY_FAILURE_LIMIT) {
                return [
                    'instance' => $instance,
                    'status' => ReferencePriceStatus::REFERENCE_UNAVAILABLE,
                    'prices' => [],
                ];
            }
        }

        return [
            'instance' => $instance,
            'status' => null,
            'prices' => $prices,
        ];
    }

    /**
     * @param list<int> $requestedTypeIds
     * @param array<int, ProviderPrice> $prices
     * @return array<int, ProviderPrice>
     */
    private function normalizeProviderPrices(int $providerId, array $requestedTypeIds, array $prices): array
    {
        $requested = array_fill_keys($requestedTypeIds, true);
        $normalized = [];

        foreach ($prices as $price) {
            if (
                $price->providerInstanceId !== $providerId
                || ! isset($requested[$price->typeId])
            ) {
                continue;
            }

            $normalized[$price->typeId] = $price;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param list<ReferencePriceStatus> $statuses */
    private function mostSpecificFailure(array $statuses): ReferencePriceStatus
    {
        if (in_array(ReferencePriceStatus::REFERENCE_MISCONFIGURED, $statuses, true)) {
            return ReferencePriceStatus::REFERENCE_MISCONFIGURED;
        }

        if (in_array(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $statuses, true)) {
            return ReferencePriceStatus::REFERENCE_UNAVAILABLE;
        }

        return ReferencePriceStatus::UNPRICED;
    }

    private function failure(
        ReferencePriceRequirement $requirement,
        ReferencePriceStatus $status,
    ): ReferencePriceOutcome {
        return ReferencePriceOutcome::failed($requirement->typeId, $requirement->mode, $status);
    }
}
