<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Pricing;

use Brick\Math\BigDecimal;
use Closure;
use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing\ReferencePriceCoordinator;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReference;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReferenceConfiguration;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceRequirement;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferencePriceStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;

final class ReferencePriceCoordinatorTest extends TestCase
{
    public function test_mixed_requirements_are_deduplicated_and_consolidated_by_instance(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            12 => [34 => '1', 35 => '2', 36 => '3', 37 => '4'],
        ]);

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
            $this->requirement(36, ReferenceMode::SELL),
            $this->requirement(37, ReferenceMode::SPLIT),
            $this->requirement(34, ReferenceMode::BUY),
        ], $this->derivedConfiguration(12, 12));

        self::assertSame([[12, [34, 35, 36, 37]]], $gateway->calls);
        self::assertCount(4, $result->all());
        self::assertSame('4', (string) $result->outcome(37, ReferenceMode::SPLIT)?->referencePrice);
    }

    public function test_dedicated_split_uses_only_its_opaque_provider(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            30 => [34 => '91.125'],
        ]);
        $configuration = new ProgramReferenceConfiguration([
            $this->providerReference(ReferenceMode::BUY, 10),
            $this->providerReference(ReferenceMode::SELL, 20),
            $this->providerReference(ReferenceMode::SPLIT, 30),
        ]);

        $outcome = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::SPLIT),
        ], $configuration)->outcome(34, ReferenceMode::SPLIT);

        self::assertSame([[30, [34]]], $gateway->calls);
        self::assertSame(ReferencePriceStatus::PRICED, $outcome?->status);
        self::assertSame('91.125', (string) $outcome?->referencePrice);
        self::assertSame(ReferenceResolution::PROVIDER, $outcome?->provenance?->resolution);
        self::assertSame('Provider 30', $outcome?->provenance?->components[0]->providerInstanceName);
    }

    public function test_dedicated_split_failure_never_falls_back_to_midpoint(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withCallback(
            static function (): array {
                throw new PriceProviderGatewayException('dedicated stream failed');
            },
        );
        $configuration = new ProgramReferenceConfiguration([
            $this->providerReference(ReferenceMode::BUY, 10),
            $this->providerReference(ReferenceMode::SELL, 20),
            $this->providerReference(ReferenceMode::SPLIT, 30),
        ]);

        $outcome = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::SPLIT),
        ], $configuration)->outcome(34, ReferenceMode::SPLIT);

        self::assertSame(ReferencePriceStatus::UNPRICED, $outcome?->status);
        self::assertSame([[30, [34]], [30, [34]]], $gateway->calls);
    }

    public function test_direct_prices_are_exact_with_identity_provenance_and_zero_is_priced(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            10 => [34 => '10.01', 35 => '0'],
        ]);

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
        ], $this->derivedConfiguration());
        $priced = $result->outcome(34, ReferenceMode::BUY);
        $zero = $result->outcome(35, ReferenceMode::BUY);

        self::assertSame('10.01', (string) $priced?->referencePrice);
        self::assertSame(10, $priced?->provenance?->components[0]->providerInstanceId);
        self::assertSame('Provider 10', $priced?->provenance?->components[0]->providerInstanceName);
        self::assertSame(ReferencePriceStatus::PRICED, $zero?->status);
        self::assertSame('0', (string) $zero?->referencePrice);
    }

    public function test_derived_split_retains_exact_midpoint_and_both_components(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            10 => [34 => '10.01'],
            20 => [34 => '10.02'],
        ]);

        $outcome = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::SPLIT),
        ], $this->derivedConfiguration())->outcome(34, ReferenceMode::SPLIT);

        self::assertSame('10.015', (string) $outcome?->referencePrice);
        self::assertSame(ReferenceResolution::DERIVED_MIDPOINT, $outcome?->provenance?->resolution);
        self::assertSame([ReferenceMode::BUY, ReferenceMode::SELL], array_map(
            static fn ($component) => $component->mode,
            $outcome?->provenance?->components ?? [],
        ));
        self::assertSame(['10.01', '10.02'], array_map(
            static fn ($component) => (string) $component->referencePrice,
            $outcome?->provenance?->components ?? [],
        ));
    }

    public function test_derived_split_never_uses_only_one_available_component(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            10 => [34 => '10.01'],
            20 => [],
        ]);

        $outcome = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::SPLIT),
        ], $this->derivedConfiguration())->outcome(34, ReferenceMode::SPLIT);

        self::assertSame(ReferencePriceStatus::UNPRICED, $outcome?->status);
        self::assertNull($outcome?->referencePrice);
    }

    public function test_bulk_success_makes_no_recovery_calls_and_missing_type_is_unpriced(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            10 => [34 => '1'],
        ]);

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
        ], $this->derivedConfiguration());

        self::assertSame([[10, [34, 35]]], $gateway->calls);
        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(34, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::UNPRICED, $result->outcome(35, ReferenceMode::BUY)?->status);
    }

    public function test_bulk_failure_recovers_types_and_isolates_nonconsecutive_failures(): void
    {
        $gateway = $this->recoveringGateway(
            10,
            [34, 35, 36, 37],
            [34 => '1.1', 35 => null, 36 => '3.3', 37 => null],
        );

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
            $this->requirement(36, ReferenceMode::BUY),
            $this->requirement(37, ReferenceMode::BUY),
        ], $this->derivedConfiguration());

        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(34, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::UNPRICED, $result->outcome(35, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(36, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::UNPRICED, $result->outcome(37, ReferenceMode::BUY)?->status);
        self::assertCount(5, $gateway->calls);
    }

    public function test_third_consecutive_failure_stops_recovery_and_invalidates_all_dependents(): void
    {
        $gateway = $this->recoveringGateway(
            10,
            [34, 35, 36, 37, 38],
            [34 => '1', 35 => null, 36 => null, 37 => null, 38 => '5'],
        );
        $requirements = array_map(
            fn (int $typeId) => $this->requirement($typeId, ReferenceMode::BUY),
            [34, 35, 36, 37, 38],
        );

        $result = (new ReferencePriceCoordinator($gateway))->price(
            $requirements,
            $this->derivedConfiguration(),
        );

        foreach ([34, 35, 36, 37, 38] as $typeId) {
            self::assertSame(
                ReferencePriceStatus::REFERENCE_UNAVAILABLE,
                $result->outcome($typeId, ReferenceMode::BUY)?->status,
            );
        }
        self::assertSame([
            [10, [34, 35, 36, 37, 38]],
            [10, [34]],
            [10, [35]],
            [10, [36]],
            [10, [37]],
        ], $gateway->calls);
    }

    public function test_shared_stream_failure_invalidates_every_logical_channel_using_it(): void
    {
        $gateway = $this->recoveringGateway(
            12,
            [34, 35, 36, 37, 38],
            [34 => '1', 35 => null, 36 => null, 37 => null, 38 => '5'],
        );
        $configuration = $this->derivedConfiguration(12, 12);

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::SELL),
            $this->requirement(36, ReferenceMode::BUY),
            $this->requirement(37, ReferenceMode::SELL),
            $this->requirement(38, ReferenceMode::SPLIT),
        ], $configuration);

        foreach ($result->all() as $outcome) {
            self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $outcome->status);
        }
        self::assertCount(5, $gateway->calls);
    }

    public function test_three_immediate_recovery_failures_mark_channel_unavailable(): void
    {
        $gateway = $this->recoveringGateway(
            10,
            [34, 35, 36, 37],
            [34 => null, 35 => null, 36 => null, 37 => '4'],
        );

        $result = (new ReferencePriceCoordinator($gateway))->price(array_map(
            fn (int $typeId) => $this->requirement($typeId, ReferenceMode::BUY),
            [34, 35, 36, 37],
        ), $this->derivedConfiguration());

        self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $result->outcome(34, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $result->outcome(37, ReferenceMode::BUY)?->status);
        self::assertCount(4, $gateway->calls);
    }

    public function test_recovery_failure_counter_resets_after_success(): void
    {
        $gateway = $this->recoveringGateway(
            10,
            [34, 35, 36, 37, 38],
            [34 => null, 35 => null, 36 => '3', 37 => null, 38 => null],
        );
        $requirements = array_map(
            fn (int $typeId) => $this->requirement($typeId, ReferenceMode::BUY),
            [34, 35, 36, 37, 38],
        );

        $result = (new ReferencePriceCoordinator($gateway))->price(
            $requirements,
            $this->derivedConfiguration(),
        );

        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(36, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::UNPRICED, $result->outcome(38, ReferenceMode::BUY)?->status);
        self::assertCount(6, $gateway->calls);
    }

    public function test_systemic_failure_in_one_channel_does_not_erase_another_channel(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withCallback(
            static function (int $providerId, array $typeIds): array {
                if ($providerId === 20) {
                    return [40 => new ProviderPrice(20, 40, BigDecimal::of('9.5'))];
                }

                throw new PriceProviderGatewayException('provider unavailable');
            },
        );
        $requirements = [
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
            $this->requirement(36, ReferenceMode::BUY),
            $this->requirement(37, ReferenceMode::BUY),
            $this->requirement(40, ReferenceMode::SELL),
            $this->requirement(41, ReferenceMode::SPLIT),
        ];

        $result = (new ReferencePriceCoordinator($gateway))->price(
            $requirements,
            $this->derivedConfiguration(),
        );

        self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $result->outcome(34, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(40, ReferenceMode::SELL)?->status);
        self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $result->outcome(41, ReferenceMode::SPLIT)?->status);
        self::assertSame('9.5', (string) $result->outcome(40, ReferenceMode::SELL)?->referencePrice);
    }

    public function test_sell_failure_does_not_erase_successful_buy_result(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withCallback(
            static function (int $providerId, array $typeIds): array {
                if ($providerId === 10) {
                    return [34 => new ProviderPrice(10, 34, BigDecimal::of('5'))];
                }

                throw new PriceProviderGatewayException('sell unavailable');
            },
        );
        $requirements = [
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(40, ReferenceMode::SELL),
            $this->requirement(41, ReferenceMode::SELL),
            $this->requirement(42, ReferenceMode::SELL),
            $this->requirement(43, ReferenceMode::SELL),
        ];

        $result = (new ReferencePriceCoordinator($gateway))->price(
            $requirements,
            $this->derivedConfiguration(),
        );

        self::assertSame(ReferencePriceStatus::PRICED, $result->outcome(34, ReferenceMode::BUY)?->status);
        self::assertSame(ReferencePriceStatus::REFERENCE_UNAVAILABLE, $result->outcome(40, ReferenceMode::SELL)?->status);
    }

    public function test_missing_provider_instance_is_misconfigured_but_unused_broken_channel_is_ignored(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withPrices([
            20 => [34 => '7'],
        ]);
        $gateway->instances[10] = null;

        $sellOnly = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::SELL),
        ], $this->derivedConfiguration());
        self::assertSame(ReferencePriceStatus::PRICED, $sellOnly->outcome(34, ReferenceMode::SELL)?->status);
        self::assertSame([[20, [34]]], $gateway->calls);

        $buy = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
        ], $this->derivedConfiguration());
        self::assertSame(ReferencePriceStatus::REFERENCE_MISCONFIGURED, $buy->outcome(34, ReferenceMode::BUY)?->status);
    }

    public function test_missing_buy_and_sell_provider_instances_are_distinct_misconfiguration_outcomes(): void
    {
        foreach ([ReferenceMode::BUY->value => 10, ReferenceMode::SELL->value => 20] as $modeValue => $providerId) {
            $mode = ReferenceMode::from($modeValue);
            $gateway = FakeSeatPriceProviderGateway::withPrices([]);
            $gateway->instances[$providerId] = null;

            $outcome = (new ReferencePriceCoordinator($gateway))->price([
                $this->requirement(34, $mode),
            ], $this->derivedConfiguration())->outcome(34, $mode);

            self::assertSame(ReferencePriceStatus::REFERENCE_MISCONFIGURED, $outcome?->status);
            self::assertSame([], $gateway->calls);
        }
    }

    public function test_missing_derived_component_configuration_is_misconfigured(): void
    {
        $configuration = new ProgramReferenceConfiguration([
            $this->providerReference(ReferenceMode::SELL, 20),
            new ProgramReference(ReferenceMode::SPLIT, ReferenceResolution::DERIVED_MIDPOINT, null),
        ]);

        $outcome = (new ReferencePriceCoordinator(FakeSeatPriceProviderGateway::withPrices([])))->price([
            $this->requirement(34, ReferenceMode::SPLIT),
        ], $configuration)->outcome(34, ReferenceMode::SPLIT);

        self::assertSame(ReferencePriceStatus::REFERENCE_MISCONFIGURED, $outcome?->status);
    }

    public function test_gateway_result_order_does_not_affect_mapping(): void
    {
        $gateway = FakeSeatPriceProviderGateway::withCallback(
            static fn (int $providerId, array $typeIds): array => [
                new ProviderPrice($providerId, 36, BigDecimal::of('3')),
                new ProviderPrice($providerId, 34, BigDecimal::of('1')),
                new ProviderPrice($providerId, 35, BigDecimal::of('2')),
            ],
        );

        $result = (new ReferencePriceCoordinator($gateway))->price([
            $this->requirement(34, ReferenceMode::BUY),
            $this->requirement(35, ReferenceMode::BUY),
            $this->requirement(36, ReferenceMode::BUY),
        ], $this->derivedConfiguration());

        self::assertSame('1', (string) $result->outcome(34, ReferenceMode::BUY)?->referencePrice);
        self::assertSame('2', (string) $result->outcome(35, ReferenceMode::BUY)?->referencePrice);
        self::assertSame('3', (string) $result->outcome(36, ReferenceMode::BUY)?->referencePrice);
    }

    private function requirement(int $typeId, ReferenceMode $mode): ReferencePriceRequirement
    {
        return new ReferencePriceRequirement($typeId, $mode);
    }

    private function providerReference(ReferenceMode $mode, int $providerId): ProgramReference
    {
        return new ProgramReference($mode, ReferenceResolution::PROVIDER, $providerId);
    }

    private function derivedConfiguration(int $buyProviderId = 10, int $sellProviderId = 20): ProgramReferenceConfiguration
    {
        return new ProgramReferenceConfiguration([
            $this->providerReference(ReferenceMode::BUY, $buyProviderId),
            $this->providerReference(ReferenceMode::SELL, $sellProviderId),
            new ProgramReference(ReferenceMode::SPLIT, ReferenceResolution::DERIVED_MIDPOINT, null),
        ]);
    }

    /** @param array<int, string|null> $recoveries */
    private function recoveringGateway(
        int $providerId,
        array $bulkTypeIds,
        array $recoveries,
    ): FakeSeatPriceProviderGateway {
        return FakeSeatPriceProviderGateway::withCallback(
            static function (int $actualProviderId, array $typeIds) use ($providerId, $bulkTypeIds, $recoveries): array {
                if ($actualProviderId !== $providerId) {
                    return [];
                }

                if ($typeIds === $bulkTypeIds) {
                    throw new PriceProviderGatewayException('bulk failed');
                }

                $price = $recoveries[$typeIds[0]] ?? null;

                return $price === null
                    ? []
                    : [$typeIds[0] => new ProviderPrice($providerId, $typeIds[0], BigDecimal::of($price))];
            },
        );
    }
}

