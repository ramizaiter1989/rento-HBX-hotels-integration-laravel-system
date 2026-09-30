<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HotelSearch;
use App\Services\HBX\RateSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RateSelectionController extends Controller
{
    public function store(Request $request, HotelSearch $search, RateSelectionService $selections): RedirectResponse
    {
        $rateKey = $request->input('rate_key');

        if (! is_string($rateKey) || $rateKey === '') {
            abort(422, 'A rateKey is required.');
        }

        $selection = $selections->selectFromAvailability($search, $rateKey);

        if (! $selection->bookingAllowed()) {
            return redirect()
                ->route('hotels.results', $search)
                ->with('hbx_error', [
                    'heading' => 'CheckRate is required',
                    'code' => 'CHECKRATE_REQUIRED',
                    'message' => 'RECHECK rates cannot be booked until CheckRate returns a bookable rateKey.',
                    'http_status' => null,
                    'operation' => 'booking',
                    'detail' => null,
                ]);
        }

        return redirect()->route('bookings.create', ['selection' => $selection->id]);
    }
}
