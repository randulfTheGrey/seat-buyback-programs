<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Requester;

use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalLine;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ResolvedInventoryType;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderPrice;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\QuoteState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuoteItem;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class RequesterUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('u', 32)));
        Carbon::setTestNow('2026-09-11 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');
        View::addNamespace('web', dirname(__DIR__, 2) . '/Fixtures/views');

        Gate::define('buyback.request', static fn (RequesterUiUser $user): bool =>
            in_array('buyback.request', $user->permissions, true));
        Gate::define('buyback.manage', static fn (RequesterUiUser $user): bool =>
            in_array('buyback.manage', $user->permissions, true));
        Gate::define('buyback.admin', static fn (RequesterUiUser $user): bool =>
            in_array('buyback.admin', $user->permissions, true));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_program_landing_shows_enabled_programs_only_and_requires_request_permission(): void
    {
        $enabled = $this->program('Visible enabled Program', ProgramStatus::ENABLED);
        $disabled = $this->program('Hidden disabled Program', ProgramStatus::DISABLED);
        $this->program('Hidden archived Program', ProgramStatus::ARCHIVED);

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.programs.index'))
            ->assertOk()
            ->assertSee('Visible enabled Program')
            ->assertDontSee('Hidden disabled Program')
            ->assertDontSee('Hidden archived Program');

        $this->get(route('buyback.appraisals.create', $enabled))
            ->assertOk()
            ->assertSee('Paste your EVE item list')
            ->assertSee('Run Appraisal');
        $this->get(route('buyback.appraisals.create', $disabled))
            ->assertNotFound();

        $this->actingAs($this->user(43, ['buyback.manage']))
            ->get(route('buyback.programs.index'))
            ->assertForbidden();

        $this->actingAs($this->user(44, ['buyback.admin']))
            ->get(route('buyback.programs.index'))
            ->assertForbidden();
    }

    public function test_appraisal_result_renders_every_outcome_and_production_policy_explanation(): void
    {
        $program = $this->program();
        $lines = [
            $this->appraisalLine(AppraisalLineStatus::PRICED, 34, 'Tritanium'),
            $this->appraisalLine(AppraisalLineStatus::EXCLUDED, 35, 'Pyerite'),
            $this->appraisalLine(AppraisalLineStatus::UNPRICED, 36, 'Mexallon'),
            $this->appraisalLine(AppraisalLineStatus::REFERENCE_UNAVAILABLE, 37, 'Isogen'),
            new AppraisalLine(5, AppraisalLineStatus::UNKNOWN, ['Almost Tritanium 2'], 'Almost Tritanium', quantity: 2),
            new AppraisalLine(6, AppraisalLineStatus::INVALID_INPUT, ['Nocxium nope'], 'Nocxium', InventoryInputError::MALFORMED_QUANTITY),
        ];
        $appraisal = new AppraisalResult(
            (int) $program->id,
            (string) $program->name,
            42,
            'original input',
            CarbonImmutable::now(),
            CarbonImmutable::now()->addMinutes(30),
            $lines,
            '21.00',
        );
        $counts = $appraisal->summaryCounts();
        $html = view('seat-buyback-programs::appraisals.result', [
            'appraisalToken' => str_repeat('a', 64),
            'appraisal' => $appraisal,
            'program' => $program,
            'counts' => $counts,
            'resolvedCount' => 4,
            'linesByStatus' => collect($lines)->groupBy(static fn (AppraisalLine $line): string => $line->status->value),
        ])->render();

        self::assertStringContainsString('Priced and payable', $html);
        self::assertStringContainsString('Excluded by Program policy', $html);
        self::assertStringContainsString('Accepted but unpriced', $html);
        self::assertStringContainsString('Reference temporarily unavailable', $html);
        self::assertStringContainsString('Almost Tritanium 2', $html);
        self::assertStringContainsString('Invalid quantity', $html);
        self::assertStringContainsString('Why this price?', $html);
        self::assertStringContainsString('Program defaults', $html);
        self::assertStringContainsString('Item rule', $html);
        self::assertStringContainsString('5% premium', $html);
        self::assertStringContainsString('name="appraisal_token"', $html);
        self::assertStringNotContainsString('name="line_total"', $html);
        self::assertStringNotContainsString('name="final_unit_price"', $html);
    }

    public function test_permitted_requester_submits_through_server_appraisal_pipeline_without_client_money_authority(): void
    {
        config()->set('cache.default', 'array');
        $this->app->instance(InventoryTypeResolver::class, new RequesterUiTypeResolver());
        $this->app->instance(SeatPriceProviderGateway::class, new RequesterUiPriceGateway());
        $program = $this->program();
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

        $response = $this->actingAs($this->user(42, ['buyback.request']))
            ->post(route('buyback.appraisals.store', $program), [
                'inventory' => 'Tritanium 2',
                'final_unit_price' => '999999.99',
                'quoteable_total' => '1999999.98',
            ])
            ->assertOk()
            ->assertViewIs('seat-buyback-programs::appraisals.result')
            ->assertSee('9.00 ISK')
            ->assertSee('18.00 ISK')
            ->assertDontSee('999999.99');

        $quoteResponse = $this->post(route('buyback.quotes.store'), [
            'appraisal_token' => $response->viewData('appraisalToken'),
            'final_unit_price' => '999999.99',
            'quoteable_total' => '1999999.98',
        ]);
        $quote = BuybackQuote::query()->with('items')->firstOrFail();

        $quoteResponse->assertRedirect(route('buyback.quotes.show', $quote));
        self::assertSame('18.00', $quote->payable_total);
        self::assertSame('9.00', $quote->items->sole()->final_unit_price);
    }

    public function test_zero_payable_appraisal_does_not_offer_quote_creation(): void
    {
        $program = $this->program();
        $line = $this->appraisalLine(AppraisalLineStatus::EXCLUDED, 35, 'Pyerite');
        $appraisal = new AppraisalResult(
            (int) $program->id,
            (string) $program->name,
            42,
            'Pyerite 1',
            CarbonImmutable::now(),
            CarbonImmutable::now()->addMinutes(30),
            [$line],
            '0.00',
        );
        $counts = $appraisal->summaryCounts();
        $html = view('seat-buyback-programs::appraisals.result', [
            'appraisalToken' => str_repeat('a', 64),
            'appraisal' => $appraisal,
            'program' => $program,
            'counts' => $counts,
            'resolvedCount' => 1,
            'linesByStatus' => collect([$line])->groupBy(static fn (AppraisalLine $line): string => $line->status->value),
        ])->render();

        self::assertStringContainsString('No payable items are available', $html);
        self::assertStringNotContainsString('Create Quote</button>', $html);
    }

    public function test_quote_pages_are_owner_only_payable_only_and_derive_state_without_program_status_gate(): void
    {
        $quote = $this->quote(42, ProgramStatus::ARCHIVED);

        $this->actingAs($this->user(43, ['buyback.request']))
            ->get(route('buyback.quotes.show', $quote))
            ->assertNotFound();

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.quotes.show', $quote))
            ->assertOk()
            ->assertSee('Available')
            ->assertSee('Submit Buyback')
            ->assertSee('Tritanium')
            ->assertSee('Contract only the quoted items', escape: false)
            ->assertDontSee('EXCLUDED');

        $quote = $this->quote(42, ProgramStatus::ENABLED, '2026-09-11 12:00:00');
        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.quotes.show', $quote))
            ->assertOk()
            ->assertSee('This Quote has expired')
            ->assertDontSee('Submit Buyback</button>', escape: false);
    }

    public function test_valid_quote_submits_after_program_disable_and_duplicate_post_reuses_request(): void
    {
        $quote = $this->quote(42, ProgramStatus::DISABLED);
        $user = $this->user(42, ['buyback.request']);

        $first = $this->actingAs($user)->post(route('buyback.quotes.submit', $quote), [
            'eve_contract_id' => '987654321',
            'requester_note' => 'Contract is ready',
        ]);
        $buybackRequest = BuybackRequest::query()->firstOrFail();
        $first->assertRedirect(route('buyback.requests.show', $buybackRequest));

        $this->post(route('buyback.quotes.submit', $quote), [])
            ->assertRedirect(route('buyback.requests.show', $buybackRequest));

        $this->get(route('buyback.quotes.show', $quote))
            ->assertOk()
            ->assertSee('Submitted')
            ->assertSee('View Buyback Request');

        self::assertSame(1, BuybackRequest::query()->count());
        self::assertSame(987654321, $buybackRequest->fresh()->eve_contract_id);
    }

    public function test_my_buybacks_datatable_is_query_scoped_to_authenticated_requester(): void
    {
        $own = $this->request(42);
        $other = $this->request(43);

        $this->actingAs($this->user(501, ['buyback.manage']))
            ->get(route('buyback.requests.index'))
            ->assertForbidden();
        $this->actingAs($this->user(601, ['buyback.admin']))
            ->get(route('buyback.requests.index'))
            ->assertForbidden();

        $response = $this->actingAs($this->user(42, ['buyback.request']))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get(route('buyback.requests.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'columns' => [],
            ]));

        $response->assertOk();
        self::assertSame([$own->public_id], collect($response->json('data'))->pluck('public_id')->all());
        self::assertStringNotContainsString($other->public_id, $response->getContent());
    }

    public function test_my_buybacks_page_renders_the_server_side_datatable(): void
    {
        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.index'))
            ->assertOk()
            ->assertSee('submitted Buyback Requests')
            ->assertSee('Request reference')
            ->assertSee('"order":[[2,"desc"]]', escape: false);
    }

    public function test_my_buybacks_page_lists_only_the_requesters_available_quotes(): void
    {
        $availableLater = $this->quote(42, expiresAt: '2026-09-11 12:45:00');
        $availableSooner = $this->quote(42, ProgramStatus::DISABLED, '2026-09-11 12:15:00');
        $otherRequester = $this->quote(43);
        $expired = $this->quote(42, expiresAt: '2026-09-11 12:00:00');
        $submitted = $this->request(42);

        $response = $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.index'))
            ->assertOk()
            ->assertSee('Available Quotes')
            ->assertSee('Continue a Quote')
            ->assertSee($availableSooner->public_id)
            ->assertSee(route('buyback.quotes.show', $availableSooner), escape: false)
            ->assertSee($availableLater->public_id)
            ->assertDontSee($otherRequester->public_id)
            ->assertDontSee($expired->public_id)
            ->assertDontSee($submitted->quote->public_id);

        self::assertSame(
            [$availableSooner->public_id, $availableLater->public_id],
            $response->viewData('availableQuotes')->pluck('public_id')->all(),
        );
    }

    public function test_my_buybacks_hides_available_quotes_panel_when_none_are_available(): void
    {
        $this->quote(42, expiresAt: '2026-09-11 12:00:00');
        $this->request(42);
        $this->quote(43);

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.index'))
            ->assertOk()
            ->assertDontSee('Available Quotes')
            ->assertSee('submitted Buyback Requests');
    }

    public function test_request_detail_never_leaks_manager_note_and_pending_controls_become_read_only(): void
    {
        $buybackRequest = $this->request(42, 'Requester-visible note', 'Manager secret');

        $this->actingAs($this->user(43, ['buyback.request']))
            ->get(route('buyback.requests.show', $buybackRequest))
            ->assertNotFound();

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.show', $buybackRequest))
            ->assertOk()
            ->assertSee('Requester-visible note')
            ->assertDontSee('Manager secret')
            ->assertSee('Update contract ID')
            ->assertSee('Update note')
            ->assertSee('Cancel Buyback');

        $this->patch(route('buyback.requests.contract.update', $buybackRequest), ['eve_contract_id' => 123456])
            ->assertRedirect(route('buyback.requests.show', $buybackRequest));
        $this->patch(route('buyback.requests.requester-note.update', $buybackRequest), ['requester_note' => 'Updated note'])
            ->assertRedirect(route('buyback.requests.show', $buybackRequest));
        $this->post(route('buyback.requests.cancel', $buybackRequest))
            ->assertRedirect(route('buyback.requests.show', $buybackRequest));

        $buybackRequest->refresh();
        self::assertSame(BuybackRequestStatus::CANCELED, $buybackRequest->status);
        self::assertSame(123456, $buybackRequest->eve_contract_id);
        self::assertSame('Updated note', $buybackRequest->requester_note);

        $this->get(route('buyback.requests.show', $buybackRequest))
            ->assertOk()
            ->assertSee('final and read-only')
            ->assertDontSee('Update contract ID')
            ->assertDontSee('Update note')
            ->assertDontSee('Cancel Buyback</button>', escape: false);
    }

    public function test_rejected_request_shows_requester_reason_but_not_manager_note(): void
    {
        $buybackRequest = $this->request(42, 'Requester note', 'Manager secret', BuybackRequestStatus::REJECTED);
        $buybackRequest->forceFill([
            'rejection_reason' => 'Contract item mismatch',
            'rejected_at' => CarbonImmutable::now(),
            'rejected_by_user_id' => 99,
        ])->saveQuietly();

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.show', $buybackRequest))
            ->assertOk()
            ->assertSee('Contract item mismatch')
            ->assertDontSee('Manager secret');
    }

    public function test_requester_routes_are_permission_protected_and_mutations_are_never_get(): void
    {
        $routes = $this->app['router']->getRoutes();
        $expectations = [
            'buyback.programs.index' => ['GET', 'HEAD'],
            'buyback.appraisals.create' => ['GET', 'HEAD'],
            'buyback.appraisals.store' => ['POST'],
            'buyback.quotes.store' => ['POST'],
            'buyback.quotes.show' => ['GET', 'HEAD'],
            'buyback.quotes.submit' => ['POST'],
            'buyback.requests.index' => ['GET', 'HEAD'],
            'buyback.requests.show' => ['GET', 'HEAD'],
            'buyback.requests.contract.update' => ['PATCH'],
            'buyback.requests.requester-note.update' => ['PATCH'],
            'buyback.requests.cancel' => ['POST'],
        ];

        foreach ($expectations as $name => $methods) {
            $route = $routes->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->methods(), $name);
            self::assertSame(['web', 'auth', 'can:buyback.request'], $route->gatherMiddleware(), $name);
        }

        self::assertSame('buyback.request', config('package.sidebar.buyback-programs.permission'));
        self::assertSame(
            ['buyback.request', 'buyback.request'],
            array_column(config('package.sidebar.buyback-programs.entries'), 'permission'),
        );
    }

    public function test_requester_presenter_preserves_decimal_strings_and_friendly_modifiers(): void
    {
        self::assertSame('847,321,044.27', RequesterUi::decimal('847321044.27'));
        self::assertSame('10.005000000000000000', RequesterUi::decimal('10.005000000000000000'));
        self::assertSame('10% discount', RequesterUi::modifier(-1000));
        self::assertSame('5% premium', RequesterUi::modifier(500));
        self::assertSame('1.25% premium', RequesterUi::modifier(125));
        self::assertSame('No adjustment', RequesterUi::modifier(0));
    }

    private function user(int $id, array $permissions): RequesterUiUser
    {
        return new RequesterUiUser($id, $permissions);
    }

    private function program(
        string $name = 'Ore Buyback',
        ProgramStatus $status = ProgramStatus::ENABLED,
    ): BuybackProgram {
        return BuybackProgram::create([
            'name' => $name,
            'description' => 'Ore and minerals purchased in Jita.',
            'status' => $status,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => -1000,
            'quote_validity_minutes' => 30,
            'contract_instructions' => 'Create an item exchange contract assigned to the corporation.',
        ]);
    }

    private function quote(
        int $requesterUserId,
        ProgramStatus $programStatus = ProgramStatus::ENABLED,
        string $expiresAt = '2026-09-11 12:30:00',
    ): BuybackQuote {
        $program = $this->program(status: $programStatus);
        $quote = BuybackQuote::create([
            'appraisal_token_hash' => hash('sha256', (string) Str::uuid()),
            'program_id' => $program->id,
            'requester_user_id' => $requesterUserId,
            'requester_name_snapshot' => 'Capsuleer ' . $requesterUserId,
            'program_name_snapshot' => 'Historical Ore Buyback',
            'pricing_completed_at' => '2026-09-11 12:00:00',
            'quoted_at' => '2026-09-11 12:00:00',
            'expires_at' => $expiresAt,
            'payable_total' => '19.00',
        ]);
        BuybackQuoteItem::create([
            'quote_id' => $quote->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'quantity' => 2,
            'compression_state' => CompressionState::NOT_APPLICABLE,
            'reference_mode' => ReferenceMode::BUY,
            'reference_resolution' => ReferenceResolution::PROVIDER,
            'reference_unit_price' => '10.000000000000000000',
            'effective_modifier_bps' => -500,
            'final_unit_price' => '9.50',
            'line_total' => '19.00',
            'policy_snapshot' => $this->policySnapshot(modifierBps: -500),
            'pricing_snapshot' => ['schema_version' => 1],
        ]);

        return $quote->load('items');
    }

    private function request(
        int $requesterUserId,
        ?string $requesterNote = null,
        ?string $managerNote = null,
        BuybackRequestStatus $status = BuybackRequestStatus::PENDING,
    ): BuybackRequest {
        $quote = $this->quote($requesterUserId);

        return BuybackRequest::create([
            'quote_id' => $quote->id,
            'status' => $status,
            'submitted_at' => CarbonImmutable::now(),
            'requester_note' => $requesterNote,
            'manager_note' => $managerNote,
        ])->load('quote');
    }

    private function appraisalLine(AppraisalLineStatus $status, int $typeId, string $typeName): AppraisalLine
    {
        $priced = $status === AppraisalLineStatus::PRICED;

        return new AppraisalLine(
            sourceLineNumber: $typeId - 33,
            status: $status,
            rawLines: [$typeName . ' 2'],
            candidateName: $typeName,
            typeId: $typeId,
            typeName: $typeName,
            groupId: 18,
            quantity: 2,
            compressionState: CompressionState::NOT_APPLICABLE,
            effectivePolicy: $this->policySnapshot($status === AppraisalLineStatus::EXCLUDED ? 'REJECT' : 'ACCEPT'),
            logicalReferenceMode: ReferenceMode::BUY,
            referenceResolution: $priced ? ReferenceResolution::PROVIDER : null,
            pricingProvenance: $priced ? ['schema_version' => 1] : null,
            referenceUnitPrice: $priced ? '10.00' : null,
            effectiveModifierBps: 500,
            finalUnitPrice: $priced ? '10.50' : null,
            lineTotal: $priced ? '21.00' : null,
        );
    }

    /** @return array<string, mixed> */
    private function policySnapshot(string $acceptance = 'ACCEPT', int $modifierBps = 500): array
    {
        return [
            'schema_version' => 1,
            'baseline' => [
                'acceptance' => ['value' => 'ACCEPT'],
                'reference_mode' => ['value' => 'SELL'],
                'modifier' => ['value_bps' => -1000],
            ],
            'layers' => [[
                'source' => 'TYPE',
                'acceptance' => ['applied' => $acceptance === 'REJECT', 'after' => $acceptance],
                'reference_mode' => ['applied' => true, 'after' => 'BUY'],
                'modifier' => ['applied' => true, 'after_bps' => $modifierBps],
            ]],
            'acceptance' => $acceptance,
            'reference_mode' => 'BUY',
            'effective_modifier_bps' => $modifierBps,
        ];
    }
}

