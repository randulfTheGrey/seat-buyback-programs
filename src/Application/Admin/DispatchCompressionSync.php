<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Illuminate\Cache\CacheManager;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionDataSynchronizer;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\CompressionSyncDispatchResult;
use RandulfTheGrey\Seat\BuybackPrograms\Jobs\SyncCompressionData;
use Throwable;

final class DispatchCompressionSync
{
    public const DISPATCH_LOCK_NAME = 'seat-buyback-programs:compression-sync-dispatch';

    public function __construct(private readonly CacheManager $cache)
    {
    }

    public function dispatch(): CompressionSyncDispatchResult
    {
        $running = $this->cache->lock(CompressionDataSynchronizer::LOCK_NAME, 5);

        if (! $running->get()) {
            return new CompressionSyncDispatchResult(false, 'A compression data synchronization is already running.');
        }

        $running->release();
        $pending = $this->cache->lock(
            self::DISPATCH_LOCK_NAME,
            (int) config('seat-buyback-programs.compression.sync_lock_seconds', 600),
        );

        if (! $pending->get()) {
            return new CompressionSyncDispatchResult(false, 'A compression data synchronization is already queued.');
        }

        try {
            SyncCompressionData::dispatch();
        } catch (Throwable $exception) {
            $pending->release();
            throw $exception;
        }

        return new CompressionSyncDispatchResult(true, 'Compression data synchronization was queued.');
    }
}
