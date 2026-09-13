<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class BuybackRequestRejected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $requestId,
        public string $requestPublicId,
        public string $quotePublicId,
        public int $rejectedByUserId,
    ) {
    }
}
