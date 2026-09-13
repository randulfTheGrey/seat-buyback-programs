<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;

final class CompressionMetadata extends Model
{
    protected $table = 'buyback_compression_metadata';

    protected $fillable = [
        'active_sde_build',
        'source',
        'source_metadata',
        'imported_at',
        'activated_at',
        'last_checked_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $casts = [
        'source_metadata' => 'array',
        'imported_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'last_error_at' => 'immutable_datetime',
    ];
}
