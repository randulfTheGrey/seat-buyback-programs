<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin;

final readonly class CompressionSyncDispatchResult
{
    public function __construct(
        public bool $dispatched,
        public string $message,
    ) {
    }
}
