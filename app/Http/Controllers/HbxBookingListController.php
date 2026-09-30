<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HotelBooking;
use App\Services\HBX\HbxBookingManagementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HbxBookingListController extends Controller
{
    public function __invoke(Request $request, HbxBookingManagementService $hbx): View
    {
        $filters = [
            'start' => $request->input('start', now()->toDateString()),
            'end' => $request->input('end', now()->toDateString()),
            'filter_type' => $request->input('filter_type', 'CREATION'),
            'status' => $request->input('status', 'ALL'),
            'from' => max(1, (int) $request->input('from', 1)),
            'to' => max(1, (int) $request->input('to', 25)),
        ];

        $result = null;
        $rows = [];

        if ($request->boolean('fetch')) {
            $validated = $request->validate([
                'start' => ['required', 'date'],
                'end' => ['required', 'date', 'after_or_equal:start'],
                'filter_type' => ['required', 'in:CREATION,CHECKIN'],
                'status' => ['required', 'in:ALL,CONFIRMED,CANCELLED'],
                'from' => ['required', 'integer', 'min:1'],
                'to' => ['required', 'integer', 'gte:from', 'max:100'],
            ]);

            $filters = [
                'start' => $validated['start'],
                'end' => $validated['end'],
                'filter_type' => $validated['filter_type'],
                'status' => $validated['status'],
                'from' => (int) $validated['from'],
                'to' => (int) $validated['to'],
            ];

            $result = $hbx->list($filters);
            $bookings = $result->data['bookings']['bookings'] ?? [];
            $references = [];
            $clients = [];

            foreach ($bookings as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (isset($row['reference'])) {
                    $references[] = (string) $row['reference'];
                }

                if (isset($row['clientReference'])) {
                    $clients[] = (string) $row['clientReference'];
                }
            }

            $local = HotelBooking::query()
                ->whereIn('hbx_reference', $references)
                ->orWhereIn('client_reference', $clients)
                ->get();

            foreach ($bookings as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $match = $local->first(function (HotelBooking $booking) use ($row): bool {
                    return ($row['reference'] ?? null) === $booking->hbx_reference
                        || ($row['clientReference'] ?? null) === $booking->client_reference;
                });

                $rows[] = [
                    'supplier' => $row,
                    'local' => $match,
                    'known' => $match !== null,
                ];
            }
        }

        return view('bookings.hbx', [
            'filters' => $filters,
            'result' => $result,
            'rows' => $rows,
        ]);
    }
}
