<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;

final readonly class CompressionSyncResult
{
    public function __construct(
        public CompressionSyncOutcome $outcome,
        public ?string $build = null,
        public ?int $mappingCount = null,
        public ?CompressionSyncFailureCode $failureCode = null,
        public ?string $message = null,
    ) {
    }
}
