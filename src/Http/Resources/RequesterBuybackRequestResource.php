<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RequesterBuybackRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('quote');

        return [
            'public_id' => $this->public_id,
            'quote_public_id' => $this->quote->public_id,
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'eve_contract_id' => $this->eve_contract_id,
            'requester_note' => $this->requester_note,
            'rejection_reason' => $this->rejection_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'payable_total' => $this->quote->payable_total,
        ];
    }
}
