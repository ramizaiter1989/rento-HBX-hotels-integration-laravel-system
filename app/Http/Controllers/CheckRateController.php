<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HotelSearch;
use App\Services\HBX\HbxCheckRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckRateController extends Controller
{
    public function store(Request $request, HotelSearch $search, HbxCheckRateService $checkRate): RedirectResponse
    {
        $rateKey = $request->input('rate_key');

        if (! is_string($rateKey) || $rateKey === '') {
            return back()->with('hbx_error', [
                'heading' => 'Rate key missing',
                'code' => 'INVALID_DATA',
                'message' => 'Choose a rate returned by this availability snapshot.',
                'http_status' => null,
                'operation' => 'checkrate',
                'detail' => null,
            ]);
        }

        $selection = $checkRate->check($search, $rateKey);

        return redirect()
            ->route('hotels.results', $search)
            ->with('status', $selection->bookingAllowed()
                ? 'CheckRate completed. Booking will use the latest returned rateKey.'
                : 'CheckRate completed, but the supplier still marks this rate as RECHECK.');
    }
}
