<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\QuoteState;

final class BuybackQuote extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'buyback_quotes';

    protected $hidden = [
        'appraisal_token_hash',
    ];

    protected $fillable = [
        'public_id',
        'appraisal_token_hash',
        'program_id',
        'requester_user_id',
        'requester_name_snapshot',
        'program_name_snapshot',
        'pricing_completed_at',
        'quoted_at',
        'expires_at',
        'payable_total',
    ];

    protected $casts = [
        'pricing_completed_at' => 'immutable_datetime',
        'quoted_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'payable_total' => 'decimal:2',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(BuybackProgram::class, 'program_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BuybackQuoteItem::class, 'quote_id');
    }

    public function buybackRequest(): HasOne
    {
        return $this->hasOne(BuybackRequest::class, 'quote_id');
    }

    public function scopeAvailableForRequester(
        Builder $query,
        int $requesterUserId,
        ?\DateTimeInterface $now = null,
    ): Builder {
        return $query
            ->where('requester_user_id', $requesterUserId)
            ->where('expires_at', '>', $now ?? now())
            ->whereDoesntHave('buybackRequest');
    }

    public function stateAt(?\DateTimeInterface $now = null): QuoteState
    {
        if ($this->relationLoaded('buybackRequest') ? $this->buybackRequest !== null : $this->buybackRequest()->exists()) {
            return QuoteState::SUBMITTED;
        }

        $now ??= now();

        return $this->expires_at->lessThanOrEqualTo($now)
            ? QuoteState::EXPIRED
            : QuoteState::AVAILABLE;
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Buyback Quotes are immutable after creation.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Buyback Quotes are immutable after creation.');
        });
    }
}
