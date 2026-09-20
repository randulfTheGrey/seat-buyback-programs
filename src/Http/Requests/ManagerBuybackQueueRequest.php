<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;

final class ManagerBuybackQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('randulfthegrey-buyback.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(BuybackRequestStatus::class)],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'requester' => ['nullable', 'string', 'max:100'],
            'submitted_from' => ['nullable', 'date_format:Y-m-d'],
            'submitted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:submitted_from'],
        ];
    }
}
