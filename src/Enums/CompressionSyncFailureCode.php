<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum CompressionSyncFailureCode: string
{
    case BUILD_LOOKUP_FAILURE = 'BUILD_LOOKUP_FAILURE';
    case DOWNLOAD_FAILURE = 'DOWNLOAD_FAILURE';
    case ARCHIVE_FAILURE = 'ARCHIVE_FAILURE';
    case PARSE_FAILURE = 'PARSE_FAILURE';
    case VALIDATION_FAILURE = 'VALIDATION_FAILURE';
    case ACTIVATION_FAILURE = 'ACTIVATION_FAILURE';
    case CONCURRENT_SYNC = 'CONCURRENT_SYNC';
}
