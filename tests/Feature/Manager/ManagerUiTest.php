<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Manager;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuoteItem;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class ManagerUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        Carbon::setTestNow('2026-09-12 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-12 12:00:00 UTC');
        View::addNamespace('web', dirname(__DIR__, 2) . '/Fixtures/views');

        foreach (['buyback.request', 'buyback.manage', 'buyback.admin'] as $permission) {
            Gate::define($permission, static fn (ManagerUiUser $user): bool =>
                in_array($permission, $user->permissions, true));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_manager_queue_is_global_server_side_paginated_and_pending_first(): void
    {
        $program = $this->program('Historical Ore Program');
        $completed = $this->request(42, $program, BuybackRequestStatus::COMPLETED, '2026-09-12 11:59:00');
        $pending = $this->request(43, $program, BuybackRequestStatus::PENDING, '2026-09-12 10:00:00');

        foreach ([['buyback.request'], ['buyback.admin']] as $permissions) {
            $this->actingAs($this->user(99, $permissions))
                ->get(route('buyback.manage.requests.index'))
                ->assertForbidden();
        }

        $response = $this->actingAs($this->user(501, ['buyback.manage']))
            ->withHeaders($this->dataTableHeaders())
            ->get(route('buyback.manage.requests.index', $this->dataTableParams(length: 1)))
            ->assertOk();

        self::assertSame(2, $response->json('recordsTotal'));
        self::assertCount(1, $response->json('data'));
        self::assertStringContainsString($pending->public_id, $response->json('data.0.public_id'));
        self::assertStringNotContainsString($completed->public_id, $response->getContent());
        self::assertSame('Capsuleer 43', $response->json('data.0.requester_name_snapshot'));
        self::assertSame('PENDING', strip_tags((string) $response->json('data.0.status')));
    }

    public function test_manager_queue_filters_are_sql_scoped_and_preserve_global_authorization(): void
    {
        $ore = $this->program('Ore Program');
        $mineral = $this->program('Mineral Program');
        $wanted = $this->request(42, $ore, submittedAt: '2026-09-11 08:00:00');
        $this->request(43, $ore, BuybackRequestStatus::REJECTED, '2026-09-12 08:00:00');
        $this->request(44, $mineral, submittedAt: '2026-09-11 08:00:00');

        $response = $this->actingAs($this->user(501, ['buyback.manage']))
            ->withHeaders($this->dataTableHeaders())
            ->get(route('buyback.manage.requests.index', $this->dataTableParams() + [
                'status' => 'PENDING',
                'program_id' => $ore->id,
                'requester' => 'Capsuleer 42',
                'submitted_from' => '2026-09-11',
                'submitted_to' => '2026-09-11',
            ]))
            ->assertOk();

        self::assertSame(1, $response->json('recordsFiltered'));
        self::assertStringContainsString($wanted->public_id, $response->json('data.0.public_id'));
    }

    public function test_manager_detail_uses_immutable_snapshots_and_exposes_only_manager_controls(): void
    {
        $program = $this->program('Current Program Name');
        $request = $this->request(42, $program, requesterNote: 'Requester-visible note', managerNote: 'Internal manager context');
        $program->update(['name' => 'Renamed after Quote']);

        $response = $this->actingAs($this->user(501, ['buyback.manage']))
            ->get(route('buyback.manage.requests.show', $request))
            ->assertOk()
            ->assertSee('Historical Current Program Name')
            ->assertDontSee('Renamed after Quote')
            ->assertSee('Capsuleer 42')
            ->assertSee('Requester-visible note')
            ->assertSee('Internal manager context')
            ->assertSee('Tritanium')
            ->assertSee('10.005000000000000000')
            ->assertSee('Provider at appraisal')
            ->assertSee('No EVE contract ID is recorded')
            ->assertSee('Complete Request')
            ->assertSee('Reject Request')
            ->assertSee('name="_token"', escape: false)
            ->assertSee('name="_method" value="PATCH"', escape: false);

        self::assertTrue($response->viewData('buybackRequest')->relationLoaded('quote'));
        self::assertTrue($response->viewData('buybackRequest')->quote->relationLoaded('items'));
        self::assertTrue($response->viewData('buybackRequest')->quote->relationLoaded('program'));
    }

    public function test_requester_collection_and_detail_never_disclose_manager_note(): void
    {
        $request = $this->request(
            42,
            $this->program(),
            requesterNote: 'Requester message',
            managerNote: 'Manager-only secret',
        );

        $response = $this->actingAs($this->user(42, ['buyback.request']))
            ->withHeaders($this->dataTableHeaders())
            ->get(route('buyback.requests.index', $this->dataTableParams()))
            ->assertOk();
        self::assertStringNotContainsString('Manager-only secret', $response->getContent());
        self::assertArrayNotHasKey('manager_note', $response->json('data.0'));

        $this->get(route('buyback.requests.show', $request))
            ->assertOk()
            ->assertSee('Requester message')
            ->assertDontSee('Manager-only secret');

        $this->actingAs($this->user(43, ['buyback.request']))
            ->get(route('buyback.requests.show', $request))
            ->assertNotFound();
    }

    public function test_manager_pending_edits_and_completion_use_services_without_quote_mutation(): void
    {
        $request = $this->request(42, $this->program());
        $quoteFields = ['payable_total', 'program_name_snapshot'];
        $itemFields = [
            'quantity', 'reference_unit_price', 'effective_modifier_bps', 'final_unit_price', 'line_total',
            'policy_snapshot', 'pricing_snapshot',
        ];
        $originalQuote = $request->quote->getRawOriginal();
        $originalItem = $request->quote->items()->firstOrFail()->getRawOriginal();
        $manager = $this->user(501, ['buyback.manage']);

        $this->actingAs($manager)
            ->patch(route('buyback.manage.requests.contract.update', $request), ['eve_contract_id' => 987654321])
            ->assertRedirect(route('buyback.manage.requests.show', $request))
            ->assertSessionHas('success', 'EVE contract ID updated.');
        $this->patch(route('buyback.manage.requests.manager-note.update', $request), ['manager_note' => 'Ready to settle'])
            ->assertRedirect(route('buyback.manage.requests.show', $request));
        $this->post(route('buyback.manage.requests.complete', $request))
            ->assertRedirect(route('buyback.manage.requests.show', $request))
            ->assertSessionHas('success', 'Buyback Request completed.');

        $terminal = $request->fresh('quote.items');
        self::assertSame(BuybackRequestStatus::COMPLETED, $terminal->status);
        self::assertSame(987654321, $terminal->eve_contract_id);
        self::assertSame('Ready to settle', $terminal->manager_note);
        self::assertSame(501, $terminal->completed_by_user_id);
        self::assertNotNull($terminal->completed_at);
        foreach ($quoteFields as $field) {
            self::assertSame($originalQuote[$field], $terminal->quote->getRawOriginal($field));
        }
        foreach ($itemFields as $field) {
            self::assertSame($originalItem[$field], $terminal->quote->items->sole()->getRawOriginal($field));
        }

        $this->get(route('buyback.manage.requests.show', $terminal))
            ->assertOk()
            ->assertSee('final and read-only')
            ->assertDontSee('Update contract ID')
            ->assertDontSee('Update manager note')
            ->assertDontSee('Complete Request</button>', escape: false)
            ->assertDontSee('Reject Request</button>', escape: false);

        $this->patchJson(route('buyback.manage.requests.manager-note.update', $terminal), [
            'manager_note' => 'Too late',
        ])->assertForbidden();
    }

    public function test_manager_rejection_requires_reason_and_requester_sees_reason_not_manager_note(): void
    {
        $request = $this->request(42, $this->program(), managerNote: 'Private investigation');
        $manager = $this->user(501, ['buyback.manage']);

        $this->actingAs($manager)
            ->post(route('buyback.manage.requests.reject', $request), ['rejection_reason' => '   '])
            ->assertSessionHasErrors('rejection_reason');
        self::assertSame(BuybackRequestStatus::PENDING, $request->fresh()->status);

        $this->post(route('buyback.manage.requests.reject', $request), [
            'rejection_reason' => 'The contract contents differ from the Quote.',
        ])->assertRedirect(route('buyback.manage.requests.show', $request));

        $request->refresh();
        self::assertSame(BuybackRequestStatus::REJECTED, $request->status);
        self::assertSame(501, $request->rejected_by_user_id);

        $this->actingAs($this->user(42, ['buyback.request']))
            ->get(route('buyback.requests.show', $request))
            ->assertOk()
            ->assertSee('The contract contents differ from the Quote.')
            ->assertDontSee('Private investigation');
    }

    public function test_canceled_request_is_clearly_read_only_for_manager(): void
    {
        $request = $this->request(42, $this->program(), BuybackRequestStatus::CANCELED);

        $this->actingAs($this->user(501, ['buyback.manage']))
            ->get(route('buyback.manage.requests.show', $request))
            ->assertOk()
            ->assertSee('Requester withdrawal')
            ->assertSee('canceled by the requester')
            ->assertSee('final and read-only')
            ->assertDontSee('Complete Request</button>', escape: false)
            ->assertDontSee('Reject Request</button>', escape: false);
    }

    #[DataProvider('permissionMatrix')]
    public function test_cross_permission_matrix(
        array $permissions,
        bool $requesterAllowed,
        bool $managerAllowed,
        bool $adminAllowed,
    ): void {
        $user = $this->user(9000, $permissions);

        $this->actingAs($user)->get(route('buyback.programs.index'))
            ->assertStatus($requesterAllowed ? 200 : 403);
        $this->get(route('buyback.manage.requests.index'))
            ->assertStatus($managerAllowed ? 200 : 403);
        $this->get(route('buyback.admin.programs.index'))
            ->assertStatus($adminAllowed ? 200 : 403);

        $request = $this->request(42, $this->program());
        $this->postJson(route('buyback.manage.requests.complete', $request))
            ->assertStatus($managerAllowed ? 200 : 403);
    }

    /** @return iterable<string, array{list<string>, bool, bool, bool}> */
    public static function permissionMatrix(): iterable
    {
        yield 'request only' => [['buyback.request'], true, false, false];
        yield 'manage only' => [['buyback.manage'], false, true, false];
        yield 'admin only' => [['buyback.admin'], false, false, true];
        yield 'request + manage' => [['buyback.request', 'buyback.manage'], true, true, false];
        yield 'manage + admin' => [['buyback.manage', 'buyback.admin'], false, true, true];
        yield 'request + admin' => [['buyback.request', 'buyback.admin'], true, false, true];
        yield 'all three' => [['buyback.request', 'buyback.manage', 'buyback.admin'], true, true, true];
    }

    public function test_manager_routes_and_navigation_use_independent_permission_and_safe_http_methods(): void
    {
        $routes = $this->app['router']->getRoutes();
        $expectations = [
            'buyback.manage.requests.index' => ['GET', 'HEAD'],
            'buyback.manage.requests.show' => ['GET', 'HEAD'],
            'buyback.manage.requests.contract.update' => ['PATCH'],
            'buyback.manage.requests.manager-note.update' => ['PATCH'],
            'buyback.manage.requests.complete' => ['POST'],
            'buyback.manage.requests.reject' => ['POST'],
        ];

        foreach ($expectations as $name => $methods) {
            $route = $routes->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->methods(), $name);
            self::assertSame(['web', 'auth', 'can:buyback.manage'], $route->gatherMiddleware(), $name);
        }

        self::assertSame('buyback.manage', config('package.sidebar.buyback-management.permission'));
        self::assertSame('buyback.manage', config('package.sidebar.buyback-management.entries.0.permission'));
        self::assertSame('buyback-manage', config('package.sidebar.buyback-management.route_segment'));
        self::assertSame('buyback.admin', config('package.sidebar.buyback-administration.permission'));

        self::assertSame('buyback/requests', $routes->getByName('buyback.requests.index')?->uri());
        self::assertSame('buyback-manage/requests', $routes->getByName('buyback.manage.requests.index')?->uri());
        self::assertSame('buyback-admin/programs', $routes->getByName('buyback.admin.programs.index')?->uri());
    }

    public function test_stale_manager_action_returns_normalized_conflict_without_internal_details(): void
    {
        $request = $this->request(42, $this->program());
        $stale = $request->fresh();
        BuybackRequest::query()->whereKey($request->id)->update([
            'status' => BuybackRequestStatus::CANCELED->value,
            'canceled_at' => now(),
            'canceled_by_user_id' => 42,
        ]);
        $this->app['router']->bind('buybackRequest', static fn (): BuybackRequest => $stale);

        $this->actingAs($this->user(501, ['buyback.manage']))
            ->postJson(route('buyback.manage.requests.complete', $request))
            ->assertConflict()
            ->assertJsonPath('error.code', 'REQUEST_NOT_PENDING')
            ->assertJsonPath('error.message', 'This Buyback Request is no longer pending.')
            ->assertJsonMissingPath('exception');

        $this->post(route('buyback.manage.requests.complete', $request))
            ->assertRedirect(route('buyback.manage.requests.show', $stale))
            ->assertSessionHas('error', 'This Buyback Request is no longer pending.');
    }

    private function program(string $name = 'Ore Program'): BuybackProgram
    {
        return BuybackProgram::query()->create([
            'name' => $name,
            'status' => ProgramStatus::ENABLED,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 30,
        ]);
    }

    private function request(
        int $requesterUserId,
        BuybackProgram $program,
        BuybackRequestStatus $status = BuybackRequestStatus::PENDING,
        string $submittedAt = '2026-09-12 10:00:00',
        ?string $requesterNote = null,
        ?string $managerNote = null,
    ): BuybackRequest {
        $quote = BuybackQuote::query()->create([
            'appraisal_token_hash' => hash('sha256', (string) Str::uuid()),
            'program_id' => $program->id,
            'requester_user_id' => $requesterUserId,
            'requester_name_snapshot' => 'Capsuleer ' . $requesterUserId,
            'program_name_snapshot' => 'Historical ' . $program->name,
            'pricing_completed_at' => '2026-09-12 09:00:00',
            'quoted_at' => '2026-09-12 09:00:00',
            'expires_at' => '2026-09-12 13:00:00',
            'payable_total' => '30.03',
        ]);
        BuybackQuoteItem::query()->create([
            'quote_id' => $quote->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'quantity' => 3,
            'compression_state' => CompressionState::NOT_APPLICABLE,
            'reference_mode' => ReferenceMode::BUY,
            'reference_resolution' => ReferenceResolution::PROVIDER,
            'reference_unit_price' => '10.005000000000000000',
            'effective_modifier_bps' => 0,
            'final_unit_price' => '10.01',
            'line_total' => '30.03',
            'policy_snapshot' => [
                'schema_version' => 1,
                'baseline' => [
                    'acceptance' => ['value' => 'ACCEPT'],
                    'reference_mode' => ['value' => 'BUY'],
                    'modifier' => ['value_bps' => 0],
                ],
                'layers' => [],
                'acceptance' => 'ACCEPT',
                'reference_mode' => 'BUY',
                'effective_modifier_bps' => 0,
            ],
            'pricing_snapshot' => [
                'schema_version' => 1,
                'logical_mode' => 'BUY',
                'resolution' => 'PROVIDER',
                'components' => [[
                    'mode' => 'BUY',
                    'provider_instance_id' => 99,
                    'provider_instance_name' => 'Provider at appraisal',
                    'reference_price' => '10.005000000000000000',
                ]],
            ],
        ]);

        $terminal = match ($status) {
            BuybackRequestStatus::COMPLETED => ['completed_at' => $submittedAt, 'completed_by_user_id' => 501],
            BuybackRequestStatus::REJECTED => [
                'rejected_at' => $submittedAt,
                'rejected_by_user_id' => 501,
                'rejection_reason' => 'Contract mismatch',
            ],
            BuybackRequestStatus::CANCELED => ['canceled_at' => $submittedAt, 'canceled_by_user_id' => $requesterUserId],
            BuybackRequestStatus::PENDING => [],
        };

        return BuybackRequest::query()->create([
            'quote_id' => $quote->id,
            'status' => $status,
            'submitted_at' => $submittedAt,
            'requester_note' => $requesterNote,
            'manager_note' => $managerNote,
        ] + $terminal)->load('quote.items');
    }

    /** @return array<string, string> */
    private function dataTableHeaders(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
    }

    /** @return array<string, mixed> */
    private function dataTableParams(int $length = 10): array
    {
        return [
            'draw' => 1,
            'start' => 0,
            'length' => $length,
            'columns' => [],
        ];
    }

    /** @param list<string> $permissions */
    private function user(int $id, array $permissions): ManagerUiUser
    {
        return new ManagerUiUser($id, $permissions);
    }
}

final class ManagerUiUser extends Authenticatable
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(int $id = 0, array $permissions = [])
    {
        $this->permissions = $permissions;
        parent::__construct();
        $this->setAttribute($this->getAuthIdentifierName(), $id);
        $this->setAttribute('name', 'User ' . $id);
    }
}
