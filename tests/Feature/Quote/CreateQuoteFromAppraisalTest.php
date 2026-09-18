<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Quote;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Quote\CreateQuoteFromAppraisal;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\SeatPriceProviderGateway;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalLine;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\QuoteState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackQuoteCreated;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalTokenNotFoundException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidAppraisalForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuotePersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\CreateQuoteRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuoteItem;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class CreateQuoteFromAppraisalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        Carbon::setTestNow('2026-09-11 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_atomically_persists_all_and_only_priced_lines_with_exact_snapshots_and_timestamps(): void
    {
        Event::fake([BuybackQuoteCreated::class]);
        $program = $this->program();
        $policy = $this->policySnapshot(ReferenceMode::SPLIT, -500);
        $pricing = $this->pricingSnapshot(ReferenceMode::SPLIT, ReferenceResolution::DERIVED_MIDPOINT);
        $lines = [
            $this->pricedLine(34, 'Tritanium', 3, '10.00', '9.50', '28.50', $policy, $pricing, -500),
            $this->outcomeLine(AppraisalLineStatus::EXCLUDED, 35),
            $this->outcomeLine(AppraisalLineStatus::UNPRICED, 36),
            $this->outcomeLine(AppraisalLineStatus::REFERENCE_UNAVAILABLE, 37),
            $this->outcomeLine(AppraisalLineStatus::UNKNOWN),
            $this->outcomeLine(AppraisalLineStatus::INVALID_INPUT),
            $this->pricedLine(38, 'Nocxium', 2, '1', '1.00', '2.00'),
        ];
        $token = $this->store($this->appraisal($program, $lines, '30.50'));

        $quote = $this->service()->create($token, 42, 'Capsuleer Example');

        self::assertTrue($quote->wasRecentlyCreated);
        self::assertTrue(Str::isUlid($quote->public_id));
        self::assertSame(hash('sha256', $token), $quote->appraisal_token_hash);
        self::assertSame(42, $quote->requester_user_id);
        self::assertSame('Capsuleer Example', $quote->requester_name_snapshot);
        self::assertSame('Historical Ore Program', $quote->program_name_snapshot);
        self::assertSame('2026-09-11T12:00:00+00:00', $quote->pricing_completed_at->toIso8601String());
        self::assertSame('2026-09-11T12:00:00+00:00', $quote->quoted_at->toIso8601String());
        self::assertSame('2026-09-11T12:30:00+00:00', $quote->expires_at->toIso8601String());
        self::assertSame('30.50', $quote->payable_total);
        self::assertSame([34, 38], $quote->items->pluck('type_id')->all());
        self::assertDatabaseCount('buyback_quote_items', 2);
        self::assertDatabaseCount('buyback_requests', 0);

        $item = $quote->items->firstWhere('type_id', 34);
        self::assertSame('10.000000000000000000', $item->reference_unit_price);
        self::assertSame('9.50', $item->final_unit_price);
        self::assertSame('28.50', $item->line_total);
        self::assertSame($policy, $item->policy_snapshot);
        self::assertSame($pricing, $item->pricing_snapshot);

        $stored = $this->app->make(AppraisalStore::class)->find($token);
        self::assertSame($quote->public_id, $stored?->createdQuoteId);
        Event::assertDispatched(BuybackQuoteCreated::class, static fn (BuybackQuoteCreated $event): bool =>
            $event->quotePublicId === $quote->public_id
            && $event->programId === $program->id
            && $event->requesterUserId === 42
        );
    }

    public function test_duplicate_and_cache_lost_retries_reuse_the_durable_quote_without_another_event(): void
    {
        Event::fake([BuybackQuoteCreated::class]);
        $program = $this->program();
        $token = $this->store($this->appraisal($program));

        $first = $this->service()->create($token, 42, 'Original Name');
        $second = $this->service()->create($token, 42, 'Changed Name');
        Cache::flush();
        $afterCacheLoss = $this->service()->create($token, 42, 'Changed Again');

        self::assertSame($first->id, $second->id);
        self::assertSame($first->id, $afterCacheLoss->id);
        self::assertFalse($second->wasRecentlyCreated);
        self::assertFalse($afterCacheLoss->wasRecentlyCreated);
        self::assertSame('Original Name', $afterCacheLoss->requester_name_snapshot);
        self::assertDatabaseCount('buyback_quotes', 1);
        self::assertDatabaseCount('buyback_quote_items', 1);
        Event::assertDispatchedTimes(BuybackQuoteCreated::class, 1);

        $this->expectException(AppraisalOwnershipException::class);
        $this->service()->create($token, 43, 'Other Requester');
    }

    public function test_database_uniqueness_closes_the_post_commit_cache_coordination_race(): void
    {
        $program = $this->program();
        $token = $this->store($this->appraisal($program));
        $quote = $this->service()->create($token, 42, 'Capsuleer Example');

        $this->expectException(QueryException::class);
        BuybackQuote::create([
            'appraisal_token_hash' => $quote->appraisal_token_hash,
            'program_id' => $program->id,
            'requester_user_id' => 42,
            'requester_name_snapshot' => 'Capsuleer Example',
            'program_name_snapshot' => 'Historical Ore Program',
            'pricing_completed_at' => '2026-09-11 12:00:00',
            'quoted_at' => '2026-09-11 12:00:00',
            'expires_at' => '2026-09-11 12:30:00',
            'payable_total' => '30.03',
        ]);
    }

    public function test_real_provider_reference_precision_is_preserved_without_rounding(): void
    {
        $program = $this->program();
        $token = $this->store($this->appraisal($program, [
            $this->pricedLine(
                typeId: 34,
                typeName: 'Tritanium',
                quantity: 100,
                referenceUnitPrice: '3.7800995782482',
                finalUnitPrice: '3.78',
                lineTotal: '378.00',
            ),
            $this->pricedLine(
                typeId: 35,
                typeName: 'Pyerite',
                quantity: 50,
                referenceUnitPrice: '16.972088411273',
                finalUnitPrice: '16.97',
                lineTotal: '848.50',
            ),
        ], '1226.50'));

        $quote = $this->service()->create($token, 42, 'Capsuleer Example');

        self::assertSame('1226.50', $quote->payable_total);
        self::assertSame(
            ['3.780099578248200000', '16.972088411273000000'],
            $quote->items->pluck('reference_unit_price')->all(),
        );
    }

    public function test_requester_cannot_use_another_requesters_unconsumed_token(): void
    {
        $program = $this->program();
        $token = $this->store($this->appraisal($program));

        try {
            $this->service()->create($token, 43, 'Other Requester');
            self::fail('A requester must not consume another requester\'s appraisal token.');
        } catch (AppraisalOwnershipException) {
            self::assertDatabaseCount('buyback_quotes', 0);
            self::assertNull($this->app->make(AppraisalStore::class)->find($token)?->createdQuoteId);
        }
    }

    #[DataProvider('unavailableProgramStatuses')]
    public function test_current_program_status_must_still_be_enabled(ProgramStatus $status): void
    {
        $program = $this->program();
        $token = $this->store($this->appraisal($program));
        $program->update(['status' => $status]);

        try {
            $this->service()->create($token, 42, 'Capsuleer Example');
            self::fail('A non-enabled Program must reject Quote creation.');
        } catch (ProgramUnavailableForQuoteException) {
            self::assertDatabaseCount('buyback_quotes', 0);
            self::assertNull($this->app->make(AppraisalStore::class)->find($token)?->createdQuoteId);
        }
    }

    /** @return iterable<string, array{ProgramStatus}> */
    public static function unavailableProgramStatuses(): iterable
    {
        yield 'disabled' => [ProgramStatus::DISABLED];
        yield 'archived' => [ProgramStatus::ARCHIVED];
    }

    public function test_expiry_is_enforced_at_the_exact_deadline_and_missing_tokens_are_normalized(): void
    {
        $program = $this->program();
        $token = $this->store($this->appraisal($program));
        $key = 'seat-buyback-programs:appraisal:' . hash('sha256', $token);
        Cache::forever($key, Cache::get($key));
        Carbon::setTestNow('2026-09-11 12:30:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:30:00 UTC');

        try {
            $this->service()->create($token, 42, 'Capsuleer Example');
            self::fail('The appraisal must expire exactly at its deadline.');
        } catch (AppraisalExpiredException) {
            self::assertDatabaseCount('buyback_quotes', 0);
        }

        Carbon::setTestNow('2026-09-11 12:31:00 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:31:00 UTC');

        try {
            $this->service()->create($token, 42, 'Capsuleer Example');
            self::fail('An appraisal must remain expired after its deadline.');
        } catch (AppraisalExpiredException) {
            self::assertDatabaseCount('buyback_quotes', 0);
        }

        $this->expectException(AppraisalTokenNotFoundException::class);
        $this->service()->create(str_repeat('0', 64), 42, 'Capsuleer Example');
    }

    #[DataProvider('invalidAppraisalVariants')]
    public function test_invalid_trusted_appraisal_integrity_fails_without_persistence(string $variant): void
    {
        $program = $this->program();
        $lines = [$this->pricedLine()];
        $total = '30.03';

        if ($variant === 'no-priced-lines') {
            $lines = [$this->outcomeLine(AppraisalLineStatus::UNPRICED, 34)];
            $total = '0.00';
        } elseif ($variant === 'duplicate-priced-type') {
            $lines[] = $this->pricedLine();
            $total = '60.06';
        } elseif ($variant === 'missing-policy-version') {
            $policy = $this->policySnapshot();
            unset($policy['schema_version']);
            $lines = [$this->pricedLine(policy: $policy)];
        } elseif ($variant === 'missing-pricing-version') {
            $pricing = $this->pricingSnapshot();
            unset($pricing['schema_version']);
            $lines = [$this->pricedLine(pricing: $pricing)];
        } elseif ($variant === 'trusted-total-mismatch') {
            $total = '30.04';
        } elseif ($variant === 'line-total-mismatch') {
            $lines = [$this->pricedLine(lineTotal: '30.04')];
            $total = '30.04';
        } elseif ($variant === 'modifier-out-of-range') {
            $lines = [$this->pricedLine(modifierBps: 10001)];
        }

        $token = $this->store($this->appraisal($program, $lines, $total));

        try {
            $this->service()->create($token, 42, 'Capsuleer Example');
            self::fail('Invalid trusted appraisal state must not create a Quote.');
        } catch (InvalidAppraisalForQuoteException) {
            self::assertDatabaseCount('buyback_quotes', 0);
            self::assertDatabaseCount('buyback_quote_items', 0);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAppraisalVariants(): iterable
    {
        foreach ([
            'no-priced-lines',
            'duplicate-priced-type',
            'missing-policy-version',
            'missing-pricing-version',
            'trusted-total-mismatch',
            'line-total-mismatch',
            'modifier-out-of-range',
        ] as $variant) {
            yield $variant => [$variant];
        }
    }

    public function test_item_failure_rolls_back_every_row_emits_no_event_and_leaves_token_retryable(): void
    {
        Event::fake([BuybackQuoteCreated::class]);
        $program = $this->program();
        $token = $this->store($this->appraisal($program, [
            $this->pricedLine(),
            $this->pricedLine(35, 'Pyerite', 2, '1', '1.00', '2.00'),
        ], '32.03'));
        $fail = true;
        $createdItems = 0;
        BuybackQuoteItem::creating(static function () use (&$fail, &$createdItems): void {
            if ($fail && ++$createdItems === 2) {
                throw new RuntimeException('Simulated second-item persistence failure.');
            }
        });

        try {
            $this->service()->create($token, 42, 'Capsuleer Example');
            self::fail('The simulated item failure should abort persistence.');
        } catch (QuotePersistenceException) {
            self::assertDatabaseCount('buyback_quotes', 0);
            self::assertDatabaseCount('buyback_quote_items', 0);
            self::assertNull($this->app->make(AppraisalStore::class)->find($token)?->createdQuoteId);
            Event::assertNotDispatched(BuybackQuoteCreated::class);
        }

        $fail = false;
        $quote = $this->service()->create($token, 42, 'Capsuleer Example');
        self::assertCount(2, $quote->items);
        Event::assertDispatchedTimes(BuybackQuoteCreated::class, 1);
    }

    public function test_historical_evidence_is_immutable_and_state_is_derived(): void
    {
        $this->app->instance(SeatPriceProviderGateway::class, new class implements SeatPriceProviderGateway {
            public function providerInstance(int $providerInstanceId): ?\RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProviderInstance
            {
                throw new RuntimeException('Quote creation must not resolve current providers.');
            }

            public function getPrices(int $providerInstanceId, array $typeIds): array
            {
                throw new RuntimeException('Quote creation must not reprice.');
            }
        });
        $program = $this->program();
        $policy = $this->policySnapshot();
        $pricing = $this->pricingSnapshot();
        $token = $this->store($this->appraisal($program, [
            $this->pricedLine(policy: $policy, pricing: $pricing),
        ]));
        $quote = $this->service()->create($token, 42, 'Capsuleer Example');

        self::assertSame(QuoteState::AVAILABLE, $quote->stateAt(CarbonImmutable::parse('2026-09-11 12:29:59 UTC')));
        self::assertSame(QuoteState::EXPIRED, $quote->stateAt(CarbonImmutable::parse('2026-09-11 12:30:00 UTC')));

        $program->update([
            'name' => 'Renamed Program',
            'status' => ProgramStatus::ARCHIVED,
            'default_modifier_bps' => 2500,
        ]);
        $reference = $program->priceReferences()->create([
            'reference_mode' => ReferenceMode::BUY,
            'resolution' => ReferenceResolution::PROVIDER,
            'provider_instance_id' => 10,
        ]);
        $reference->update(['provider_instance_id' => 999]);
        $rule = $program->rules()->create([
            'target_type' => RuleTargetType::TYPE,
            'target_id' => 34,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'modifier_operation' => ModifierOperation::INHERIT,
        ]);
        $rule->update(['enabled' => false]);
        $reloaded = $quote->fresh('items');
        self::assertSame('Historical Ore Program', $reloaded->program_name_snapshot);
        self::assertSame('30.03', $reloaded->payable_total);
        self::assertSame($policy, $reloaded->items[0]->policy_snapshot);
        self::assertSame($pricing, $reloaded->items[0]->pricing_snapshot);

        try {
            $reloaded->update(['payable_total' => '99.99']);
            self::fail('A persisted Quote must reject updates.');
        } catch (LogicException) {
            self::assertSame('30.03', $quote->fresh()->payable_total);
        }

        BuybackRequest::create([
            'quote_id' => $quote->id,
            'submitted_at' => '2026-09-11 12:05:00',
        ]);
        self::assertSame(QuoteState::SUBMITTED, $quote->fresh()->stateAt());
    }

    public function test_quote_route_is_token_only_post_and_has_requester_permission(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('buyback.quotes.store');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertSame(['web', 'auth', 'can:buyback.request'], $route->gatherMiddleware());
        self::assertSame(['appraisal_token'], array_keys((new CreateQuoteRequest())->rules()));
    }

    private function service(): CreateQuoteFromAppraisal
    {
        return $this->app->make(CreateQuoteFromAppraisal::class);
    }

    private function store(AppraisalResult $appraisal): string
    {
        return $this->app->make(AppraisalStore::class)->put($appraisal);
    }

    private function program(): BuybackProgram
    {
        return BuybackProgram::create([
            'name' => 'Current Ore Program',
            'status' => ProgramStatus::ENABLED,
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 30,
        ]);
    }

    /** @param list<AppraisalLine>|null $lines */
    private function appraisal(
        BuybackProgram $program,
        ?array $lines = null,
        string $total = '30.03',
    ): AppraisalResult {
        return new AppraisalResult(
            programId: (int) $program->id,
            programName: 'Historical Ore Program',
            requesterUserId: 42,
            originalInput: "Tritanium\t3",
            pricedAt: CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
            quoteExpiresAt: CarbonImmutable::parse('2026-09-11 12:30:00 UTC'),
            lines: $lines ?? [$this->pricedLine()],
            quoteableTotal: $total,
        );
    }

    /** @param array<string, mixed>|null $policy @param array<string, mixed>|null $pricing */
    private function pricedLine(
        int $typeId = 34,
        string $typeName = 'Tritanium',
        int $quantity = 3,
        string $referenceUnitPrice = '10.005',
        string $finalUnitPrice = '10.01',
        string $lineTotal = '30.03',
        ?array $policy = null,
        ?array $pricing = null,
        int $modifierBps = 0,
    ): AppraisalLine {
        $mode = ReferenceMode::SPLIT;
        $resolution = ReferenceResolution::DERIVED_MIDPOINT;

        return new AppraisalLine(
            sourceLineNumber: 1,
            status: AppraisalLineStatus::PRICED,
            rawLines: [sprintf('%s %d', $typeName, $quantity)],
            candidateName: $typeName,
            typeId: $typeId,
            typeName: $typeName,
            groupId: 18,
            quantity: $quantity,
            compressionState: CompressionState::NOT_APPLICABLE,
            effectivePolicy: $policy ?? $this->policySnapshot($mode, $modifierBps),
            logicalReferenceMode: $mode,
            referenceResolution: $resolution,
            pricingProvenance: $pricing ?? $this->pricingSnapshot($mode, $resolution),
            referenceUnitPrice: $referenceUnitPrice,
            effectiveModifierBps: $modifierBps,
            finalUnitPrice: $finalUnitPrice,
            lineTotal: $lineTotal,
        );
    }

    private function outcomeLine(AppraisalLineStatus $status, ?int $typeId = null): AppraisalLine
    {
        return new AppraisalLine(
            sourceLineNumber: 2,
            status: $status,
            rawLines: ['Non-payable line'],
            typeId: $typeId,
            typeName: $typeId === null ? null : 'Non-payable Type',
        );
    }

    /** @return array<string, mixed> */
    private function policySnapshot(
        ReferenceMode $mode = ReferenceMode::SPLIT,
        int $modifierBps = 0,
    ): array {
        return [
            'schema_version' => 1,
            'baseline' => [
                'source' => 'PROGRAM_DEFAULTS',
                'acceptance' => ['value' => 'ACCEPT'],
            ],
            'acceptance' => 'ACCEPT',
            'reference_mode' => $mode->value,
            'effective_modifier_bps' => $modifierBps,
            'layers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function pricingSnapshot(
        ReferenceMode $mode = ReferenceMode::SPLIT,
        ReferenceResolution $resolution = ReferenceResolution::DERIVED_MIDPOINT,
    ): array {
        return [
            'schema_version' => 1,
            'logical_mode' => $mode->value,
            'resolution' => $resolution->value,
            'components' => [
                [
                    'mode' => 'BUY',
                    'provider_instance_id' => 10,
                    'provider_instance_name' => 'Buy Provider',
                    'reference_price' => '10.00',
                ],
                [
                    'mode' => 'SELL',
                    'provider_instance_id' => 20,
                    'provider_instance_name' => 'Sell Provider',
                    'reference_price' => '10.01',
                ],
            ],
        ];
    }
}
