<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum CompressionDataStatus: string
{
    case MISSING = 'MISSING';
    case AVAILABLE = 'AVAILABLE';
    case STALE = 'STALE';
}
