<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Console\Commands;

use Illuminate\Console\Command;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;

final class SyncCompressionDataCommand extends Command
{
    protected $signature = 'buyback:sync-compression-data';

    protected $description = 'Synchronize CCP SDE compression reference data';

    public function __construct(private readonly CompressionSync $sync)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->sync->synchronize();

        return match ($result->outcome) {
            CompressionSyncOutcome::UPDATED => $this->successful($result->message ?? 'Compression data updated.'),
            CompressionSyncOutcome::CURRENT => $this->successful($result->message ?? 'Compression data is current.'),
            CompressionSyncOutcome::LOCKED => $this->unsuccessful(
                $result->message ?? 'Another compression synchronization is already running.',
            ),
            CompressionSyncOutcome::FAILED => $this->unsuccessful(sprintf(
                '%s: %s',
                $result->failureCode?->value ?? 'SYNC_FAILURE',
                $result->message ?? 'Compression data synchronization failed.',
            )),
        };
    }

    private function successful(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function unsuccessful(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
