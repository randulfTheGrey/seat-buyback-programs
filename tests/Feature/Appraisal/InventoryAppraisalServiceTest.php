<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Appraisal;

use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal\InventoryAppraisalService;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalLimitExceededException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPricingConfigurationException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionReferenceDataUnavailableException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\PriceProviderGatewayException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForAppraisalException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMetadata;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class InventoryAppraisalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        Carbon::setTestNow('2026-09-11 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');

        Schema::create('invTypes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('typeID')->primary();
            $table->unsignedBigInteger('groupID');
            $table->string('typeName');
            $table->boolean('published');
        });
        DB::table('invTypes')->insert([
            ['typeID' => 34, 'groupID' => 18, 'typeName' => 'Tritanium', 'published' => true],
            ['typeID' => 35, 'groupID' => 18, 'typeName' => 'Pyerite', 'published' => true],
            ['typeID' => 36, 'groupID' => 20, 'typeName' => 'Mexallon', 'published' => true],
            ['typeID' => 37, 'groupID' => 21, 'typeName' => 'Isogen', 'published' => true],
            ['typeID' => 38, 'groupID' => 22, 'typeName' => 'Nocxium', 'published' => true],
            ['typeID' => 634, 'groupID' => 18, 'typeName' => 'Compressed Tritanium', 'published' => true],
            ['typeID' => 1230, 'groupID' => 465, 'typeName' => 'Compressed Veldspar', 'published' => true],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_complete_pipeline_merges_duplicates_preserves_mixed_outcomes_and_caches_exact_offer(): void
    {
        $this->usableCompressionData();
        $program = $this->program();
        $program->rules()->createMany([
            $this->rule(
                RuleTargetType::GROUP,
                20,
                acceptance: RuleAcceptance::REJECT,
            ),
            $this->rule(
                RuleTargetType::TYPE,
                34,
                referenceMode: ReferenceMode::SELL,
                modifierOperation: ModifierOperation::REPLACE,
                modifierBps: -1000,
            ),
        ]);
        $gateway = PipelineFakePriceGateway::withPrices([
            20 => [34 => '10003.17'],
            10 => [],
        ]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $completed = $this->service()->appraise($program, 42, implode("\n", [
            "Tritanium\t100",
            'Tritanium 250',
            'Pyerite 2',
            'Mexallon 3',
            'Mystery Goo 4',
            'BrokenLine',
            'Nocxium 0',
        ]));

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $completed->token);
        self::assertSame('3150997.50', $completed->result->quoteableTotal);
        self::assertSame('2026-09-11T12:00:00+00:00', $completed->result->pricedAt->toIso8601String());
        self::assertSame('2026-09-11T13:00:00+00:00', $completed->result->quoteExpiresAt->toIso8601String());
        self::assertSame([
            AppraisalLineStatus::PRICED,
            AppraisalLineStatus::UNPRICED,
            AppraisalLineStatus::EXCLUDED,
            AppraisalLineStatus::UNKNOWN,
            AppraisalLineStatus::INVALID_INPUT,
            AppraisalLineStatus::INVALID_INPUT,
        ], array_map(static fn ($line): AppraisalLineStatus => $line->status, $completed->result->lines));

        $tritanium = $completed->result->lines[0];
        self::assertSame(34, $tritanium->typeId);
        self::assertSame(350, $tritanium->quantity);
        self::assertCount(2, $tritanium->rawLines);
        self::assertSame(CompressionState::UNCOMPRESSED, $tritanium->compressionState);
        self::assertSame(ReferenceMode::SELL, $tritanium->logicalReferenceMode);
        self::assertSame(-1000, $tritanium->effectiveModifierBps);
        self::assertSame('10003.17', $tritanium->referenceUnitPrice);
        self::assertSame('9002.85', $tritanium->finalUnitPrice);
        self::assertSame('3150997.50', $tritanium->lineTotal);
        self::assertSame('Provider 20', $tritanium->pricingProvenance['components'][0]['provider_instance_name']);
        self::assertSame('PROGRAM_DEFAULTS', $tritanium->effectivePolicy['baseline']['source']);
        self::assertSame(1, $tritanium->effectivePolicy['schema_version']);
        self::assertSame(34, $tritanium->effectivePolicy['layers'][0]['target_id']);

        self::assertSame([[10, [35]], [20, [34]]], $gateway->calls);
        self::assertNotContains(36, array_merge(...array_column($gateway->calls, 1)));
        self::assertSame(1, $completed->result->summaryCounts()['PRICED']);
        self::assertSame(1, $completed->result->summaryCounts()['EXCLUDED']);
        self::assertSame(0, $completed->result->summaryCounts()['REFERENCE_UNAVAILABLE']);

        $stored = $this->app->make(AppraisalStore::class)
            ->findForRequester($completed->token, 42);
        self::assertNotNull($stored);
        self::assertSame('3150997.50', $stored->result->quoteableTotal);
        self::assertNull($this->app->make(AppraisalStore::class)->findForRequester($completed->token, 99));

        self::assertDatabaseCount('buyback_quotes', 0);
        self::assertDatabaseCount('buyback_quote_items', 0);
        self::assertDatabaseCount('buyback_requests', 0);

        $queries = DB::getQueryLog();
        self::assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from "invTypes"'),
        ));
        self::assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from "buyback_rules"'),
        ));
    }

    public function test_case_variants_resolve_to_canonical_type_and_follow_existing_duplicate_merging(): void
    {
        $program = $this->program();
        $gateway = PipelineFakePriceGateway::withPrices([10 => [1230 => '2']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $result = $this->service()->appraise($program, 42, implode("\n", [
            'Compressed Veldspar 100',
            'compressed veldspar 200',
            'COMPRESSED VELDSPAR 300',
            'cOmPrEsSeD vElDsPaR 400',
            'Compressed Veldspa 5',
            'Veldspar 6',
        ]))->result;

        self::assertSame([
            AppraisalLineStatus::PRICED,
            AppraisalLineStatus::UNKNOWN,
            AppraisalLineStatus::UNKNOWN,
        ], array_column($result->lines, 'status'));

        $resolved = $result->lines[0];
        self::assertSame(1230, $resolved->typeId);
        self::assertSame('Compressed Veldspar', $resolved->typeName);
        self::assertSame(1000, $resolved->quantity);
        self::assertCount(4, $resolved->rawLines);
        self::assertSame('2000.00', $resolved->lineTotal);
        self::assertSame([[10, [1230]]], $gateway->calls);
        self::assertSame('Compressed Veldspa', $result->lines[1]->candidateName);
        self::assertSame('Veldspar', $result->lines[2]->candidateName);
    }

    public function test_missing_compression_data_blocks_only_programs_with_qualified_rules(): void
    {
        $programWithoutQualifiedRules = $this->program('No qualified rules');
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '1']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $completed = $this->service()->appraise($programWithoutQualifiedRules, 42, 'Tritanium 2');

        self::assertSame(CompressionState::NOT_APPLICABLE, $completed->result->lines[0]->compressionState);
        self::assertSame(AppraisalLineStatus::PRICED, $completed->result->lines[0]->status);

        $programWithQualifiedRule = $this->program('Qualified rules');
        $programWithQualifiedRule->rules()->create($this->rule(
            RuleTargetType::GROUP,
            18,
            qualifier: CompressionQualifier::COMPRESSED,
            acceptance: RuleAcceptance::REJECT,
        ));

        try {
            $this->service()->appraise($programWithQualifiedRule, 42, 'Tritanium 2');
            self::fail('A Program requiring missing compression data should fail.');
        } catch (CompressionReferenceDataUnavailableException) {
            self::assertSame([[10, [34]]], $gateway->calls);
        }
    }

    public function test_stale_usable_compression_data_propagates_all_states_and_warning_into_policy(): void
    {
        $this->usableCompressionData(stale: true);
        $program = $this->program();
        $program->rules()->createMany([
            $this->rule(
                RuleTargetType::TYPE,
                34,
                referenceMode: ReferenceMode::SELL,
            ),
            $this->rule(
                RuleTargetType::TYPE,
                634,
                referenceMode: ReferenceMode::SPLIT,
            ),
        ]);
        $gateway = PipelineFakePriceGateway::withPrices([
            10 => [35 => '3', 634 => '2'],
            20 => [34 => '4', 634 => '4'],
        ]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $result = $this->service()
            ->appraise($program, 42, "Tritanium 1\nCompressed Tritanium 1\nPyerite 1")
            ->result;

        self::assertSame(['COMPRESSION_DATA_STALE'], $result->warnings);
        self::assertSame([
            CompressionState::UNCOMPRESSED,
            CompressionState::COMPRESSED,
            CompressionState::NOT_APPLICABLE,
        ], array_map(static fn ($line): ?CompressionState => $line->compressionState, $result->lines));
        self::assertSame([
            ReferenceMode::SELL,
            ReferenceMode::SPLIT,
            ReferenceMode::BUY,
        ], array_map(static fn ($line): ?ReferenceMode => $line->logicalReferenceMode, $result->lines));
        self::assertSame('3', $result->lines[1]->referenceUnitPrice);
        self::assertSame('DERIVED_MIDPOINT', $result->lines[1]->referenceResolution?->value);
    }

    public function test_default_global_rejection_excludes_unmatched_item_before_any_pricing_call(): void
    {
        $program = $this->program(defaultAcceptance: Acceptance::REJECT);
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '100']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $line = $this->service()->appraise($program, 42, 'Tritanium 1')->result->lines[0];

        self::assertSame(AppraisalLineStatus::EXCLUDED, $line->status);
        self::assertSame('REJECT', $line->effectivePolicy['baseline']['acceptance']['value']);
        self::assertSame([], $gateway->calls);
    }

    public function test_production_policy_evaluator_can_reaccept_after_compression_qualified_group_rejection(): void
    {
        $this->usableCompressionData();
        $program = $this->program();
        $program->rules()->createMany([
            $this->rule(
                RuleTargetType::GROUP,
                18,
                referenceMode: ReferenceMode::SELL,
            ),
            $this->rule(
                RuleTargetType::GROUP,
                18,
                qualifier: CompressionQualifier::COMPRESSED,
                acceptance: RuleAcceptance::REJECT,
            ),
            $this->rule(
                RuleTargetType::TYPE,
                634,
                acceptance: RuleAcceptance::ACCEPT,
                modifierOperation: ModifierOperation::REPLACE,
                modifierBps: -500,
            ),
        ]);
        $gateway = PipelineFakePriceGateway::withPrices([20 => [634 => '2']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $line = $this->service()->appraise($program, 42, 'Compressed Tritanium 1')->result->lines[0];

        self::assertSame(AppraisalLineStatus::PRICED, $line->status);
        self::assertSame(
            ['GROUP', 'GROUP_COMPRESSION', 'TYPE'],
            array_column($line->effectivePolicy['layers'], 'source'),
        );
        self::assertNotContains('TYPE_COMPRESSION', array_column($line->effectivePolicy['layers'], 'source'));
        self::assertSame(-500, $line->effectiveModifierBps);
        self::assertSame(ReferenceMode::SELL, $line->logicalReferenceMode);
        self::assertSame([[20, [634]]], $gateway->calls);
    }

    public function test_unavailable_channel_affects_sell_and_derived_split_while_buy_remains_priced(): void
    {
        $program = $this->program();
        $program->rules()->createMany([
            $this->rule(RuleTargetType::TYPE, 35, referenceMode: ReferenceMode::SELL),
            $this->rule(RuleTargetType::TYPE, 36, referenceMode: ReferenceMode::SPLIT),
        ]);
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '2', 36 => '4']]);
        $gateway->unavailableInstances[20] = true;
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $result = $this->service()
            ->appraise($program, 42, "Tritanium 1\nPyerite 1\nMexallon 1")
            ->result;

        self::assertSame([
            AppraisalLineStatus::PRICED,
            AppraisalLineStatus::REFERENCE_UNAVAILABLE,
            AppraisalLineStatus::REFERENCE_UNAVAILABLE,
        ], array_map(static fn ($line): AppraisalLineStatus => $line->status, $result->lines));
        self::assertSame('2.00', $result->quoteableTotal);
        self::assertSame([[10, [34, 36]]], $gateway->calls);
    }

    public function test_pricing_misconfiguration_fails_the_appraisal_without_creating_a_token(): void
    {
        $program = $this->program();
        $gateway = PipelineFakePriceGateway::withPrices([]);
        $gateway->missingInstances[10] = true;
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $this->expectException(AppraisalPricingConfigurationException::class);

        $this->service()->appraise($program, 42, 'Tritanium 1');
    }

    public function test_invalid_effective_policy_fails_cleanly_before_pricing(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => 10000])->save();
        $program->rules()->create($this->rule(
            RuleTargetType::TYPE,
            34,
            modifierOperation: ModifierOperation::ADJUST,
            modifierBps: 1,
        ));
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '1']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        try {
            $this->service()->appraise($program->fresh(), 42, 'Tritanium 1');
            self::fail('An out-of-range effective modifier should fail appraisal.');
        } catch (AppraisalPolicyException) {
            self::assertSame([], $gateway->calls);
        }
    }

    public function test_retry_is_a_full_new_appraisal_and_does_not_mutate_the_cached_offer(): void
    {
        $program = $this->program();
        $this->app->instance(
            SeatPriceProviderGateway::class,
            PipelineFakePriceGateway::withPrices([10 => [34 => '1.005']]),
        );
        $first = $this->service()->appraise($program, 42, 'Tritanium 2');

        $program->forceFill(['default_modifier_bps' => 1000])->save();
        $this->app->instance(
            SeatPriceProviderGateway::class,
            PipelineFakePriceGateway::withPrices([10 => [34 => '5']]),
        );
        $second = $this->service()->appraise($program->fresh(), 42, 'Tritanium 2');

        self::assertNotSame($first->token, $second->token);
        self::assertSame('2.02', $first->result->quoteableTotal);
        self::assertSame('11.00', $second->result->quoteableTotal);
        self::assertSame(
            '2.02',
            $this->app->make(AppraisalStore::class)
                ->findForRequester($first->token, 42)?->result->quoteableTotal,
        );
    }

    public function test_only_enabled_programs_can_start_new_appraisals(): void
    {
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '1']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        foreach ([ProgramStatus::DISABLED, ProgramStatus::ARCHIVED] as $status) {
            $program = $this->program(status: $status);

            try {
                $this->service()->appraise($program, 42, 'Tritanium 1');
                self::fail($status->value . ' should not allow appraisal.');
            } catch (ProgramUnavailableForAppraisalException) {
                self::assertSame([], $gateway->calls);
            }
        }

        $enabled = $this->program(status: ProgramStatus::ENABLED);
        self::assertSame(
            AppraisalLineStatus::PRICED,
            $this->service()->appraise($enabled, 42, 'Tritanium 1')->result->lines[0]->status,
        );
    }

    public function test_configurable_unique_type_limit_is_independent_of_total_quantity(): void
    {
        config()->set('seat-buyback-programs.appraisal.max_unique_types', 1);
        $program = $this->program();
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '1', 35 => '1']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);

        $largeQuantity = $this->service()->appraise($program, 42, 'Tritanium 5000000000');
        self::assertSame('5000000000.00', $largeQuantity->result->quoteableTotal);

        $this->expectException(AppraisalLimitExceededException::class);
        $this->service()->appraise($program, 42, "Tritanium 1\nPyerite 1");
    }

    public function test_configurable_input_byte_and_line_limits_fail_before_pricing(): void
    {
        $program = $this->program();
        $gateway = PipelineFakePriceGateway::withPrices([10 => [34 => '1']]);
        $this->app->instance(SeatPriceProviderGateway::class, $gateway);
        config()->set('seat-buyback-programs.appraisal.max_input_bytes', 10);

        try {
            $this->service()->appraise($program, 42, 'Tritanium 1');
            self::fail('The byte limit should reject oversized input.');
        } catch (AppraisalLimitExceededException) {
            self::assertSame([], $gateway->calls);
        }

        config()->set('seat-buyback-programs.appraisal.max_input_bytes', 1000);
        config()->set('seat-buyback-programs.appraisal.max_input_lines', 1);

        $this->expectException(AppraisalLimitExceededException::class);
        $this->service()->appraise($program, 42, "Tritanium 1\nTritanium 1");
    }

    public function test_route_is_post_only_and_requires_web_auth_csrf_group_and_request_permission(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('buyback.appraisals.store');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertSame(
            ['web', 'auth', 'can:buyback.request'],
            $route->gatherMiddleware(),
        );
    }

    private function service(): InventoryAppraisalService
    {
        return $this->app->make(InventoryAppraisalService::class);
    }

    private function program(
        string $name = 'Ore',
        ProgramStatus $status = ProgramStatus::ENABLED,
        Acceptance $defaultAcceptance = Acceptance::ACCEPT,
    ): BuybackProgram {
        $program = BuybackProgram::create([
            'name' => $name,
            'status' => $status,
            'default_acceptance' => $defaultAcceptance,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 60,
        ]);
        $program->priceReferences()->createMany([
            [
                'reference_mode' => ReferenceMode::BUY,
                'resolution' => ReferenceResolution::PROVIDER,
                'provider_instance_id' => 10,
            ],
            [
                'reference_mode' => ReferenceMode::SELL,
                'resolution' => ReferenceResolution::PROVIDER,
                'provider_instance_id' => 20,
            ],
            [
                'reference_mode' => ReferenceMode::SPLIT,
                'resolution' => ReferenceResolution::DERIVED_MIDPOINT,
                'provider_instance_id' => null,
            ],
        ]);

        return $program;
    }

    /** @return array<string, mixed> */
    private function rule(
        RuleTargetType $targetType,
        int $targetId,
        CompressionQualifier $qualifier = CompressionQualifier::ANY,
        RuleAcceptance $acceptance = RuleAcceptance::INHERIT,
        ?ReferenceMode $referenceMode = null,
        ModifierOperation $modifierOperation = ModifierOperation::INHERIT,
        ?int $modifierBps = null,
    ): array {
        return [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'compression_qualifier' => $qualifier,
            'acceptance' => $acceptance,
            'reference_mode_override' => $referenceMode,
            'modifier_operation' => $modifierOperation,
            'modifier_bps' => $modifierBps,
            'enabled' => true,
        ];
    }

    private function usableCompressionData(bool $stale = false): void
    {
        CompressionMapping::create([
            'uncompressed_type_id' => 34,
            'compressed_type_id' => 634,
        ]);
        CompressionMetadata::create([
            'active_sde_build' => '2026-09-11',
            'source' => 'CCP Static Data Export',
            'source_metadata' => $stale ? [
                'last_refresh_failure' => [
                    'code' => 'DOWNLOAD_FAILURE',
                    'attempted_build' => '2026-09-12',
                ],
            ] : [],
            'imported_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'last_checked_at' => CarbonImmutable::now(),
            'last_error_at' => $stale ? CarbonImmutable::now() : null,
            'last_error_message' => $stale ? 'Refresh failed.' : null,
        ]);
    }
}

final class PipelineFakePriceGateway implements SeatPriceProviderGateway
{
    /** @var list<array{int, list<int>}> */
    public array $calls = [];

    /** @var array<int, true> */
    public array $missingInstances = [];

    /** @var array<int, true> */
    public array $unavailableInstances = [];

    private function __construct(private readonly Closure $callback)
    {
    }

    /** @param array<int, array<int, string>> $prices */
    public static function withPrices(array $prices): self
    {
        return new self(static function (int $providerId, array $typeIds) use ($prices): array {
            $result = [];

            foreach ($typeIds as $typeId) {
                if (isset($prices[$providerId][$typeId])) {
                    $result[$typeId] = new ProviderPrice(
                        $providerId,
                        $typeId,
                        BigDecimal::of($prices[$providerId][$typeId]),
                    );
                }
            }

            return $result;
        });
    }

    public function providerInstance(int $providerInstanceId): ?ProviderInstance
    {
        if (isset($this->unavailableInstances[$providerInstanceId])) {
            throw new PriceProviderGatewayException('Simulated provider outage.');
        }

        if (isset($this->missingInstances[$providerInstanceId])) {
            return null;
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
