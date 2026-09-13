<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum ProgramStatus: string
{
    case ENABLED = 'ENABLED';
    case DISABLED = 'DISABLED';
    case ARCHIVED = 'ARCHIVED';
}
