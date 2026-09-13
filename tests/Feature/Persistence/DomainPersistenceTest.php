<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuoteItem;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMetadata;
use RandulfTheGrey\Seat\BuybackPrograms\Models\ProgramPriceReference;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class DomainPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_program_defaults_and_enum_values_round_trip(): void
    {
        $program = BuybackProgram::create([
            'name' => 'Default Program',
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => -1000,
            'quote_validity_minutes' => 60,
        ])->fresh();

        self::assertSame(ProgramStatus::DISABLED, $program->status);
        self::assertSame(Acceptance::ACCEPT, $program->default_acceptance);
        self::assertSame(ReferenceMode::BUY, $program->default_reference_mode);
        self::assertSame(-1000, $program->default_modifier_bps->value);
        self::assertSame('DISABLED', DB::table('buyback_programs')->value('status'));
        self::assertSame('ACCEPT', DB::table('buyback_programs')->value('default_acceptance'));

        $program->update(['default_acceptance' => Acceptance::REJECT]);
        self::assertSame(Acceptance::REJECT, $program->fresh()->default_acceptance);
    }

    public function test_price_reference_and_rule_enum_values_and_relationships_round_trip(): void
    {
        $program = $this->createProgram();

        $reference = $program->priceReferences()->create([
            'reference_mode' => ReferenceMode::SPLIT,
            'resolution' => ReferenceResolution::DERIVED_MIDPOINT,
            'provider_instance_id' => null,
        ]);
        $rule = $program->rules()->create([
            'target_type' => RuleTargetType::TYPE,
            'target_id' => 34,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'reference_mode_override' => ReferenceMode::SELL,
            'modifier_operation' => ModifierOperation::ADJUST,
            'modifier_bps' => -500,
        ]);

        self::assertTrue($reference->program->is($program));
        self::assertSame(ReferenceMode::SPLIT, $reference->fresh()->reference_mode);
        self::assertSame(ReferenceResolution::DERIVED_MIDPOINT, $reference->fresh()->resolution);
        self::assertTrue($rule->program->is($program));
        self::assertSame(RuleTargetType::TYPE, $rule->fresh()->target_type);
        self::assertSame(CompressionQualifier::ANY, $rule->fresh()->compression_qualifier);
        self::assertSame(RuleAcceptance::REJECT, $rule->fresh()->acceptance);
        self::assertSame(ReferenceMode::SELL, $rule->fresh()->reference_mode_override);
        self::assertSame(ModifierOperation::ADJUST, $rule->fresh()->modifier_operation);
        self::assertSame(-500, $rule->fresh()->modifier_bps->value);
    }

    /**
     * @return iterable<string, array{RuleTargetType, int}>
     */
    public static function sparseRuleTargetProvider(): iterable
    {
        yield 'group' => [RuleTargetType::GROUP, 18];
        yield 'type' => [RuleTargetType::TYPE, 34];
    }

    #[DataProvider('sparseRuleTargetProvider')]
    public function test_group_and_type_rule_logical_identity_is_unique(
        RuleTargetType $targetType,
        int $targetId,
    ): void {
        $program = $this->createProgram();
        $attributes = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::INHERIT,
            'modifier_operation' => ModifierOperation::ADJUST,
            'modifier_bps' => -100,
        ];

        $program->rules()->create($attributes);

        $this->expectException(QueryException::class);
        $program->rules()->create($attributes);
    }

    /** @return iterable<string, array{CompressionQualifier}> */
    public static function invalidTypeQualifierProvider(): iterable
    {
        yield 'compressed' => [CompressionQualifier::COMPRESSED];
        yield 'uncompressed' => [CompressionQualifier::UNCOMPRESSED];
    }

    #[DataProvider('invalidTypeQualifierProvider')]
    public function test_database_rejects_independent_type_compression_qualifier(
        CompressionQualifier $qualifier,
    ): void {
        $program = $this->createProgram();

        $this->expectException(QueryException::class);

        DB::table('buyback_rules')->insert([
            'program_id' => $program->id,
            'target_type' => RuleTargetType::TYPE->value,
            'target_id' => 34,
            'compression_qualifier' => $qualifier->value,
        ]);
    }

    public function test_group_qualifier_identities_can_coexist(): void
    {
        $program = $this->createProgram();

        foreach (CompressionQualifier::cases() as $qualifier) {
            $program->rules()->create([
                'target_type' => RuleTargetType::GROUP,
                'target_id' => 18,
                'compression_qualifier' => $qualifier,
                'acceptance' => RuleAcceptance::REJECT,
                'modifier_operation' => ModifierOperation::INHERIT,
            ]);
        }

        self::assertSame(
            ['ANY', 'COMPRESSED', 'UNCOMPRESSED'],
            $program->rules()
                ->orderBy('compression_qualifier')
                ->get()
                ->map(static fn (BuybackRule $rule): string => $rule->compression_qualifier->value)
                ->all(),
        );
    }

    public function test_rule_target_representation_contains_only_group_and_type(): void
    {
        self::assertSame([RuleTargetType::GROUP, RuleTargetType::TYPE], RuleTargetType::cases());
        self::assertNull(RuleTargetType::tryFrom('GLOBAL'));
    }

    public function test_global_rule_target_cannot_be_persisted_through_model(): void
    {
        $this->expectException(\ValueError::class);

        $this->createProgram()->rules()->create([
            'target_type' => 'GLOBAL',
            'target_id' => 18,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::ACCEPT,
            'modifier_operation' => ModifierOperation::INHERIT,
        ]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidStoredRuleTargetProvider(): iterable
    {
        yield 'global target' => ['GLOBAL', 18];
        yield 'zero group target ID' => ['GROUP', 0];
    }

    #[DataProvider('invalidStoredRuleTargetProvider')]
    public function test_database_rejects_non_sparse_rule_targets(string $targetType, int $targetId): void
    {
        $program = $this->createProgram();

        $this->expectException(QueryException::class);

        DB::table('buyback_rules')->insert([
            'program_id' => $program->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }

    public function test_zero_rule_target_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target_id must be a positive integer');

        $this->createProgram()->rules()->create([
            'target_type' => RuleTargetType::GROUP,
            'target_id' => 0,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'modifier_operation' => ModifierOperation::INHERIT,
        ]);
    }

    public function test_rule_target_id_has_no_reserved_zero_default(): void
    {
        $column = collect(DB::select("PRAGMA table_info('buyback_rules')"))
            ->first(static fn (object $definition): bool => $definition->name === 'target_id');

        self::assertNotNull($column);
        self::assertNull($column->dflt_value);
        self::assertSame(1, $column->notnull);
    }

    public function test_program_reference_mode_is_unique(): void
    {
        $program = $this->createProgram();
        $attributes = [
            'reference_mode' => ReferenceMode::BUY,
            'resolution' => ReferenceResolution::PROVIDER,
            'provider_instance_id' => 999999,
        ];

        $program->priceReferences()->create($attributes);

        $this->expectException(QueryException::class);
        $program->priceReferences()->create($attributes);
    }

    public function test_compression_projection_enforces_one_to_one_mapping(): void
    {
        CompressionMapping::create([
            'uncompressed_type_id' => 123,
            'compressed_type_id' => 456,
        ]);

        $this->expectException(QueryException::class);
        CompressionMapping::create([
            'uncompressed_type_id' => 789,
            'compressed_type_id' => 456,
        ]);
    }

    public function test_quote_and_request_public_ids_are_generated_ulids(): void
    {
        $quote = $this->createQuote($this->createProgram());
        $request = BuybackRequest::create([
            'quote_id' => $quote->id,
            'submitted_at' => CarbonImmutable::parse('2026-09-10 12:05:00'),
        ]);

        self::assertTrue(Str::isUlid($quote->public_id));
        self::assertTrue(Str::isUlid($request->public_id));
        self::assertSame(26, strlen($quote->public_id));
        self::assertSame(26, strlen($request->public_id));
        self::assertNotSame((string) $quote->id, $quote->public_id);
        self::assertSame($quote->public_id, $quote->getRouteKey());
        self::assertSame($request->public_id, $request->getRouteKey());
    }

    public function test_quote_public_id_is_unique(): void
    {
        $program = $this->createProgram();
        $quote = $this->createQuote($program);

        $this->expectException(QueryException::class);
        $this->createQuote($program, ['public_id' => $quote->public_id]);
    }

    public function test_request_public_id_is_unique(): void
    {
        $program = $this->createProgram();
        $request = BuybackRequest::create([
            'quote_id' => $this->createQuote($program)->id,
            'submitted_at' => '2026-09-10 12:05:00',
        ]);

        $this->expectException(QueryException::class);
        BuybackRequest::create([
            'public_id' => $request->public_id,
            'quote_id' => $this->createQuote($program)->id,
            'submitted_at' => '2026-09-10 12:06:00',
        ]);
    }

    public function test_request_is_unique_per_quote(): void
    {
        $quote = $this->createQuote($this->createProgram());
        $attributes = [
            'quote_id' => $quote->id,
            'submitted_at' => CarbonImmutable::parse('2026-09-10 12:05:00'),
        ];

        BuybackRequest::create($attributes);

        $this->expectException(QueryException::class);
        BuybackRequest::create($attributes);
    }

    public function test_quote_item_is_unique_per_quote_and_type(): void
    {
        $quote = $this->createQuote($this->createProgram());
        $this->createQuoteItem($quote);

        $this->expectException(QueryException::class);
        $this->createQuoteItem($quote);
    }

    public function test_exact_decimal_money_and_versioned_snapshots_round_trip(): void
    {
        $quote = $this->createQuote($this->createProgram(), [
            'payable_total' => '30.03',
        ]);
        $item = $this->createQuoteItem($quote, [
            'reference_unit_price' => '10.00500000',
            'final_unit_price' => '10.01',
            'line_total' => '30.03',
            'policy_snapshot' => [
                'schema_version' => 1,
                'layers' => [['kind' => 'PROGRAM_DEFAULT', 'modifier_bps' => 0]],
            ],
            'pricing_snapshot' => [
                'schema_version' => 1,
                'resolution' => 'DERIVED_MIDPOINT',
                'components' => ['buy' => '10.00', 'sell' => '10.01'],
            ],
        ])->fresh();

        self::assertSame('30.03', $quote->fresh()->payable_total);
        self::assertSame('10.005000000000000000', $item->reference_unit_price);
        self::assertSame('10.01', $item->final_unit_price);
        self::assertSame('30.03', $item->line_total);
        self::assertSame(1, $item->policy_snapshot['schema_version']);
        self::assertSame('PROGRAM_DEFAULT', $item->policy_snapshot['layers'][0]['kind']);
        self::assertSame('10.01', $item->pricing_snapshot['components']['sell']);
    }

    public function test_quote_item_is_payable_only_and_request_does_not_duplicate_requester(): void
    {
        self::assertFalse(Schema::hasColumn('buyback_quote_items', 'status'));
        self::assertFalse(Schema::hasColumn('buyback_quote_items', 'outcome'));
        self::assertFalse(Schema::hasColumn('buyback_requests', 'requester_user_id'));
        self::assertFalse(Schema::hasColumn('buyback_requests', 'requester_name'));
        self::assertFalse(Schema::hasColumn('buyback_requests', 'requester_name_snapshot'));
        self::assertFalse(Schema::hasColumn('buyback_requests', 'requester_name_snapshot'));
    }

    public function test_rule_schema_stays_sparse_and_has_no_priority_or_materialized_allowlist(): void
    {
        self::assertFalse(Schema::hasColumn('buyback_rules', 'priority'));
        self::assertFalse(Schema::hasColumn('buyback_rules', 'sort_order'));
        self::assertFalse(Schema::hasColumn('buyback_rules', 'category_id'));
        self::assertFalse(Schema::hasTable('buyback_type_allowlist'));
        $mappingColumns = Schema::getColumnListing('buyback_compression_mappings');
        sort($mappingColumns);

        self::assertSame(['compressed_type_id', 'uncompressed_type_id'], $mappingColumns);
    }

    public function test_external_identifiers_have_no_foreign_keys_and_can_drift(): void
    {
        $externalColumns = [
            'buyback_programs' => ['created_by_user_id', 'updated_by_user_id'],
            'buyback_program_price_references' => ['provider_instance_id'],
            'buyback_rules' => ['target_id', 'created_by_user_id', 'updated_by_user_id'],
            'buyback_quotes' => ['requester_user_id'],
            'buyback_quote_items' => ['type_id'],
            'buyback_requests' => [
                'eve_contract_id',
                'completed_by_user_id',
                'rejected_by_user_id',
                'canceled_by_user_id',
            ],
        ];

        foreach ($externalColumns as $table => $columns) {
            $foreignKeyColumns = array_map(
                static fn (object $foreignKey): string => $foreignKey->from,
                DB::select(sprintf("PRAGMA foreign_key_list('%s')", $table)),
            );

            foreach ($columns as $column) {
                self::assertNotContains($column, $foreignKeyColumns, sprintf('%s.%s must remain external.', $table, $column));
            }
        }

        $program = $this->createProgram([
            'created_by_user_id' => 900000001,
            'updated_by_user_id' => 900000002,
        ]);
        $program->priceReferences()->create([
            'reference_mode' => ReferenceMode::BUY,
            'resolution' => ReferenceResolution::PROVIDER,
            'provider_instance_id' => 900000003,
        ]);
        $quote = $this->createQuote($program, ['requester_user_id' => 900000004]);
        $this->createQuoteItem($quote, ['type_id' => 900000005]);

        self::assertTrue($quote->fresh()->exists);
    }

    public function test_internal_relationship_foreign_keys_are_present(): void
    {
        $expected = [
            'buyback_program_price_references' => ['program_id' => 'buyback_programs'],
            'buyback_rules' => ['program_id' => 'buyback_programs'],
            'buyback_quotes' => ['program_id' => 'buyback_programs'],
            'buyback_quote_items' => ['quote_id' => 'buyback_quotes'],
            'buyback_requests' => ['quote_id' => 'buyback_quotes'],
        ];

        foreach ($expected as $table => $relationships) {
            $actual = [];
            foreach (DB::select(sprintf("PRAGMA foreign_key_list('%s')", $table)) as $foreignKey) {
                $actual[$foreignKey->from] = $foreignKey->table;
            }

            self::assertSame($relationships, $actual);
        }
    }

    public function test_quote_item_and_request_model_relationships_work(): void
    {
        $program = $this->createProgram();
        $quote = $this->createQuote($program);
        $item = $this->createQuoteItem($quote);
        $request = BuybackRequest::create([
            'quote_id' => $quote->id,
            'submitted_at' => '2026-09-10 12:05:00',
        ]);

        self::assertTrue($quote->program->is($program));
        self::assertTrue($program->quotes->first()->is($quote));
        self::assertTrue($quote->items->first()->is($item));
        self::assertTrue($item->quote->is($quote));
        self::assertTrue($quote->buybackRequest->is($request));
        self::assertTrue($request->quote->is($quote));
    }

    public function test_configuration_cascades_but_historical_program_delete_is_restricted(): void
    {
        $unused = $this->createProgram();
        $unused->rules()->create([
            'target_type' => RuleTargetType::TYPE,
            'target_id' => 34,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'modifier_operation' => ModifierOperation::INHERIT,
        ]);
        $unused->delete();
        self::assertSame(0, BuybackRule::query()->count());

        $historical = $this->createProgram();
        $this->createQuote($historical);

        $this->expectException(QueryException::class);
        $historical->delete();
    }

    public function test_compression_metadata_health_fields_round_trip(): void
    {
        $metadata = CompressionMetadata::create([
            'active_sde_build' => '2026-09-08',
            'source' => 'CCP SDE',
            'source_metadata' => ['schema_version' => 1, 'archive' => 'sde.zip'],
            'imported_at' => '2026-09-10 10:00:00',
            'activated_at' => '2026-09-10 10:01:00',
            'last_checked_at' => '2026-09-10 11:00:00',
            'last_error_at' => '2026-09-10 11:01:00',
            'last_error_message' => 'fixture failure',
        ])->fresh();

        self::assertSame('2026-09-08', $metadata->active_sde_build);
        self::assertSame(1, $metadata->source_metadata['schema_version']);
        self::assertSame('fixture failure', $metadata->last_error_message);
        self::assertSame('2026-09-10 11:00:00', $metadata->last_checked_at->format('Y-m-d H:i:s'));
    }

    public function test_basis_point_cast_enforces_configured_bounds(): void
    {
        self::assertSame(-10000, (new BasisPoints(-10000))->value);
        self::assertSame(10000, (new BasisPoints(10000))->value);
        self::assertSame(
            500,
            $this->createProgram(['default_modifier_bps' => '+500'])->default_modifier_bps->value,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->createProgram(['default_modifier_bps' => 10001]);
    }

    public function test_all_request_states_and_terminal_evidence_are_representable(): void
    {
        $program = $this->createProgram();
        $requests = [
            [BuybackRequestStatus::PENDING, []],
            [BuybackRequestStatus::COMPLETED, [
                'completed_at' => '2026-09-10 13:00:00',
                'completed_by_user_id' => 101,
            ]],
            [BuybackRequestStatus::REJECTED, [
                'rejected_at' => '2026-09-10 13:00:00',
                'rejected_by_user_id' => 102,
                'rejection_reason' => 'Contract contents did not match.',
            ]],
            [BuybackRequestStatus::CANCELED, [
                'canceled_at' => '2026-09-10 13:00:00',
                'canceled_by_user_id' => 103,
            ]],
        ];

        foreach ($requests as [$status, $terminalFields]) {
            $request = BuybackRequest::create([
                'quote_id' => $this->createQuote($program)->id,
                'status' => $status,
                'submitted_at' => '2026-09-10 12:05:00',
                'eve_contract_id' => null,
                'requester_note' => 'Requester context',
                'manager_note' => 'Manager context',
                ...$terminalFields,
            ])->fresh();

            self::assertSame($status, $request->status);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProgram(array $overrides = []): BuybackProgram
    {
        return BuybackProgram::create([
            'name' => 'Mineral Buyback',
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => -1000,
            'quote_validity_minutes' => 60,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createQuote(BuybackProgram $program, array $overrides = []): BuybackQuote
    {
        return BuybackQuote::create([
            'appraisal_token_hash' => hash('sha256', (string) Str::ulid()),
            'program_id' => $program->id,
            'requester_user_id' => 42,
            'requester_name_snapshot' => 'Capsuleer Example',
            'program_name_snapshot' => $program->name,
            'pricing_completed_at' => '2026-09-10 12:00:00',
            'quoted_at' => '2026-09-10 12:01:00',
            'expires_at' => '2026-09-10 13:00:00',
            'payable_total' => '30.03',
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createQuoteItem(BuybackQuote $quote, array $overrides = []): BuybackQuoteItem
    {
        return BuybackQuoteItem::create([
            'quote_id' => $quote->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'quantity' => 3,
            'compression_state' => CompressionState::NOT_APPLICABLE,
            'reference_mode' => ReferenceMode::SPLIT,
            'reference_resolution' => ReferenceResolution::DERIVED_MIDPOINT,
            'reference_unit_price' => '10.00500000',
            'effective_modifier_bps' => 0,
            'final_unit_price' => '10.01',
            'line_total' => '30.03',
            'policy_snapshot' => ['schema_version' => 1],
            'pricing_snapshot' => ['schema_version' => 1],
            ...$overrides,
        ]);
    }
}
