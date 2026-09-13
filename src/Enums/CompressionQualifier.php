<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum CompressionQualifier: string
{
    case ANY = 'ANY';
    case COMPRESSED = 'COMPRESSED';
    case UNCOMPRESSED = 'UNCOMPRESSED';
}
