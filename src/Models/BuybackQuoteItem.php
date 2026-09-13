<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use RandulfTheGrey\Seat\BuybackPrograms\Casts\BasisPointsCast;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;

final class BuybackQuoteItem extends Model
{
    public $timestamps = false;

    protected $table = 'buyback_quote_items';

    protected $fillable = [
        'quote_id',
        'type_id',
        'type_name',
        'quantity',
        'compression_state',
        'reference_mode',
        'reference_resolution',
        'reference_unit_price',
        'effective_modifier_bps',
        'final_unit_price',
        'line_total',
        'policy_snapshot',
        'pricing_snapshot',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'quantity' => 'integer',
        'compression_state' => CompressionState::class,
        'reference_mode' => ReferenceMode::class,
        'reference_resolution' => ReferenceResolution::class,
        'reference_unit_price' => 'decimal:18',
        'effective_modifier_bps' => BasisPointsCast::class,
        'final_unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'policy_snapshot' => 'array',
        'pricing_snapshot' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(BuybackQuote::class, 'quote_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Buyback Quote items are immutable after creation.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Buyback Quote items are immutable after creation.');
        });
    }
}
