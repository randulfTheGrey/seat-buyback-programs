<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAppraisalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('buyback.request') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'inventory' => ['required', 'string'],
        ];
    }
}
