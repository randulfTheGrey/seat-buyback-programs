<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Request;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\CancelBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\CompleteBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\RejectBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\SubmitBuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateBuybackContract;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateManagerNote;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateRequesterNote;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestCanceled;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestCompleted;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestRejected;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestSubmitted;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\MissingRejectionReasonException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestNotPendingException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Resources\ManagerBuybackRequestResource;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Resources\RequesterBuybackRequestResource;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuoteItem;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackQuotePolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Policies\BuybackRequestPolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class BuybackRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-11 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('programStatuses')]
    public function test_owned_unexpired_quote_submits_once_independent_of_program_status(
        ProgramStatus $status,
    ): void {
        Event::fake([BuybackRequestSubmitted::class]);
        $quote = $this->quote(programStatus: $status);
        $originalTotal = $quote->payable_total;

        $request = $this->submitter()->submit($quote, 42, 987654321, '  Deliver to Jita  ');

        self::assertTrue($request->wasRecentlyCreated);
        self::assertTrue(Str::isUlid($request->public_id));
        self::assertSame(BuybackRequestStatus::PENDING, $request->status);
        self::assertSame('2026-09-11T12:00:00+00:00', $request->submitted_at->toIso8601String());
        self::assertSame(987654321, $request->eve_contract_id);
        self::assertSame('Deliver to Jita', $request->requester_note);
        self::assertNull($request->manager_note);
        self::assertNull($request->completed_at);
        self::assertNull($request->rejected_at);
        self::assertNull($request->canceled_at);
        self::assertSame($originalTotal, $quote->fresh()->payable_total);
        self::assertSame('10.01', $quote->items()->first()->final_unit_price);
        self::assertDatabaseCount('buyback_requests', 1);
        Event::assertDispatchedTimes(BuybackRequestSubmitted::class, 1);
    }

    /** @return iterable<string, array{ProgramStatus}> */
    public static function programStatuses(): iterable
    {
        yield 'enabled' => [ProgramStatus::ENABLED];
        yield 'disabled' => [ProgramStatus::DISABLED];
        yield 'archived' => [ProgramStatus::ARCHIVED];
    }

    public function test_duplicate_submission_reuses_request_and_emits_no_duplicate_event(): void
    {
        Event::fake([BuybackRequestSubmitted::class]);
        $quote = $this->quote();

        $first = $this->submitter()->submit($quote, 42, null, 'Original note');
        $second = $this->submitter()->submit($quote->fresh(), 42, 123, 'Replacement note');

        self::assertSame($first->id, $second->id);
        self::assertFalse($second->wasRecentlyCreated);
        self::assertNull($second->eve_contract_id);
        self::assertSame('Original note', $second->requester_note);
        self::assertDatabaseCount('buyback_requests', 1);
        Event::assertDispatchedTimes(BuybackRequestSubmitted::class, 1);
    }

    public function test_submission_failure_rolls_back_and_is_normalized_without_event(): void
    {
        Event::fake([BuybackRequestSubmitted::class]);
        $quote = $this->quote();
        BuybackRequest::creating(static function (): never {
            throw new RuntimeException('Simulated persistence failure.');
        });

        try {
            $this->submitter()->submit($quote, 42);
            self::fail('A persistence failure must be normalized.');
        } catch (RequestPersistenceException) {
            self::assertDatabaseCount('buyback_requests', 0);
            Event::assertNotDispatched(BuybackRequestSubmitted::class);
        }
    }

    #[DataProvider('expiredTimes')]
    public function test_quote_at_or_after_expiry_cannot_submit(string $now): void
    {
        Event::fake([BuybackRequestSubmitted::class]);
        $quote = $this->quote(expiresAt: '2026-09-11 12:30:00');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        try {
            $this->submitter()->submit($quote, 42);
            self::fail('An expired Quote must not create a Request.');
        } catch (QuoteExpiredException) {
            self::assertDatabaseCount('buyback_requests', 0);
            Event::assertNotDispatched(BuybackRequestSubmitted::class);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function expiredTimes(): iterable
    {
        yield 'at expiry' => ['2026-09-11 12:30:00 UTC'];
        yield 'after expiry' => ['2026-09-11 12:31:00 UTC'];
    }

    public function test_existing_request_is_reused_after_quote_later_expires(): void
    {
        $quote = $this->quote(expiresAt: '2026-09-11 12:30:00');
        $first = $this->submitter()->submit($quote, 42);
        Carbon::setTestNow('2026-09-11 13:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 13:00:00 UTC');

        self::assertSame($first->id, $this->submitter()->submit($quote, 42)->id);
    }

    public function test_submission_rejects_non_owner_without_creating_or_exposing_request(): void
    {
        $quote = $this->quote();

        try {
            $this->submitter()->submit($quote, 43);
            self::fail('Another requester must not submit the Quote.');
        } catch (QuoteOwnershipException) {
            self::assertDatabaseCount('buyback_requests', 0);
        }
    }

    public function test_pending_fields_are_separate_optional_and_ownership_scope_uses_quote(): void
    {
        $mine = $this->request();
        $other = $this->request(requesterUserId: 43);

        $mine = $this->app->make(UpdateBuybackContract::class)->update($mine, 111);
        $mine = $this->app->make(UpdateRequesterNote::class)->update($mine, 'Requester-visible');
        $mine = $this->app->make(UpdateManagerNote::class)->update($mine, 'Internal only');
        $mine = $this->app->make(UpdateBuybackContract::class)->update($mine, null);

        self::assertNull($mine->eve_contract_id);
        self::assertSame('Requester-visible', $mine->requester_note);
        self::assertSame('Internal only', $mine->manager_note);
        self::assertSame([$mine->id], BuybackRequest::query()->ownedByRequester(42)->pluck('id')->all());
        self::assertNotSame($mine->id, $other->id);
    }

    #[DataProvider('inactiveProgramStatuses')]
    public function test_existing_request_remains_editable_and_completable_after_program_status_changes(
        ProgramStatus $status,
    ): void {
        $request = $this->request();
        $request->quote->program()->update(['status' => $status]);

        $request = $this->app->make(UpdateBuybackContract::class)->update($request, 991);
        $request = $this->app->make(UpdateManagerNote::class)->update($request, 'Program status is irrelevant');
        $request = $this->app->make(CompleteBuybackRequest::class)->complete($request, 501);

        self::assertSame(BuybackRequestStatus::COMPLETED, $request->status);
        self::assertSame(991, $request->eve_contract_id);
        self::assertSame('Program status is irrelevant', $request->manager_note);
    }

    /** @return iterable<string, array{ProgramStatus}> */
    public static function inactiveProgramStatuses(): iterable
    {
        yield 'disabled' => [ProgramStatus::DISABLED];
        yield 'archived' => [ProgramStatus::ARCHIVED];
    }

    public function test_requester_cancel_sets_only_cancel_audit_fields_even_with_contract_id(): void
    {
        Event::fake([BuybackRequestCanceled::class]);
        $request = $this->request(eveContractId: 777);

        $request = $this->app->make(CancelBuybackRequest::class)->cancel($request, 42);

        self::assertSame(BuybackRequestStatus::CANCELED, $request->status);
        self::assertSame(777, $request->eve_contract_id);
        self::assertSame(42, $request->canceled_by_user_id);
        self::assertSame('2026-09-11T12:00:00+00:00', $request->canceled_at->toIso8601String());
        self::assertNull($request->completed_at);
        self::assertNull($request->rejected_at);
        self::assertNull($request->rejection_reason);
        Event::assertDispatchedTimes(BuybackRequestCanceled::class, 1);
    }

    public function test_manager_can_complete_without_contract_and_only_completion_fields_are_set(): void
    {
        Event::fake([BuybackRequestCompleted::class]);
        $request = $this->request();

        $request = $this->app->make(CompleteBuybackRequest::class)->complete($request, 501);

        self::assertSame(BuybackRequestStatus::COMPLETED, $request->status);
        self::assertNull($request->eve_contract_id);
        self::assertSame(501, $request->completed_by_user_id);
        self::assertSame('2026-09-11T12:00:00+00:00', $request->completed_at->toIso8601String());
        self::assertNull($request->rejected_at);
        self::assertNull($request->canceled_at);
        Event::assertDispatchedTimes(BuybackRequestCompleted::class, 1);
    }

    public function test_rejection_requires_and_persists_a_distinct_non_blank_reason(): void
    {
        $request = $this->request(managerNote: 'Private context');

        foreach (['', '   '] as $reason) {
            try {
                $this->app->make(RejectBuybackRequest::class)->reject($request, 501, $reason);
                self::fail('Blank rejection reasons must fail.');
            } catch (MissingRejectionReasonException) {
                self::assertSame(BuybackRequestStatus::PENDING, $request->fresh()->status);
            }
        }

        Event::fake([BuybackRequestRejected::class]);
        $request = $this->app->make(RejectBuybackRequest::class)
            ->reject($request, 501, '  Contract contents differ from Quote.  ');

        self::assertSame(BuybackRequestStatus::REJECTED, $request->status);
        self::assertSame('Contract contents differ from Quote.', $request->rejection_reason);
        self::assertSame('Private context', $request->manager_note);
        self::assertSame(501, $request->rejected_by_user_id);
        self::assertNotNull($request->rejected_at);
        self::assertNull($request->completed_at);
        self::assertNull($request->canceled_at);
        Event::assertDispatchedTimes(BuybackRequestRejected::class, 1);
    }

    #[DataProvider('competingTransitions')]
    public function test_competing_terminal_transitions_have_one_winner_and_named_conflict(
        string $winner,
        string $loser,
        BuybackRequestStatus $expected,
    ): void {
        Event::fake([
            BuybackRequestCanceled::class,
            BuybackRequestCompleted::class,
            BuybackRequestRejected::class,
        ]);
        $request = $this->request();
        $stale = $request->fresh();

        $this->transition($winner, $request);

        try {
            $this->transition($loser, $stale);
            self::fail('A competing terminal action must lose its conditional update.');
        } catch (RequestNotPendingException $exception) {
            self::assertStringContainsString('no longer pending', $exception->getMessage());
            self::assertSame($expected, $request->fresh()->status);
            Event::assertDispatchedTimes($this->eventFor($winner), 1);
            Event::assertNotDispatched($this->eventFor($loser));
        }
    }

    /** @return iterable<string, array{string, string, BuybackRequestStatus}> */
    public static function competingTransitions(): iterable
    {
        yield 'cancel beats complete' => ['cancel', 'complete', BuybackRequestStatus::CANCELED];
        yield 'complete beats cancel' => ['complete', 'cancel', BuybackRequestStatus::COMPLETED];
        yield 'complete beats reject' => ['complete', 'reject', BuybackRequestStatus::COMPLETED];
        yield 'reject beats complete' => ['reject', 'complete', BuybackRequestStatus::REJECTED];
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_requests_reject_field_edits_transitions_and_direct_model_mutation(
        BuybackRequestStatus $status,
    ): void {
        $request = $this->request();
        $this->transition(match ($status) {
            BuybackRequestStatus::COMPLETED => 'complete',
            BuybackRequestStatus::REJECTED => 'reject',
            BuybackRequestStatus::CANCELED => 'cancel',
            default => throw new LogicException('Unexpected test state.'),
        }, $request);
        $terminal = $request->fresh();

        foreach ([
            fn () => $this->app->make(UpdateBuybackContract::class)->update($terminal, 123),
            fn () => $this->app->make(UpdateRequesterNote::class)->update($terminal, 'changed'),
            fn () => $this->app->make(UpdateManagerNote::class)->update($terminal, 'changed'),
            fn () => $this->app->make(CompleteBuybackRequest::class)->complete($terminal, 501),
        ] as $operation) {
            try {
                $operation();
                self::fail('Terminal Requests must reject ordinary workflow mutation.');
            } catch (RequestNotPendingException) {
                self::assertSame($status, $terminal->fresh()->status);
            }
        }

        $this->expectException(LogicException::class);
        $terminal->update(['eve_contract_id' => 999]);
    }

    /** @return iterable<string, array{BuybackRequestStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [BuybackRequestStatus::COMPLETED];
        yield 'rejected' => [BuybackRequestStatus::REJECTED];
        yield 'canceled' => [BuybackRequestStatus::CANCELED];
    }

    public function test_terminal_request_does_not_make_quote_or_quote_items_mutable(): void
    {
        $request = $this->app->make(CompleteBuybackRequest::class)->complete($this->request(), 501);

        try {
            $request->quote->update(['payable_total' => '1.00']);
            self::fail('The fulfilled Request must not permit Quote mutation.');
        } catch (LogicException) {
            self::assertSame('30.03', $request->quote->fresh()->payable_total);
        }

        $item = $request->quote->items()->firstOrFail();

        try {
            $item->update(['quantity' => 1]);
            self::fail('The fulfilled Request must not permit QuoteItem mutation.');
        } catch (LogicException) {
            self::assertSame(3, $item->fresh()->quantity);
        }
    }

    public function test_permissions_are_independent_and_manager_note_is_not_requester_serialized(): void
    {
        $request = $this->request(managerNote: 'Manager secret')->load('quote');
        $quotePolicy = new BuybackQuotePolicy();
        $requestPolicy = new BuybackRequestPolicy();
        $requester = new RequestPrincipal(42, ['randulfthegrey-buyback.request']);
        $manager = new RequestPrincipal(501, ['randulfthegrey-buyback.manage']);
        $admin = new RequestPrincipal(601, ['randulfthegrey-buyback.admin']);

        self::assertTrue($quotePolicy->submit($requester, $request->quote));
        self::assertFalse($quotePolicy->submit(new RequestPrincipal(43, ['randulfthegrey-buyback.request']), $request->quote));
        self::assertTrue($requestPolicy->cancel($requester, $request));
        self::assertTrue($requestPolicy->updateRequesterContract($requester, $request));
        self::assertTrue($requestPolicy->updateRequesterNote($requester, $request));
        self::assertFalse($requestPolicy->complete($requester, $request));
        self::assertTrue($requestPolicy->complete($manager, $request));
        self::assertTrue($requestPolicy->reject($manager, $request));
        self::assertTrue($requestPolicy->updateManagerContract($manager, $request));
        self::assertTrue($requestPolicy->updateManagerNote($manager, $request));
        self::assertFalse($requestPolicy->cancel($manager, $request));
        self::assertFalse($requestPolicy->complete($admin, $request));
        self::assertFalse($requestPolicy->reject($admin, $request));
        self::assertFalse($requestPolicy->viewAsManager($admin, $request));
        self::assertTrue($requestPolicy->complete(
            new RequestPrincipal(701, ['randulfthegrey-buyback.request', 'randulfthegrey-buyback.manage']),
            $request,
        ));

        $httpRequest = Request::create('/');
        $requesterData = (new RequesterBuybackRequestResource($request))->resolve($httpRequest);
        $managerData = (new ManagerBuybackRequestResource($request))->resolve($httpRequest);
        self::assertArrayNotHasKey('manager_note', $requesterData);
        self::assertArrayNotHasKey('manager_note', $request->toArray());
        self::assertSame('Manager secret', $managerData['manager_note']);
        self::assertSame('30.03', $requesterData['payable_total']);
    }

    public function test_lifecycle_routes_use_only_mutating_verbs_and_independent_permissions(): void
    {
        $routes = $this->app['router']->getRoutes();
        $expectations = [
            'buyback.quotes.submit' => [['POST'], 'can:randulfthegrey-buyback.request'],
            'buyback.requests.contract.update' => [['PATCH'], 'can:randulfthegrey-buyback.request'],
            'buyback.requests.requester-note.update' => [['PATCH'], 'can:randulfthegrey-buyback.request'],
            'buyback.requests.cancel' => [['POST'], 'can:randulfthegrey-buyback.request'],
            'buyback.manage.requests.contract.update' => [['PATCH'], 'can:randulfthegrey-buyback.manage'],
            'buyback.manage.requests.manager-note.update' => [['PATCH'], 'can:randulfthegrey-buyback.manage'],
            'buyback.manage.requests.complete' => [['POST'], 'can:randulfthegrey-buyback.manage'],
            'buyback.manage.requests.reject' => [['POST'], 'can:randulfthegrey-buyback.manage'],
        ];

        foreach ($expectations as $name => [$methods, $permission]) {
            $route = $routes->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->methods(), $name);
            self::assertSame(['web', 'auth', $permission], $route->gatherMiddleware(), $name);
        }
    }

    public function test_http_boundary_applies_resource_policies_and_serializes_string_contract_ids(): void
    {
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        Gate::define('randulfthegrey-buyback.request', static fn (RequestHttpUser $user): bool =>
            in_array('randulfthegrey-buyback.request', $user->permissions, true));
        Gate::define('randulfthegrey-buyback.manage', static fn (RequestHttpUser $user): bool =>
            in_array('randulfthegrey-buyback.manage', $user->permissions, true));
        Gate::define('randulfthegrey-buyback.admin', static fn (RequestHttpUser $user): bool =>
            in_array('randulfthegrey-buyback.admin', $user->permissions, true));
        $quote = $this->quote();

        $this->actingAs(new RequestHttpUser(43, ['randulfthegrey-buyback.request']))
            ->postJson(route('buyback.quotes.submit', $quote), [])
            ->assertForbidden();

        $response = $this->actingAs(new RequestHttpUser(42, ['randulfthegrey-buyback.request']))
            ->postJson(route('buyback.quotes.submit', $quote), [
                'eve_contract_id' => '123456',
                'requester_note' => 'Requester note',
            ])
            ->assertCreated()
            ->assertJsonPath('request.eve_contract_id', 123456)
            ->assertJsonMissingPath('request.manager_note');
        $buybackRequest = BuybackRequest::query()
            ->where('public_id', $response->json('request.public_id'))
            ->firstOrFail();

        $this->actingAs(new RequestHttpUser(42, ['randulfthegrey-buyback.request']))
            ->patchJson(route('buyback.manage.requests.manager-note.update', $buybackRequest), [
                'manager_note' => 'Should not be accepted',
            ])
            ->assertForbidden();

        $this->actingAs(new RequestHttpUser(601, ['randulfthegrey-buyback.admin']))
            ->postJson(route('buyback.manage.requests.complete', $buybackRequest))
            ->assertForbidden();

        $this->actingAs(new RequestHttpUser(501, ['randulfthegrey-buyback.manage']))
            ->patchJson(route('buyback.manage.requests.manager-note.update', $buybackRequest), [
                'manager_note' => 'Internal manager note',
            ])
            ->assertOk()
            ->assertJsonPath('request.manager_note', 'Internal manager note')
            ->assertJsonPath('request.requester_note', 'Requester note');

        $this->postJson(route('buyback.manage.requests.complete', $buybackRequest))
            ->assertOk()
            ->assertJsonPath('request.status', BuybackRequestStatus::COMPLETED->value);
    }

    private function transition(string $transition, BuybackRequest $request): BuybackRequest
    {
        return match ($transition) {
            'cancel' => $this->app->make(CancelBuybackRequest::class)->cancel($request, 42),
            'complete' => $this->app->make(CompleteBuybackRequest::class)->complete($request, 501),
            'reject' => $this->app->make(RejectBuybackRequest::class)->reject($request, 501, 'Mismatch'),
            default => throw new LogicException('Unknown test transition.'),
        };
    }

    /** @return class-string */
    private function eventFor(string $transition): string
    {
        return match ($transition) {
            'cancel' => BuybackRequestCanceled::class,
            'complete' => BuybackRequestCompleted::class,
            'reject' => BuybackRequestRejected::class,
            default => throw new LogicException('Unknown test transition.'),
        };
    }

    private function submitter(): SubmitBuybackQuote
    {
        return $this->app->make(SubmitBuybackQuote::class);
    }

    private function request(
        int $requesterUserId = 42,
        ?int $eveContractId = null,
        ?string $managerNote = null,
    ): BuybackRequest {
        $request = $this->submitter()->submit(
            $this->quote(requesterUserId: $requesterUserId),
            $requesterUserId,
            $eveContractId,
        );

        if ($managerNote !== null) {
            $request = $this->app->make(UpdateManagerNote::class)->update($request, $managerNote);
        }

        return $request->fresh('quote');
    }

    private function quote(
        int $requesterUserId = 42,
        ProgramStatus $programStatus = ProgramStatus::ENABLED,
        string $expiresAt = '2026-09-11 12:30:00',
    ): BuybackQuote {
        $program = BuybackProgram::create([
            'name' => 'Ore Buyback',
            'status' => $programStatus,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 30,
        ]);
        $quote = BuybackQuote::create([
            'appraisal_token_hash' => hash('sha256', (string) Str::uuid()),
            'program_id' => $program->id,
            'requester_user_id' => $requesterUserId,
            'requester_name_snapshot' => 'Capsuleer ' . $requesterUserId,
            'program_name_snapshot' => 'Ore Buyback',
            'pricing_completed_at' => '2026-09-11 12:00:00',
            'quoted_at' => '2026-09-11 12:00:00',
            'expires_at' => $expiresAt,
            'payable_total' => '30.03',
        ]);
        BuybackQuoteItem::create([
            'quote_id' => $quote->id,
            'type_id' => 34,
            'type_name' => 'Tritanium',
            'quantity' => 3,
            'compression_state' => 'NOT_APPLICABLE',
            'reference_mode' => 'BUY',
            'reference_resolution' => 'PROVIDER',
            'reference_unit_price' => '10.005',
            'effective_modifier_bps' => 0,
            'final_unit_price' => '10.01',
            'line_total' => '30.03',
            'policy_snapshot' => ['schema_version' => 1],
            'pricing_snapshot' => ['schema_version' => 1],
        ]);

        return $quote->load('items');
    }
}

final class RequestPrincipal
{
    /** @param list<string> $permissions */
    public function __construct(
        private readonly int $id,
        private readonly array $permissions,
    ) {
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

final class RequestHttpUser extends Authenticatable
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(int $id = 0, array $permissions = [])
    {
        $this->permissions = $permissions;
        parent::__construct();

        $this->setAttribute($this->getAuthIdentifierName(), $id);
    }
}
