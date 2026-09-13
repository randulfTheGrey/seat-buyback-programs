<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class BuybackRequestSubmitted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $requestId,
        public string $requestPublicId,
        public int $quoteId,
        public string $quotePublicId,
        public int $requesterUserId,
    ) {
    }
}
