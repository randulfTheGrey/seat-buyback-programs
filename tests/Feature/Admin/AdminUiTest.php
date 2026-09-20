<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\View;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\ProgramHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionSyncResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Jobs\SyncCompressionData;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMetadata;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class AdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        config()->set('cache.default', 'array');
        View::addNamespace('web', dirname(__DIR__, 2) . '/Fixtures/views');

        Gate::define('randulfthegrey-buyback.request', static fn (AdminUiUser $user): bool => in_array('randulfthegrey-buyback.request', $user->permissions, true));
        Gate::define('randulfthegrey-buyback.manage', static fn (AdminUiUser $user): bool => in_array('randulfthegrey-buyback.manage', $user->permissions, true));
        Gate::define('randulfthegrey-buyback.admin', static fn (AdminUiUser $user): bool => in_array('randulfthegrey-buyback.admin', $user->permissions, true));

        Schema::create('price_provider_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('backend')->default('opaque-test-backend');
            $table->json('configuration')->nullable();
        });
        Schema::create('invGroups', function (Blueprint $table): void {
            $table->unsignedBigInteger('groupID')->primary();
            $table->unsignedBigInteger('categoryID');
            $table->string('groupName');
        });
        Schema::create('invTypes', function (Blueprint $table): void {
            $table->unsignedBigInteger('typeID')->primary();
            $table->unsignedBigInteger('groupID');
            $table->string('typeName');
            $table->boolean('published')->default(true);
        });

        $this->seedSde();
    }

    public function test_admin_routes_require_independent_admin_permission(): void
    {
        foreach ([['randulfthegrey-buyback.request'], ['randulfthegrey-buyback.manage']] as $permissions) {
            $this->actingAs($this->user(40, $permissions))
                ->get(route('buyback.admin.programs.index'))
                ->assertForbidden();
        }

        $this->actingAs($this->user(41, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.programs.index'))
            ->assertOk()
            ->assertSee('New Program');

        self::assertFalse(Gate::forUser($this->user(42, ['randulfthegrey-buyback.admin']))->allows('randulfthegrey-buyback.manage'));

        $routes = $this->app['router']->getRoutes();
        self::assertSame('buyback/programs', $routes->getByName('buyback.programs.index')?->uri());
        self::assertSame('buyback-admin/programs', $routes->getByName('buyback.admin.programs.index')?->uri());
        self::assertSame('buyback', config('package.sidebar.buyback-programs.route_segment'));
        self::assertSame('buyback-admin', config('package.sidebar.buyback-administration.route_segment'));
    }

    public function test_program_editor_uses_responsive_two_column_cards_and_live_landing_preview(): void
    {
        $response = $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.programs.create'))
            ->assertOk()
            ->assertSee('Appraisals Landing Preview')
            ->assertSee('id="program-preview-name"', escape: false)
            ->assertSee('id="program-preview-policy"', escape: false)
            ->assertSee('id="program-preview-contract"', escape: false)
            ->assertSee("input.addEventListener('input', updatePreview)", escape: false);

        self::assertSame(4, substr_count($response->getContent(), 'class="col-12 col-lg-6 d-flex"'));
    }

    public function test_program_creation_defaults_disabled_accepts_global_choice_and_translates_modifier_exactly(): void
    {
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->post(route('buyback.admin.programs.store'), $this->programPayload([
                'status' => null,
                'default_acceptance' => 'REJECT',
                'default_modifier_direction' => 'discount',
                'default_modifier_percentage' => '10.00',
                'contract_instructions' => 'Contract to the alliance corporation.',
            ]))
            ->assertRedirect();

        $program = BuybackProgram::query()->with('priceReferences')->sole();
        self::assertSame(ProgramStatus::DISABLED, $program->status);
        self::assertSame(Acceptance::REJECT, $program->default_acceptance);
        self::assertSame(-1000, $program->default_modifier_bps->value);
        self::assertSame('Contract to the alliance corporation.', $program->contract_instructions);
        self::assertCount(3, $program->priceReferences);

        $this->get(route('buyback.admin.programs.edit', $program))
            ->assertOk()
            ->assertSee('Accept items unless a rule rejects them')
            ->assertSee('Reject items unless a rule accepts them')
            ->assertSee('Derived midpoint — (BUY + SELL) / 2');
    }

    public function test_program_enablement_blocks_missing_reference_and_allows_non_blocking_warnings(): void
    {
        $program = $this->program();

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
                'status' => 'ENABLED',
            ]))
            ->assertSessionHasErrors('status');
        self::assertSame(ProgramStatus::DISABLED, $program->fresh()->status);

        $providerId = $this->provider('Shared BUY/SELL');
        $response = $this->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
            'status' => 'ENABLED',
            'default_acceptance' => 'REJECT',
            'buy_provider_instance_id' => $providerId,
            'sell_provider_instance_id' => $providerId,
        ]));

        $response->assertRedirect(route('buyback.admin.programs.edit', $program));
        self::assertSame(ProgramStatus::ENABLED, $program->fresh()->status);
        self::assertContains('This Program currently rejects every item.', session('warnings'));
        self::assertContains(
            'BUY and SELL currently use the same provider instance. Derived SPLIT will equal that stream.',
            session('warnings'),
        );
    }

    public function test_provider_drift_degrades_health_without_mutating_enabled_status(): void
    {
        $providerId = $this->provider('BUY stream');
        $program = $this->program(ProgramStatus::ENABLED);
        $this->references($program, $providerId, $providerId);

        self::assertTrue($this->app->make(ProgramHealthService::class)->forProgram($program)->operational());
        \RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance::query()->whereKey($providerId)->delete();

        $health = $this->app->make(ProgramHealthService::class)->forProgram($program->fresh());
        self::assertFalse($health->operational());
        self::assertStringContainsString('no longer exists', $health->references['BUY']['message']);
        self::assertSame(ProgramStatus::ENABLED, $program->fresh()->status);
        self::assertTrue($health->configuration->valid());
        self::assertNotEmpty($health->runtimeErrors);

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
                'name' => 'Renamed during provider drift',
                'status' => 'ENABLED',
                'buy_provider_instance_id' => $providerId,
                'sell_provider_instance_id' => $providerId,
            ]))
            ->assertRedirect(route('buyback.admin.programs.edit', $program));
        self::assertSame('Renamed during provider drift', $program->fresh()->name);
        self::assertSame(ProgramStatus::ENABLED, $program->fresh()->status);
    }

    public function test_enablement_checks_rule_references_derived_split_and_compression_dependencies(): void
    {
        $buy = $this->provider('BUY stream');
        $program = $this->program();
        $this->references($program, $buy, $buy);
        $program->rules()->create($this->storedRule(['reference_mode_override' => 'SELL']));

        \RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance::query()->whereKey($buy)->delete();
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
                'status' => 'ENABLED',
                'default_reference_mode' => 'SPLIT',
                'buy_provider_instance_id' => null,
                'sell_provider_instance_id' => null,
            ]))
            ->assertSessionHasErrors('status');
        self::assertSame(ProgramStatus::DISABLED, $program->fresh()->status);

        $provider = $this->provider('Restored stream');
        $program->rules()->delete();
        $program->rules()->create($this->storedRule(['compression_qualifier' => 'COMPRESSED', 'acceptance' => 'REJECT']));
        $this->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
            'status' => 'ENABLED',
            'buy_provider_instance_id' => $provider,
            'sell_provider_instance_id' => $provider,
        ]))->assertSessionHasErrors('status');
        self::assertSame(ProgramStatus::DISABLED, $program->fresh()->status);
    }

    public function test_rule_editor_supports_sparse_states_and_enforces_single_type_identity(): void
    {
        $program = $this->program();
        $user = $this->user(42, ['randulfthegrey-buyback.admin']);

        $this->actingAs($user)->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload())
            ->assertSessionHasErrors('acceptance');

        $valid = $this->rulePayload([
            'acceptance' => 'ACCEPT',
            'modifier_operation' => 'ADJUST',
            'modifier_direction' => 'discount',
            'modifier_percentage' => '5.00',
            'admin_note' => 'Admin-only context',
        ]);
        $this->post(route('buyback.admin.programs.rules.store', $program), $valid)->assertRedirect();
        $rule = BuybackRule::query()->sole();
        self::assertSame(-500, $rule->modifier_bps->value);
        self::assertSame('Admin-only context', $rule->admin_note);

        $this->post(route('buyback.admin.programs.rules.store', $program), $valid)
            ->assertSessionHasErrors('target_id');
        self::assertSame(1, BuybackRule::query()->count());

        $this->usableCompressionData();
        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 34,
            'compression_qualifier' => 'ANY',
            'acceptance' => 'REJECT',
        ]))->assertRedirect();

        $typeRule = BuybackRule::query()->where('target_type', 'TYPE')->where('target_id', 34)->sole();
        self::assertSame(CompressionQualifier::ANY, $typeRule->compression_qualifier);
        $this->get(route('buyback.admin.programs.rules.edit', [$program, $typeRule]))
            ->assertOk()
            ->assertSee('data-compression-state="COMPRESSED"', escape: false)
            ->assertSee('Compression classification')
            ->assertSee('does not create another rule layer');

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 34,
            'compression_qualifier' => 'COMPRESSED',
            'acceptance' => 'REJECT',
        ]))->assertSessionHasErrors('compression_qualifier');

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 34,
            'compression_qualifier' => 'ANY',
            'acceptance' => 'ACCEPT',
        ]))->assertSessionHasErrors('target_id');
        self::assertSame(1, BuybackRule::query()->where('target_type', 'TYPE')->count());

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 35,
            'compression_qualifier' => 'COMPRESSED',
            'acceptance' => 'REJECT',
        ]))->assertSessionHasErrors('compression_qualifier');

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'compression_qualifier' => 'UNCOMPRESSED',
            'acceptance' => 'REJECT',
        ]))->assertSessionHasErrors('compression_qualifier');

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'GROUP',
            'target_id' => 20,
            'compression_qualifier' => 'COMPRESSED',
            'acceptance' => 'REJECT',
        ]))->assertSessionHasErrors('compression_qualifier');

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'GROUP',
            'target_id' => 20,
            'compression_qualifier' => 'UNCOMPRESSED',
            'acceptance' => 'REJECT',
        ]))->assertRedirect();

        $program->rules()->create($this->storedRule([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'compression_qualifier' => 'COMPRESSED',
            'acceptance' => 'REJECT',
        ]));
        $configuration = $this->app->make(ProgramHealthService::class)->forProgram($program->fresh())->configuration;
        self::assertFalse($configuration->valid());
        self::assertStringContainsString('no COMPRESSED canonical members', implode(' ', $configuration->errors));

        $this->get(route('buyback.admin.programs.rules.edit', [$program, $rule]))
            ->assertOk()
            ->assertSee('percentage points')
            ->assertSee('Administrator note');
    }

    public function test_disabled_program_rejects_deterministic_effective_modifier_overflow_on_rule_and_default_saves(): void
    {
        $this->usableCompressionData();
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => 8000])->save();
        $program->rules()->createMany([
            $this->storedRule([
                'modifier_operation' => 'ADJUST',
                'modifier_bps' => 500,
            ]),
            $this->storedRule([
                'compression_qualifier' => 'COMPRESSED',
                'modifier_operation' => 'ADJUST',
                'modifier_bps' => 500,
            ]),
        ]);
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']));
        $typeRule = $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 34,
            'compression_qualifier' => 'ANY',
            'modifier_operation' => 'ADJUST',
            'modifier_direction' => 'premium',
            'modifier_percentage' => '10.01',
        ]);

        $this->post(route('buyback.admin.programs.rules.store', $program), $typeRule)
            ->assertSessionHasErrors('rule_status');
        self::assertSame(2, $program->rules()->count());

        $typeRule['modifier_percentage'] = '10.00';
        $this->post(route('buyback.admin.programs.rules.store', $program), $typeRule)
            ->assertRedirect(route('buyback.admin.programs.rules.index', $program));

        $savedTypeRule = $program->rules()->where('target_type', 'TYPE')->sole();
        self::assertSame(1000, $savedTypeRule->modifier_bps->value);

        $typeRule['modifier_percentage'] = '10.01';
        $this->patch(
            route('buyback.admin.programs.rules.update', [$program, $savedTypeRule]),
            $typeRule,
        )->assertSessionHasErrors('rule_status');
        self::assertSame(1000, $savedTypeRule->fresh()->modifier_bps->value);

        $this->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
            'default_modifier_direction' => 'premium',
            'default_modifier_percentage' => '80.01',
        ]))->assertSessionHasErrors('status');
        self::assertSame(8000, $program->fresh()->default_modifier_bps->value);
    }

    public function test_valid_type_replacement_is_saved_despite_an_unchanged_invalid_group_in_another_context(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => -1000])->save();
        $program->rules()->create($this->storedRule([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'modifier_operation' => 'ADJUST',
            'modifier_bps' => -10000,
        ]));
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']));

        $this->post(route('buyback.admin.programs.rules.store', $program), $this->rulePayload([
            'target_type' => 'TYPE',
            'target_id' => 34,
            'modifier_operation' => 'REPLACE',
            'modifier_direction' => 'discount',
            'modifier_percentage' => '100',
        ]))->assertRedirect(route('buyback.admin.programs.rules.index', $program));

        $program = $program->fresh('rules');
        $typeRule = $program->rules->firstWhere('target_type', \RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType::TYPE);
        self::assertNotNull($typeRule);
        self::assertSame(-10000, $typeRule->modifier_bps->value);

        $mapper = $this->app->make(PolicyInputMapper::class);
        $effective = $this->app->make(PolicyEvaluator::class)->evaluate(
            $mapper->defaults($program),
            new ItemPolicyContext(34, 18, CompressionState::NOT_APPLICABLE),
            $mapper->activeRules($program->rules),
        );
        self::assertSame(-10000, $effective->effectiveModifierBps->value);

        $health = $this->app->make(ProgramHealthService::class)->forProgram($program);
        self::assertFalse($health->configuration->valid());
        self::assertStringContainsString('item group “Ore”', implode(' ', $health->configuration->errors));
    }

    public function test_invalid_adjustment_is_rejected_with_one_actionable_manager_diagnostic(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => -1000])->save();
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']));
        $createUrl = route('buyback.admin.programs.rules.create', $program);

        $response = $this->from($createUrl)->post(
            route('buyback.admin.programs.rules.store', $program),
            $this->rulePayload([
                'target_type' => 'TYPE',
                'target_id' => 34,
                'modifier_operation' => 'ADJUST',
                'modifier_direction' => 'discount',
                'modifier_percentage' => '100',
            ]),
        );

        $response->assertRedirect($createUrl)->assertSessionHasErrors('rule_status');
        self::assertSame(0, $program->rules()->count());

        $rendered = $this->get($createUrl)->assertOk()->getContent();
        $diagnostic = 'The ADJUST modifier for item type “Tritanium” would adjust 10% discount by 100% discount, producing 110% discount.';
        self::assertSame(1, substr_count($rendered, $diagnostic));
        self::assertSame(1, substr_count($rendered, 'Rule was not saved'));
        self::assertStringNotContainsString('-11000', $rendered);
    }

    public function test_worsened_persisted_violation_is_blocked_and_transient_error_has_one_presentation_owner(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => -1000])->save();
        $program->rules()->create($this->storedRule([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'modifier_operation' => 'ADJUST',
            'modifier_bps' => -10000,
        ]));
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']));
        $editUrl = route('buyback.admin.programs.edit', $program);

        $this->from($editUrl)->patch(route('buyback.admin.programs.update', $program), $this->programPayload([
            'default_modifier_direction' => 'discount',
            'default_modifier_percentage' => '20',
        ]))->assertRedirect($editUrl)->assertSessionHasErrors('status');
        self::assertSame(-1000, $program->fresh()->default_modifier_bps->value);

        $failedResponse = $this->get($editUrl)->assertOk()->getContent();
        self::assertSame(1, substr_count($failedResponse, 'producing 120% discount'));
        self::assertStringNotContainsString('producing 110% discount', $failedResponse);

        $durableHealth = $this->get($editUrl)->assertOk()->getContent();
        self::assertSame(1, substr_count($durableHealth, 'producing 110% discount'));
        self::assertStringNotContainsString('-11000', $durableHealth);
    }

    public function test_persisted_invalid_rule_can_be_improved_and_then_fully_corrected(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => -1000])->save();
        $rule = $program->rules()->create($this->storedRule([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'modifier_operation' => 'ADJUST',
            'modifier_bps' => -10000,
        ]));
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']));
        $route = route('buyback.admin.programs.rules.update', [$program, $rule]);

        $this->patch($route, $this->rulePayload([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'modifier_operation' => 'ADJUST',
            'modifier_direction' => 'discount',
            'modifier_percentage' => '95',
        ]))->assertRedirect(route('buyback.admin.programs.rules.index', $program));
        self::assertSame(-9500, $rule->fresh()->modifier_bps->value);
        self::assertFalse($this->app->make(ProgramHealthService::class)->forProgram($program->fresh())->configuration->valid());

        $this->patch($route, $this->rulePayload([
            'target_type' => 'GROUP',
            'target_id' => 19,
            'modifier_operation' => 'ADJUST',
            'modifier_direction' => 'discount',
            'modifier_percentage' => '90',
        ]))->assertRedirect(route('buyback.admin.programs.rules.index', $program));
        self::assertSame(-9000, $rule->fresh()->modifier_bps->value);
        self::assertTrue($this->app->make(ProgramHealthService::class)->forProgram($program->fresh())->configuration->valid());
    }

    public function test_program_health_deduplicates_one_rule_across_real_item_contexts_but_keeps_distinct_violations(): void
    {
        $program = $this->program();
        $program->forceFill(['default_modifier_bps' => -1000])->save();
        $program->rules()->createMany([
            $this->storedRule([
                'target_type' => 'GROUP',
                'target_id' => 18,
                'modifier_operation' => 'ADJUST',
                'modifier_bps' => -10000,
            ]),
            $this->storedRule([
                'target_type' => 'GROUP',
                'target_id' => 19,
                'modifier_operation' => 'ADJUST',
                'modifier_bps' => -10000,
            ]),
        ]);

        $health = $this->app->make(ProgramHealthService::class)->forProgram($program->fresh());

        self::assertCount(2, $health->configuration->effectivePolicyDiagnostics);
        self::assertSame(1, substr_count(implode(' ', $health->configuration->errors), 'item group “Mineral”'));
        self::assertSame(1, substr_count(implode(' ', $health->configuration->errors), 'item group “Ore”'));
    }

    public function test_rule_create_page_renders_searchable_target_control(): void
    {
        $program = $this->program();

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.programs.rules.create', $program))
            ->assertOk()
            ->assertSee('Target and lifecycle')
            ->assertSee('Search by EVE name')
            ->assertSee('Affected items')
            ->assertSee('buyback-select2-native')
            ->assertSee('Search for an EVE group')
            ->assertSee("width: '100%'", escape: false)
            ->assertSee('background-image: var(--icon-chevron-down', escape: false)
            ->assertSee("qualifierGroup.hide()", escape: false)
            ->assertSee("qualifier.val('ANY')", escape: false)
            ->assertSee('id="locked-compression-qualifier"', escape: false)
            ->assertSee('Compression classification')
            ->assertSee('configureTypeClassification', escape: false)
            ->assertSee("option[value=\"COMPRESSED\"]", escape: false)
            ->assertSee("affectedPanel.addClass('d-none')", escape: false)
            ->assertSee('affected-types')
            ->assertDontSee("theme: 'bootstrap4'", escape: false);
    }

    public function test_rule_preview_uses_production_precedence_and_one_program_default_baseline(): void
    {
        $program = $this->program();
        $program->rules()->createMany([
            $this->storedRule(['target_type' => 'GROUP', 'target_id' => 18, 'compression_qualifier' => 'COMPRESSED', 'acceptance' => 'REJECT']),
            $this->storedRule(['target_type' => 'TYPE', 'target_id' => 34, 'acceptance' => 'ACCEPT', 'reference_mode_override' => 'SELL']),
        ]);
        $this->usableCompressionData();

        $response = $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.preview.index', ['program_id' => $program->id, 'type_id' => 34]))
            ->assertOk()
            ->assertSee('Compressed Tritanium')
            ->assertSee('GROUP + COMPRESSION')
            ->assertSee('TYPE')
            ->assertDontSee('TYPE + COMPRESSION')
            ->assertSee('ACCEPT · SELL', escape: false)
            ->assertSee('Search for an EVE item type')
            ->assertSee("width: '100%'", escape: false)
            ->assertSee('height: calc(2.25rem + 2px)', escape: false)
            ->assertSee('background-image: var(--icon-chevron-down', escape: false)
            ->assertSee('display: none', escape: false)
            ->assertDontSee("$('#program_id').select2", escape: false)
            ->assertDontSee("theme: 'bootstrap4'", escape: false);

        self::assertSame(1, substr_count($response->getContent(), 'Program defaults / GLOBAL'));
    }

    public function test_sde_selectors_are_authorized_server_side_paginated_and_published_only(): void
    {
        $url = route('buyback.admin.selectors.types', ['q' => 'Trit']);
        $this->actingAs($this->user(40, ['randulfthegrey-buyback.request']))->getJson($url)->assertForbidden();

        $response = $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))->getJson($url)->assertOk();
        self::assertSame(34, $response->json('results.0.id'));
        self::assertStringContainsString('Mineral', $response->json('results.0.text'));
        self::assertNull($response->json('results.0.compression_applicable'));

        $this->getJson(route('buyback.admin.selectors.types', ['q' => 'Secret']))
            ->assertOk()
            ->assertJsonCount(0, 'results');
        $this->getJson(route('buyback.admin.selectors.groups', ['q' => 'Miner']))
            ->assertOk()
            ->assertJsonPath('results.0.id', 18)
            ->assertJsonPath('results.0.compression_applicable', null);

        $this->usableCompressionData();
        $this->getJson(route('buyback.admin.selectors.types', ['q' => 'Tritanium']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_applicable', true)
            ->assertJsonPath('results.0.compression_state', 'COMPRESSED');
        $this->getJson(route('buyback.admin.selectors.types', ['q' => 'Pyerite']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_applicable', false)
            ->assertJsonPath('results.0.compression_state', 'NOT_APPLICABLE');
        $this->getJson(route('buyback.admin.selectors.groups', ['q' => 'Mineral']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_applicable', true)
            ->assertJsonPath('results.0.compression_qualifiers', ['ANY', 'COMPRESSED', 'UNCOMPRESSED']);
        $this->getJson(route('buyback.admin.selectors.groups', ['q' => 'Ore']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_applicable', false)
            ->assertJsonPath('results.0.compression_qualifiers', ['ANY']);
        $this->getJson(route('buyback.admin.selectors.groups', ['q' => 'Raw Only']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_qualifiers', ['ANY', 'UNCOMPRESSED']);
        $this->getJson(route('buyback.admin.selectors.groups', ['q' => 'Compressed Only']))
            ->assertOk()
            ->assertJsonPath('results.0.compression_qualifiers', ['ANY', 'COMPRESSED']);
    }

    public function test_affected_items_preview_is_admin_only_paginated_and_qualifier_aware(): void
    {
        $url = route('buyback.admin.selectors.affected-types', [
            'target_type' => 'GROUP',
            'target_id' => 18,
            'compression_qualifier' => 'ANY',
        ]);
        $this->actingAs($this->user(40, ['randulfthegrey-buyback.request']))->getJson($url)->assertForbidden();

        $this->usableCompressionData();
        $response = $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('compression_applicable', true)
            ->assertJsonPath('pagination.total', 3);
        $items = collect($response->json('items'))->keyBy('id');
        self::assertSame('UNCOMPRESSED', $items->get(33)['compression_state']);
        self::assertSame('COMPRESSED', $items->get(34)['compression_state']);
        self::assertSame('NOT_APPLICABLE', $items->get(35)['compression_state']);

        $this->getJson(route('buyback.admin.selectors.affected-types', [
            'target_type' => 'TYPE',
            'target_id' => 33,
            'compression_qualifier' => 'ANY',
        ]))
            ->assertOk()
            ->assertJsonPath('target_compression_state', 'UNCOMPRESSED')
            ->assertJsonPath('pagination.total', 1);

        $this->getJson(route('buyback.admin.selectors.affected-types', [
            'target_type' => 'GROUP',
            'target_id' => 18,
            'compression_qualifier' => 'COMPRESSED',
        ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', 34);

        $this->getJson(route('buyback.admin.selectors.affected-types', [
            'target_type' => 'GROUP',
            'target_id' => 19,
            'compression_qualifier' => 'COMPRESSED',
        ]))
            ->assertOk()
            ->assertJsonPath('compression_applicable', false)
            ->assertJsonPath('effective_qualifier', 'ANY')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', 36);
    }

    public function test_program_and_rule_datatables_are_server_side_and_resolve_targets_without_ids_as_primary_labels(): void
    {
        $provider = $this->provider('BUY stream');
        $program = $this->program();
        $this->references($program, $provider, $provider);
        $program->rules()->createMany([
            $this->storedRule(['acceptance' => 'REJECT']),
            $this->storedRule([
                'target_type' => 'TYPE',
                'target_id' => 34,
                'modifier_operation' => 'REPLACE',
                'modifier_bps' => -500,
            ]),
            $this->storedRule([
                'target_type' => 'TYPE',
                'target_id' => 35,
                'modifier_operation' => 'ADJUST',
                'modifier_bps' => -250,
            ]),
        ]);
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $params = ['draw' => 1, 'start' => 0, 'length' => 10, 'columns' => []];

        $programs = $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->withHeaders($headers)
            ->get(route('buyback.admin.programs.index', $params))
            ->assertOk();
        self::assertSame('Mineral Buyback', $programs->json('data.0.name'));
        self::assertSame('Healthy', strip_tags((string) $programs->json('data.0.health')));

        $rules = $this->withHeaders($headers)
            ->get(route('buyback.admin.programs.rules.index', [$program] + $params))
            ->assertOk();
        self::assertSame('Mineral', $rules->json('data.0.target'));
        self::assertSame('Group', $rules->json('data.0.scope'));
        self::assertSame('Exact item type', $rules->json('data.1.qualifier'));
        self::assertSame(
            ['Inherit', 'Replace: 5% discount', 'Adjust: 2.5% discount'],
            array_column($rules->json('data'), 'modifier_label'),
        );
        self::assertStringNotContainsString('percentage points', $rules->getContent());
    }

    public function test_reference_data_health_and_manual_sync_are_admin_only_queued_and_overlap_safe(): void
    {
        Queue::fake();
        $this->usableCompressionData();
        $admin = $this->user(42, ['randulfthegrey-buyback.admin']);

        $this->actingAs($admin)->get(route('buyback.admin.reference-data.index'))
            ->assertOk()
            ->assertSee(CompressionDataStatus::AVAILABLE->value)
            ->assertSee('CCP Static Data Export')
            ->assertSee('1');

        $this->actingAs($this->user(43, ['randulfthegrey-buyback.request']))
            ->post(route('buyback.admin.reference-data.sync'))
            ->assertForbidden();

        $this->actingAs($admin)->post(route('buyback.admin.reference-data.sync'))
            ->assertSessionHas('success', 'Compression data synchronization was queued.');
        Queue::assertPushed(SyncCompressionData::class, 1);

        $this->post(route('buyback.admin.reference-data.sync'))
            ->assertSessionHas('status', 'A compression data synchronization is already queued.');
        Queue::assertPushed(SyncCompressionData::class, 1);

        $sync = new RecordingCompressionSync();
        (new SyncCompressionData())->handle($sync, $this->app->make(CacheManager::class));
        self::assertSame(1, $sync->calls);
    }

    public function test_stale_reference_data_renders_last_known_good_and_normalized_failure_only(): void
    {
        $this->usableCompressionData('Safe normalized failure');

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.reference-data.index'))
            ->assertOk()
            ->assertSee('STALE')
            ->assertSee('Using last-known-good mapping.')
            ->assertSee('Safe normalized failure')
            ->assertDontSee('RuntimeException');
    }

    public function test_missing_reference_data_renders_missing_health(): void
    {
        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.reference-data.index'))
            ->assertOk()
            ->assertSee('MISSING')
            ->assertSee('Never');
    }

    public function test_admin_mutations_are_not_exposed_as_get_routes(): void
    {
        $program = $this->program();
        $rule = $program->rules()->create($this->storedRule(['acceptance' => 'REJECT']));

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->get(route('buyback.admin.reference-data.sync'))
            ->assertMethodNotAllowed();
        $this->get(route('buyback.admin.programs.archive', $program))
            ->assertMethodNotAllowed();
        $this->get(route('buyback.admin.programs.rules.archive', [$program, $rule]))
            ->assertMethodNotAllowed();
    }

    public function test_program_archival_removes_requester_selection_without_mutating_historical_quote(): void
    {
        $program = $this->program(ProgramStatus::ENABLED);
        $quote = BuybackQuote::query()->create([
            'appraisal_token_hash' => hash('sha256', 'historical'),
            'program_id' => $program->id,
            'requester_user_id' => 77,
            'requester_name_snapshot' => 'Historical User',
            'program_name_snapshot' => 'Mineral Buyback',
            'pricing_completed_at' => now(),
            'quoted_at' => now(),
            'expires_at' => now()->addHour(),
            'payable_total' => '123.45',
        ]);
        $before = [
            'program_name_snapshot' => $quote->program_name_snapshot,
            'payable_total' => $quote->payable_total,
            'appraisal_token_hash' => $quote->getRawOriginal('appraisal_token_hash'),
        ];

        $this->actingAs($this->user(42, ['randulfthegrey-buyback.admin']))
            ->patch(route('buyback.admin.programs.archive', $program))
            ->assertRedirect(route('buyback.admin.programs.index'))
            ->assertSessionHas('success', 'Program archived. Existing Quotes and Buyback Requests are unaffected.');

        self::assertSame(ProgramStatus::ARCHIVED, $program->fresh()->status);
        $historical = $quote->fresh();
        self::assertSame($before['program_name_snapshot'], $historical->program_name_snapshot);
        self::assertSame($before['payable_total'], $historical->payable_total);
        self::assertSame($before['appraisal_token_hash'], $historical->getRawOriginal('appraisal_token_hash'));
        $this->actingAs($this->user(77, ['randulfthegrey-buyback.request']))
            ->get(route('buyback.programs.index'))
            ->assertOk()
            ->assertDontSee('Mineral Buyback');
    }

    /** @param array<string, mixed> $overrides */
    private function programPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Mineral Buyback',
            'description' => 'Minerals',
            'status' => 'DISABLED',
            'quote_validity_minutes' => 30,
            'contract_instructions' => null,
            'default_acceptance' => 'ACCEPT',
            'default_reference_mode' => 'BUY',
            'default_modifier_direction' => 'none',
            'default_modifier_percentage' => '0',
            'buy_provider_instance_id' => null,
            'sell_provider_instance_id' => null,
            'split_resolution' => 'DERIVED_MIDPOINT',
            'split_provider_instance_id' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function rulePayload(array $overrides = []): array
    {
        return array_replace([
            'target_type' => 'GROUP',
            'target_id' => 18,
            'compression_qualifier' => 'ANY',
            'acceptance' => 'INHERIT',
            'reference_mode_override' => 'INHERIT',
            'modifier_operation' => 'INHERIT',
            'modifier_direction' => 'none',
            'modifier_percentage' => '0',
            'rule_status' => 'ENABLED',
            'admin_note' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function storedRule(array $overrides = []): array
    {
        return array_replace([
            'target_type' => 'GROUP',
            'target_id' => 18,
            'compression_qualifier' => 'ANY',
            'acceptance' => 'INHERIT',
            'reference_mode_override' => null,
            'modifier_operation' => 'INHERIT',
            'modifier_bps' => null,
            'enabled' => true,
        ], $overrides);
    }

    private function program(ProgramStatus $status = ProgramStatus::DISABLED): BuybackProgram
    {
        return BuybackProgram::query()->create([
            'name' => 'Mineral Buyback',
            'status' => $status,
            'default_acceptance' => Acceptance::ACCEPT,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 30,
        ]);
    }

    private function provider(string $name): int
    {
        return (int) \RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance::query()->insertGetId([
            'name' => $name,
            'backend' => 'opaque-test-backend',
            'configuration' => '{}',
        ]);
    }

    private function references(BuybackProgram $program, int $buy, int $sell): void
    {
        $program->priceReferences()->createMany([
            ['reference_mode' => 'BUY', 'resolution' => 'PROVIDER', 'provider_instance_id' => $buy],
            ['reference_mode' => 'SELL', 'resolution' => 'PROVIDER', 'provider_instance_id' => $sell],
            ['reference_mode' => 'SPLIT', 'resolution' => 'DERIVED_MIDPOINT', 'provider_instance_id' => null],
        ]);
    }

    private function usableCompressionData(?string $failure = null): void
    {
        CompressionMapping::query()->create(['uncompressed_type_id' => 33, 'compressed_type_id' => 34]);
        CompressionMapping::query()->create(['uncompressed_type_id' => 37, 'compressed_type_id' => 38]);
        CompressionMetadata::query()->create([
            'id' => 1,
            'active_sde_build' => '2026-09-11',
            'source' => 'CCP Static Data Export',
            'source_metadata' => $failure === null ? [] : ['last_refresh_failure' => ['code' => 'DOWNLOAD_FAILURE']],
            'imported_at' => CarbonImmutable::now(),
            'last_checked_at' => CarbonImmutable::now(),
            'last_error_at' => $failure === null ? null : CarbonImmutable::now(),
            'last_error_message' => $failure,
        ]);
    }

    private function seedSde(): void
    {
        \DB::table('invGroups')->insert([
            ['groupID' => 18, 'categoryID' => 4, 'groupName' => 'Mineral'],
            ['groupID' => 19, 'categoryID' => 4, 'groupName' => 'Ore'],
            ['groupID' => 20, 'categoryID' => 4, 'groupName' => 'Raw Only'],
            ['groupID' => 21, 'categoryID' => 4, 'groupName' => 'Compressed Only'],
        ]);
        \DB::table('invTypes')->insert([
            ['typeID' => 33, 'groupID' => 18, 'typeName' => 'Uncompressed Tritanium', 'published' => true],
            ['typeID' => 34, 'groupID' => 18, 'typeName' => 'Tritanium', 'published' => true],
            ['typeID' => 35, 'groupID' => 18, 'typeName' => 'Pyerite', 'published' => true],
            ['typeID' => 36, 'groupID' => 19, 'typeName' => 'Veldspar', 'published' => true],
            ['typeID' => 37, 'groupID' => 20, 'typeName' => 'Raw Test Ore', 'published' => true],
            ['typeID' => 38, 'groupID' => 21, 'typeName' => 'Compressed Test Ore', 'published' => true],
            ['typeID' => 99, 'groupID' => 19, 'typeName' => 'Secret Unpublished Type', 'published' => false],
        ]);
    }

    /** @param list<string> $permissions */
    private function user(int $id, array $permissions): AdminUiUser
    {
        return new AdminUiUser($id, $permissions);
    }
}

final class AdminUiUser extends Authenticatable
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(int $id = 0, array $permissions = [])
    {
        $this->permissions = $permissions;
        parent::__construct();
        $this->setAttribute($this->getAuthIdentifierName(), $id);
        $this->setAttribute('name', 'Admin ' . $id);
    }
}

final class RecordingCompressionSync implements CompressionSync
{
    public int $calls = 0;

    public function synchronize(): CompressionSyncResult
    {
        $this->calls++;

        return new CompressionSyncResult(CompressionSyncOutcome::CURRENT);
    }
}