final class FakeSeatPriceProviderGateway implements SeatPriceProviderGateway
{
    /** @var array<int, ProviderInstance|null> */
    public array $instances = [];

    /** @var list<array{int, list<int>}> */
    public array $calls = [];

    private function __construct(private readonly Closure $callback)
    {
        foreach ([10, 12, 20, 30] as $providerId) {
            $this->instances[$providerId] = new ProviderInstance($providerId, 'Provider ' . $providerId);
        }
    }

    /** @param array<int, array<int, string>> $prices */
    public static function withPrices(array $prices): self
    {
        return self::withCallback(
            static function (int $providerId, array $typeIds) use ($prices): array {
                $results = [];

                foreach ($typeIds as $typeId) {
                    if (! array_key_exists($typeId, $prices[$providerId] ?? [])) {
                        continue;
                    }

                    $results[$typeId] = new ProviderPrice(
                        $providerId,
                        $typeId,
                        BigDecimal::of($prices[$providerId][$typeId]),
                    );
                }

                return $results;
            },
        );
    }

    public static function withCallback(Closure $callback): self
    {
        return new self($callback);
    }

    public function providerInstance(int $providerInstanceId): ?ProviderInstance
    {
        if (array_key_exists($providerInstanceId, $this->instances)) {
            return $this->instances[$providerInstanceId];
        }

        return new ProviderInstance($providerInstanceId, 'Provider ' . $providerInstanceId);
    }

    public function getPrices(int $providerInstanceId, array $typeIds): array
    {
        sort($typeIds);
        $this->calls[] = [$providerInstanceId, $typeIds];

        return ($this->callback)($providerInstanceId, $typeIds);
    }
}
