<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression;

use Carbon\CarbonImmutable;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;

final readonly class CompressionDataHealth
{
    public function __construct(
        public CompressionDataStatus $status,
        public bool $usable,
        public ?string $activeBuild,
        public ?CarbonImmutable $importedAt,
        public ?CarbonImmutable $lastCheckedAt,
        public bool $lastRefreshFailed,
        public ?CompressionSyncFailureCode $lastFailureCode,
        public ?string $lastFailureMessage,
        public ?string $lastAttemptedBuild,
    ) {
    }
}
