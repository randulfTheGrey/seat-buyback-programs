<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum ReferenceMode: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';
    case SPLIT = 'SPLIT';
}