final class RequesterUiUser extends Authenticatable
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(int $id = 0, array $permissions = [])
    {
        $this->permissions = $permissions;
        parent::__construct();
        $this->setAttribute($this->getAuthIdentifierName(), $id);
        $this->setAttribute('name', 'Capsuleer ' . $id);
    }
}

final class RequesterUiTypeResolver implements InventoryTypeResolver
{
    public function resolveExact(iterable $candidateNames): array
    {
        $resolved = [];

        foreach ($candidateNames as $candidateName) {
            if ($candidateName === 'Tritanium') {
                $resolved[$candidateName] = new ResolvedInventoryType(34, 'Tritanium', 18);
            }
        }

        return $resolved;
    }
}

final class RequesterUiPriceGateway implements SeatPriceProviderGateway
{
    public function providerInstance(int $providerInstanceId): ?ProviderInstance
    {
        return new ProviderInstance($providerInstanceId, 'Provider ' . $providerInstanceId);
    }

    public function getPrices(int $providerInstanceId, array $typeIds): array
    {
        return array_combine(
            $typeIds,
            array_map(
                static fn (int $typeId): ProviderPrice => new ProviderPrice(
                    $providerInstanceId,
                    $typeId,
                    BigDecimal::of('10.00'),
                ),
                $typeIds,
            ),
        ) ?: [];
    }
}
