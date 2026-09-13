<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;

final class ProgramPriceReference extends Model
{
    protected $table = 'buyback_program_price_references';

    protected $fillable = [
        'program_id',
        'reference_mode',
        'resolution',
        'provider_instance_id',
    ];

    protected $casts = [
        'reference_mode' => ReferenceMode::class,
        'resolution' => ReferenceResolution::class,
        'provider_instance_id' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BuybackProgram::class, 'program_id');
    }
}
