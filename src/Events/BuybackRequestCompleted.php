<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class BuybackRequestCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $requestId,
        public string $requestPublicId,
        public string $quotePublicId,
        public int $completedByUserId,
    ) {
    }
}
