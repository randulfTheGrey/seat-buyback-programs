<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum QuoteState: string
{
    case AVAILABLE = 'AVAILABLE';
    case SUBMITTED = 'SUBMITTED';
    case EXPIRED = 'EXPIRED';
}
