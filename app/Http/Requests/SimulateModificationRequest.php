<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulateModificationRequest extends FormRequest
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
            'holder_name' => ['required', 'string', 'max:80'],
            'holder_surname' => ['required', 'string', 'max:80'],
        ];
    }
}
