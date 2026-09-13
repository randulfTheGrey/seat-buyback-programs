<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RandulfTheGrey\Seat\BuybackPrograms\Casts\BasisPointsCast;
use RandulfTheGrey\Seat\BuybackPrograms\Casts\RuleTargetIdCast;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;

final class BuybackRule extends Model
{
    protected $table = 'buyback_rules';

    protected $fillable = [
        'program_id',
        'target_type',
        'target_id',
        'compression_qualifier',
        'acceptance',
        'reference_mode_override',
        'modifier_operation',
        'modifier_bps',
        'enabled',
        'archived_at',
        'admin_note',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'target_type' => RuleTargetType::class,
        'target_id' => RuleTargetIdCast::class,
        'compression_qualifier' => CompressionQualifier::class,
        'acceptance' => RuleAcceptance::class,
        'reference_mode_override' => ReferenceMode::class,
        'modifier_operation' => ModifierOperation::class,
        'modifier_bps' => BasisPointsCast::class,
        'enabled' => 'boolean',
        'archived_at' => 'immutable_datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BuybackProgram::class, 'program_id');
    }
}
