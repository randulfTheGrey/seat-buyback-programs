<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\DispatchCompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;

final class SyncCompressionData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(CompressionSync $sync, CacheManager $cache): void
    {
        try {
            $sync->synchronize();
        } finally {
            $cache->lock(DispatchCompressionSync::DISPATCH_LOCK_NAME)->forceRelease();
        }
    }
}
