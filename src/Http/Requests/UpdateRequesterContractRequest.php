<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class UpdateRequesterContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $buybackRequest = $this->route('buybackRequest');

        return $buybackRequest instanceof BuybackRequest
            && $this->user()?->can('updateRequesterContract', $buybackRequest) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['eve_contract_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
