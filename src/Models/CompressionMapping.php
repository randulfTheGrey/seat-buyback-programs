<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;

final class CompressionMapping extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'buyback_compression_mappings';

    protected $primaryKey = 'uncompressed_type_id';

    protected $keyType = 'int';

    protected $fillable = [
        'uncompressed_type_id',
        'compressed_type_id',
    ];

    protected $casts = [
        'uncompressed_type_id' => 'integer',
        'compressed_type_id' => 'integer',
    ];
}
