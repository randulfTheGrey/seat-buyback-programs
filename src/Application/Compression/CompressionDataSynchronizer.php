<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use Carbon\CarbonImmutable;
use Illuminate\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionSyncResult;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use Throwable;

final class CompressionDataSynchronizer implements CompressionSync
{
    public const LOCK_NAME = 'seat-buyback-programs:compression-sync';

    public function __construct(
        private readonly CompressionSdeSource $source,
        private readonly CompressionArchiveParser $parser,
        private readonly CompressionCandidateValidator $validator,
        private readonly CompressionProjectionStore $store,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(): CompressionSyncResult
    {
        $lock = $this->cache->lock(
            self::LOCK_NAME,
            (int) config('seat-buyback-programs.compression.sync_lock_seconds', 600),
        );

        if (! $lock->get()) {
            $this->logger->info('Compression SDE synchronization skipped because another sync holds the lock.');

            return new CompressionSyncResult(
                CompressionSyncOutcome::LOCKED,
                failureCode: CompressionSyncFailureCode::CONCURRENT_SYNC,
                message: 'Another compression data synchronization is already running.',
            );
        }

        try {
            return $this->synchronizeWhileLocked();
        } finally {
            $lock->release();
        }
    }

    private function synchronizeWhileLocked(): CompressionSyncResult
    {
        $checkedAt = CarbonImmutable::now();
        $build = null;
        $archivePath = null;

        try {
            $build = $this->source->latestBuild();

            if ($this->store->activeBuild() === $build->number && $this->store->hasUsableDataset()) {
                $this->store->recordCurrentCheck($build, $checkedAt);
                $this->logger->info('Compression SDE data is already current.', ['build' => $build->number]);

                return new CompressionSyncResult(
                    CompressionSyncOutcome::CURRENT,
                    build: $build->number,
                    message: sprintf('Compression data is already current at SDE build %s.', $build->number),
                );
            }

            $archivePath = tempnam(sys_get_temp_dir(), 'buyback-compression-');

            if ($archivePath === false) {
                throw new CompressionSyncException(
                    CompressionSyncFailureCode::DOWNLOAD_FAILURE,
                    'Unable to allocate temporary storage for the CCP SDE archive.',
                );
            }

            $this->source->download($build, $archivePath);
            $candidate = $this->parser->parse($archivePath);
            $this->validator->validate($candidate);

            try {
                $this->store->activate($candidate, $build, $checkedAt);
            } catch (Throwable $exception) {
                throw new CompressionSyncException(
                    CompressionSyncFailureCode::ACTIVATION_FAILURE,
                    sprintf('Unable to activate CCP SDE build %s.', $build->number),
                    $exception,
                );
            }

            $this->logger->info('Compression SDE data updated.', [
                'build' => $build->number,
                'mapping_count' => count($candidate->pairs),
            ]);

            return new CompressionSyncResult(
                CompressionSyncOutcome::UPDATED,
                build: $build->number,
                mappingCount: count($candidate->pairs),
                message: sprintf(
                    'Activated %d compression mappings from SDE build %s.',
                    count($candidate->pairs),
                    $build->number,
                ),
            );
        } catch (CompressionSyncException $exception) {
            $this->recordFailure($exception, $checkedAt, $build?->number);

            return new CompressionSyncResult(
                CompressionSyncOutcome::FAILED,
                build: $build?->number,
                failureCode: $exception->failureCode,
                message: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $normalized = new CompressionSyncException(
                CompressionSyncFailureCode::ACTIVATION_FAILURE,
                'Compression data synchronization failed during persistence.',
                $exception,
            );
            $this->recordFailure($normalized, $checkedAt, $build?->number);

            return new CompressionSyncResult(
                CompressionSyncOutcome::FAILED,
                build: $build?->number,
                failureCode: $normalized->failureCode,
                message: $normalized->getMessage(),
            );
        } finally {
            if (is_string($archivePath) && is_file($archivePath)) {
                @unlink($archivePath);
            }
        }
    }

    private function recordFailure(
        CompressionSyncException $exception,
        CarbonImmutable $checkedAt,
        ?string $attemptedBuild,
    ): void {
        try {
            $this->store->recordFailure(
                $exception->failureCode,
                $exception->getMessage(),
                $checkedAt,
                $attemptedBuild,
            );
        } catch (Throwable $metadataException) {
            $this->logger->error('Unable to record compression refresh failure metadata.', [
                'failure_code' => $exception->failureCode->value,
                'exception' => $metadataException,
            ]);
        }

        $context = [
            'failure_code' => $exception->failureCode->value,
            'attempted_build' => $attemptedBuild,
            'exception' => $exception->getPrevious() ?? $exception,
        ];

        try {
            $hasUsableDataset = $this->store->hasUsableDataset();
        } catch (Throwable $healthException) {
            $hasUsableDataset = false;
            $this->logger->error('Unable to determine compression dataset availability after refresh failure.', [
                'exception' => $healthException,
            ]);
        }

        if ($hasUsableDataset) {
            $this->logger->warning('Compression SDE refresh failed; last-known-good data remains active.', $context);
        } else {
            $this->logger->error('Initial compression SDE import failed; no active dataset is available.', $context);
        }
    }
}
