<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class RejectBuybackRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $buybackRequest = $this->route('buybackRequest');

        return $buybackRequest instanceof BuybackRequest
            && $this->user()?->can('reject', $buybackRequest) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['rejection_reason' => ['required', 'string', 'max:10000', 'not_regex:/^\s*$/u']];
    }
}
