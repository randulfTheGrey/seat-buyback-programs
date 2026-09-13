<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('buyback.request') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'appraisal_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
        ];
    }
}
