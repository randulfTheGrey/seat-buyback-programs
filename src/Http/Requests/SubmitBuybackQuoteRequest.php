<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;

final class SubmitBuybackQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof BuybackQuote
            && $this->user()?->can('submit', $quote) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'eve_contract_id' => ['nullable', 'integer', 'min:1'],
            'requester_note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
