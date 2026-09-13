<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;

final class BuybackRequest extends Model
{
    use HasUlids;

    protected $table = 'buyback_requests';

    protected $hidden = [
        'manager_note',
    ];

    protected $fillable = [
        'public_id',
        'quote_id',
        'status',
        'submitted_at',
        'eve_contract_id',
        'requester_note',
        'manager_note',
        'rejection_reason',
        'completed_at',
        'completed_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'canceled_at',
        'canceled_by_user_id',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    protected $casts = [
        'status' => BuybackRequestStatus::class,
        'submitted_at' => 'immutable_datetime',
        'eve_contract_id' => 'integer',
        'completed_at' => 'immutable_datetime',
        'completed_by_user_id' => 'integer',
        'rejected_at' => 'immutable_datetime',
        'rejected_by_user_id' => 'integer',
        'canceled_at' => 'immutable_datetime',
        'canceled_by_user_id' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(BuybackQuote::class, 'quote_id');
    }

    public function scopeOwnedByRequester(Builder $query, int $requesterUserId): Builder
    {
        return $query->whereHas(
            'quote',
            static fn (Builder $quote): Builder => $quote->where('requester_user_id', $requesterUserId),
        );
    }

    public function isPending(): bool
    {
        return $this->status === BuybackRequestStatus::PENDING;
    }

    protected static function booted(): void
    {
        static::updating(static function (BuybackRequest $request): void {
            if ($request->isDirty([
                'status',
                'rejection_reason',
                'completed_at',
                'completed_by_user_id',
                'rejected_at',
                'rejected_by_user_id',
                'canceled_at',
                'canceled_by_user_id',
            ])) {
                throw new LogicException('Buyback Request transitions must use the lifecycle services.');
            }

            $originalStatus = BuybackRequestStatus::tryFrom((string) $request->getRawOriginal('status'));

            if (
                $originalStatus !== BuybackRequestStatus::PENDING
                && $request->isDirty(['eve_contract_id', 'requester_note', 'manager_note'])
            ) {
                throw new LogicException('Terminal Buyback Requests are immutable.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Buyback Requests are retained as lifecycle history.');
        });
    }
}
