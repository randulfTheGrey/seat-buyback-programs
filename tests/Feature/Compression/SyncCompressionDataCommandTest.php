<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Compression;

use Illuminate\Console\Scheduling\Schedule;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionSyncResult;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class SyncCompressionDataCommandTest extends TestCase
{
    public function test_command_reports_successful_import_from_shared_service(): void
    {
        $stub = new CompressionSyncStub(new CompressionSyncResult(
            CompressionSyncOutcome::UPDATED,
            build: '3503375',
            mappingCount: 100,
            message: 'Activated 100 compression mappings from SDE build 3503375.',
        ));
        $this->app->instance(CompressionSync::class, $stub);

        $this->artisan('buyback:sync-compression-data')
            ->expectsOutput('Activated 100 compression mappings from SDE build 3503375.')
            ->assertSuccessful();
        self::assertSame(1, $stub->calls);
    }

    public function test_command_reports_current_no_op(): void
    {
        $stub = new CompressionSyncStub(new CompressionSyncResult(
            CompressionSyncOutcome::CURRENT,
            build: '3503375',
            message: 'Compression data is already current at SDE build 3503375.',
        ));
        $this->app->instance(CompressionSync::class, $stub);

        $this->artisan('buyback:sync-compression-data')
            ->expectsOutput('Compression data is already current at SDE build 3503375.')
            ->assertSuccessful();
        self::assertSame(1, $stub->calls);
    }

    public function test_command_returns_failure_for_normalized_sync_failure(): void
    {
        $stub = new CompressionSyncStub(new CompressionSyncResult(
            CompressionSyncOutcome::FAILED,
            failureCode: CompressionSyncFailureCode::DOWNLOAD_FAILURE,
            message: 'Unable to download CCP SDE build 3503375.',
        ));
        $this->app->instance(CompressionSync::class, $stub);

        $this->artisan('buyback:sync-compression-data')
            ->expectsOutput('DOWNLOAD_FAILURE: Unable to download CCP SDE build 3503375.')
            ->assertFailed();
        self::assertSame(1, $stub->calls);
    }

    public function test_daily_schedule_uses_the_same_command_and_overlap_protection(): void
    {
        $events = $this->app->make(Schedule::class)->events();
        $compressionEvents = array_values(array_filter(
            $events,
            static fn ($event): bool => str_contains($event->command ?? '', 'buyback:sync-compression-data'),
        ));

        self::assertCount(1, $compressionEvents);
        self::assertSame('0 0 * * *', $compressionEvents[0]->expression);
        self::assertTrue($compressionEvents[0]->withoutOverlapping);
        self::assertSame(120, $compressionEvents[0]->expiresAt);
    }
}

final class CompressionSyncStub implements CompressionSync
{
    public int $calls = 0;

    public function __construct(private readonly CompressionSyncResult $result)
    {
    }

    public function synchronize(): CompressionSyncResult
    {
        $this->calls++;

        return $this->result;
    }
}
