<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHotelSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:5'],
            'adults' => ['required', 'integer', 'min:1', 'max:8'],
            'children' => ['required', 'integer', 'min:0', 'max:4'],
            'child_ages' => ['nullable', 'array'],
            'child_ages.*' => ['integer', 'min:0', 'max:17'],
            'destination_code' => ['nullable', 'string', 'max:8', 'required_without:hotel_code', 'prohibits:hotel_code'],
            'hotel_code' => ['nullable', 'string', 'max:80', 'required_without:destination_code', 'prohibits:destination_code'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_code.prohibits' => 'Send either a destination code or hotel codes, not both.',
            'hotel_code.prohibits' => 'Send either a destination code or hotel codes, not both.',
            'destination_code.required_without' => 'Enter a destination code or a hotel code.',
            'hotel_code.required_without' => 'Enter a destination code or a hotel code.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $children = (int) $this->input('children', 0);
            $ages = array_filter((array) $this->input('child_ages', []), fn ($age): bool => $age !== null && $age !== '');

            if ($children !== count($ages)) {
                $validator->errors()->add('child_ages', 'Provide one age for each child.');
            }
        });
    }
}
