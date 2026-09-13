<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum BuybackRequestStatus: string
{
    case PENDING = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELED = 'CANCELED';
}
