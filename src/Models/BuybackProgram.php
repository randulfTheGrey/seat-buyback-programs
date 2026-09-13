<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RandulfTheGrey\Seat\BuybackPrograms\Casts\BasisPointsCast;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;

final class BuybackProgram extends Model
{
    protected $table = 'buyback_programs';

    protected $fillable = [
        'name',
        'description',
        'status',
        'default_acceptance',
        'default_reference_mode',
        'default_modifier_bps',
        'quote_validity_minutes',
        'contract_instructions',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'status' => 'DISABLED',
        'default_acceptance' => 'ACCEPT',
    ];

    protected $casts = [
        'status' => ProgramStatus::class,
        'default_acceptance' => Acceptance::class,
        'default_reference_mode' => ReferenceMode::class,
        'default_modifier_bps' => BasisPointsCast::class,
        'quote_validity_minutes' => 'integer',
    ];

    public function priceReferences(): HasMany
    {
        return $this->hasMany(ProgramPriceReference::class, 'program_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BuybackRule::class, 'program_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(BuybackQuote::class, 'program_id');
    }
}
