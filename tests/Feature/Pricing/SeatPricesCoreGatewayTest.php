<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use RecursiveTree\Seat\PricesCore\Contracts\IPriceProviderManager;
use RecursiveTree\Seat\PricesCore\Exceptions\PriceProviderException;
use Seat\Services\Contracts\IPriceable;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Pricing\SeatPriceable;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Pricing\SeatPricesCoreGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class SeatPricesCoreGatewayTest extends TestCase
{
    public function test_buyback_adapter_satisfies_current_ipriceable_contract(): void
    {
        $priceable = new SeatPriceable(34);

        self::assertInstanceOf(IPriceable::class, $priceable);
        self::assertSame(34, $priceable->getTypeID());
        self::assertSame(1, $priceable->getAmount());
        self::assertNull($priceable->price());

        $priceable->setPrice(10.015);

        self::assertSame(10.015, $priceable->price());
    }

    public function test_gateway_passes_instance_and_collection_then_reads_mutated_prices(): void
    {
        $manager = new RecordingPriceProviderManager(
            static function (Collection $items): void {
                foreach ($items as $item) {
                    $item->setPrice($item->getTypeID() === 34 ? 10.01 : 0.0);
                }
            },
        );

        $prices = (new SeatPricesCoreGateway($manager, new NullLogger()))->getPrices(77, [35, 34, 35]);

        self::assertSame(77, $manager->providerInstanceId);
        self::assertSame([35, 34], $manager->typeIds);
        self::assertSame([1, 1], $manager->amounts);
        self::assertSame('10.01', (string) $prices[34]->referencePrice);
        self::assertSame('0', (string) $prices[35]->referencePrice);
    }

    public function test_unmutated_priceable_is_returned_as_missing_not_zero(): void
    {
        $gateway = new SeatPricesCoreGateway(new RecordingPriceProviderManager(
            static function (Collection $items): void {
                $items->first()->setPrice(0.0);
            },
        ), new NullLogger());

        $prices = $gateway->getPrices(77, [34, 35]);

        self::assertArrayHasKey(34, $prices);
        self::assertSame('0', (string) $prices[34]->referencePrice);
        self::assertArrayNotHasKey(35, $prices);
    }

    public function test_core_provider_exception_is_normalized(): void
    {
        $gateway = new SeatPricesCoreGateway(new RecordingPriceProviderManager(
            static function (): void {
                throw new PriceProviderException('raw upstream detail');
            },
        ), new NullLogger());

        try {
            $gateway->getPrices(77, [34]);
            self::fail('Expected a normalized gateway exception.');
        } catch (PriceProviderGatewayException $exception) {
            self::assertStringNotContainsString('raw upstream detail', $exception->getMessage());
            self::assertInstanceOf(PriceProviderException::class, $exception->getPrevious());
        }
    }

    public function test_provider_instance_identity_is_resolved_without_backend_details(): void
    {
        Schema::create('price_provider_instances', static function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('backend');
            $table->json('configuration');
        });
        $id = DB::table('price_provider_instances')->insertGetId([
            'name' => 'Jita buy reference',
            'backend' => 'opaque-backend',
            'configuration' => '{}',
        ]);
        $gateway = new SeatPricesCoreGateway(
            new RecordingPriceProviderManager(static fn () => null),
            new NullLogger(),
        );

        $identity = $gateway->providerInstance($id);

        self::assertSame($id, $identity?->id);
        self::assertSame('Jita buy reference', $identity?->name);
        self::assertNull($gateway->providerInstance($id + 1));
    }
}

final class RecordingPriceProviderManager implements IPriceProviderManager
{
    public ?int $providerInstanceId = null;

    /** @var list<int> */
    public array $typeIds = [];

    /** @var list<int> */
    public array $amounts = [];

    public function __construct(private readonly \Closure $callback)
    {
    }

    public function getPrices(int $instance_id, Collection $items): void
    {
        $this->providerInstanceId = $instance_id;
        $this->typeIds = $items->map(static fn (IPriceable $item): int => $item->getTypeID())->values()->all();
        $this->amounts = $items->map(static fn (IPriceable $item): int => $item->getAmount())->values()->all();

        ($this->callback)($items);
    }
}
