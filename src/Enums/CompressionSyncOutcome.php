<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum CompressionSyncOutcome: string
{
    case UPDATED = 'UPDATED';
    case CURRENT = 'CURRENT';
    case FAILED = 'FAILED';
    case LOCKED = 'LOCKED';
}
