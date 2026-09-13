<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum CompressionState: string
{
    case COMPRESSED = 'COMPRESSED';
    case UNCOMPRESSED = 'UNCOMPRESSED';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
