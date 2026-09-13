<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Appraisal;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class CacheAppraisalStoreTest extends TestCase
{
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

    public function test_opaque_owner_bound_token_round_trips_trusted_data_and_expires_at_quote_deadline(): void
    {
        $result = new AppraisalResult(
            programId: 77,
            programName: 'Ore',
            requesterUserId: 42,
            originalInput: "Tritanium\t10",
            pricedAt: CarbonImmutable::now(),
            quoteExpiresAt: CarbonImmutable::now()->addSeconds(10),
            lines: [],
            quoteableTotal: '0.00',
        );
        $store = $this->app->make(AppraisalStore::class);

        $token = $store->put($result);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertNotSame('42', $token);
        self::assertNotSame('77', $token);
        self::assertNull($store->findForRequester($token, 43));

        $stored = $store->findForRequester($token, 42);
        self::assertNotNull($stored);
        self::assertSame(77, $stored->result->programId);
        self::assertSame("Tritanium\t10", $stored->result->originalInput);
        self::assertNull($stored->createdQuoteId);

        $store->withCreationLock($token, static function () use ($store, $token): void {
            $store->recordCreatedQuote($token, 42, '01K4QUOTE000000000000000000');
        });
        self::assertSame(
            '01K4QUOTE000000000000000000',
            $store->findForRequester($token, 42)?->createdQuoteId,
        );

        $key = 'seat-buyback-programs:appraisal:' . hash('sha256', $token);
        $payload = Cache::get($key);
        self::assertSame('QUOTED', $payload['consumption']['state']);
        self::assertArrayHasKey('created_quote_id', $payload['consumption']);

        Carbon::setTestNow('2026-09-11 12:00:11 UTC');
        CarbonImmutable::setTestNow('2026-09-11 12:00:11 UTC');

        self::assertNull($store->findForRequester($token, 42));
        self::assertNull(Cache::get($key));
    }
}
