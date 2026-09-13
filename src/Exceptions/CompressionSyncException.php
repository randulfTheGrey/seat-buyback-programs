<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Exceptions;

use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use Throwable;

final class CompressionSyncException extends RuntimeException
{
    public function __construct(
        public readonly CompressionSyncFailureCode $failureCode,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
