<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ManagerBuybackRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('quote');

        return [
            'public_id' => $this->public_id,
            'quote_public_id' => $this->quote->public_id,
            'requester_user_id' => $this->quote->requester_user_id,
            'requester_name' => $this->quote->requester_name_snapshot,
            'program_id' => $this->quote->program_id,
            'program_name' => $this->quote->program_name_snapshot,
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'eve_contract_id' => $this->eve_contract_id,
            'requester_note' => $this->requester_note,
            'manager_note' => $this->manager_note,
            'rejection_reason' => $this->rejection_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by_user_id' => $this->completed_by_user_id,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'canceled_by_user_id' => $this->canceled_by_user_id,
            'payable_total' => $this->quote->payable_total,
        ];
    }
}
