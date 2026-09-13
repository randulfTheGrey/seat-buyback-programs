<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionCandidate;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionDataHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\SdeBuild;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMetadata;

final class CompressionProjectionStore
{
    public const METADATA_ID = 1;

    public function activeBuild(): ?string
    {
        return CompressionMetadata::query()->find(self::METADATA_ID)?->active_sde_build;
    }

    public function hasUsableDataset(): bool
    {
        return $this->activeBuild() !== null && CompressionMapping::query()->exists();
    }

    public function activate(
        CompressionCandidate $candidate,
        SdeBuild $build,
        CarbonImmutable $checkedAt,
    ): void {
        DB::transaction(function () use ($candidate, $build, $checkedAt): void {
            CompressionMapping::query()->delete();

            $rows = array_map(
                static fn ($pair): array => [
                    'uncompressed_type_id' => $pair->uncompressedTypeId,
                    'compressed_type_id' => $pair->compressedTypeId,
                ],
                $candidate->pairs,
            );

            foreach (array_chunk($rows, 500) as $chunk) {
                CompressionMapping::query()->insert($chunk);
            }

            $metadata = CompressionMetadata::query()->find(self::METADATA_ID)
                ?? new CompressionMetadata(['id' => self::METADATA_ID]);
            $metadata->forceFill([
                'id' => self::METADATA_ID,
                'active_sde_build' => $build->number,
                'source' => 'CCP Static Data Export',
                'source_metadata' => $build->metadata(),
                'imported_at' => $checkedAt,
                'activated_at' => $checkedAt,
                'last_checked_at' => $checkedAt,
                'last_error_at' => null,
                'last_error_message' => null,
            ])->save();
        });
    }

    public function recordCurrentCheck(SdeBuild $build, CarbonImmutable $checkedAt): void
    {
        $metadata = CompressionMetadata::query()->findOrFail(self::METADATA_ID);
        $metadata->forceFill([
            'source_metadata' => $build->metadata(),
            'last_checked_at' => $checkedAt,
            'last_error_at' => null,
            'last_error_message' => null,
        ])->save();
    }

    public function recordFailure(
        CompressionSyncFailureCode $failureCode,
        string $safeMessage,
        CarbonImmutable $checkedAt,
        ?string $attemptedBuild,
    ): void {
        $metadata = CompressionMetadata::query()->find(self::METADATA_ID)
            ?? new CompressionMetadata(['id' => self::METADATA_ID]);
        $sourceMetadata = is_array($metadata->source_metadata) ? $metadata->source_metadata : [];
        $sourceMetadata['last_refresh_failure'] = [
            'code' => $failureCode->value,
            'attempted_build' => $attemptedBuild,
        ];

        $metadata->forceFill([
            'id' => self::METADATA_ID,
            'source' => $metadata->source ?? 'CCP Static Data Export',
            'source_metadata' => $sourceMetadata,
            'last_checked_at' => $checkedAt,
            'last_error_at' => $checkedAt,
            'last_error_message' => $safeMessage,
        ])->save();
    }

    public function health(): CompressionDataHealth
    {
        $metadata = CompressionMetadata::query()->find(self::METADATA_ID);
        $usable = $metadata?->active_sde_build !== null && CompressionMapping::query()->exists();
        $lastRefreshFailed = $metadata?->last_error_at !== null;
        $failureCodeValue = $metadata?->source_metadata['last_refresh_failure']['code'] ?? null;
        $failureCode = is_string($failureCodeValue)
            ? CompressionSyncFailureCode::tryFrom($failureCodeValue)
            : null;
        $attemptedBuildValue = $metadata?->source_metadata['last_refresh_failure']['attempted_build'] ?? null;

        $status = match (true) {
            ! $usable => CompressionDataStatus::MISSING,
            $lastRefreshFailed => CompressionDataStatus::STALE,
            default => CompressionDataStatus::AVAILABLE,
        };

        return new CompressionDataHealth(
            status: $status,
            usable: $usable,
            activeBuild: $usable ? $metadata->active_sde_build : null,
            importedAt: $usable ? $metadata->imported_at : null,
            lastCheckedAt: $metadata?->last_checked_at,
            lastRefreshFailed: $lastRefreshFailed,
            lastFailureCode: $failureCode,
            lastFailureMessage: $metadata?->last_error_message,
            lastAttemptedBuild: is_string($attemptedBuildValue) ? $attemptedBuildValue : null,
        );
    }
}
