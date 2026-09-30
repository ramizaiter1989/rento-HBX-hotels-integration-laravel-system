<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RateSelection;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
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
            'selection_id' => ['required', 'integer', 'exists:rate_selections,id'],
            'holder_name' => ['required', 'string', 'max:80'],
            'holder_surname' => ['required', 'string', 'max:80'],
            'remark' => ['nullable', 'string', 'max:255'],
            'submission_token' => ['required', 'uuid'],
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.room_id' => ['required', 'integer', 'min:1'],
            'guests.*.type' => ['required', 'in:AD,CH'],
            'guests.*.name' => ['required', 'string', 'max:80'],
            'guests.*.surname' => ['required', 'string', 'max:80'],
            'guests.*.age' => ['nullable', 'integer', 'min:0', 'max:17'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $selection = RateSelection::query()->with('search')->find($this->input('selection_id'));

            if (! $selection || ! $selection->search) {
                return;
            }

            $rooms = (int) $selection->search->rooms_count;
            $adults = (int) $selection->search->adults_count;
            $children = (int) $selection->search->children_count;
            $guests = $this->input('guests', []);
            $adultCount = 0;
            $childCount = 0;

            foreach ($guests as $index => $guest) {
                if (! is_array($guest)) {
                    continue;
                }

                $roomId = (int) ($guest['room_id'] ?? 0);

                if ($roomId < 1 || $roomId > $rooms) {
                    $validator->errors()->add("guests.$index.room_id", 'Guest room does not match the searched occupancy.');
                }

                if (($guest['type'] ?? null) === 'AD') {
                    $adultCount++;
                }

                if (($guest['type'] ?? null) === 'CH') {
                    $childCount++;

                    if (! isset($guest['age']) || $guest['age'] === '') {
                        $validator->errors()->add("guests.$index.age", 'Child guests require an age.');
                    }
                }
            }

            if ($adultCount !== $rooms * $adults) {
                $validator->errors()->add('guests', "This search requires {$rooms} room(s) with {$adults} adult(s) each.");
            }

            if ($childCount !== $rooms * $children) {
                $validator->errors()->add('guests', "This search requires {$children} child guest(s) per room.");
            }
        });
    }
}
