<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Exceptions;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RuntimeException;
use Throwable;

final class RequestCreationFromAppraisalException extends RuntimeException
{
    public function __construct(
        public readonly BuybackQuote $quote,
        Throwable $previous,
    ) {
        parent::__construct(
            'The Quote was saved, but the Buyback Request could not be created.',
            previous: $previous,
        );
    }
}
