<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class BuybackQuoteCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $quoteId,
        public string $quotePublicId,
        public int $programId,
        public int $requesterUserId,
    ) {
    }
}
