<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\HBX\AvailabilityQuery;
use App\Http\Requests\StoreHotelSearchRequest;
use App\Models\HotelSearch;
use App\Services\Content\ContentAvailabilityEnricher;
use App\Services\HBX\AvailabilitySnapshotReader;
use App\Services\HBX\HbxAvailabilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class HotelSearchController extends Controller
{
    public function create(): View
    {
        return view('hotels.search', [
            'defaults' => [
                'check_in' => now()->addDays(14)->toDateString(),
                'check_out' => now()->addDays(15)->toDateString(),
                'rooms' => 1,
                'adults' => 2,
                'children' => 0,
                'destination_code' => 'PMI',
                'hotel_code' => '',
            ],
        ]);
    }

    public function store(StoreHotelSearchRequest $request, HbxAvailabilityService $availability): RedirectResponse
    {
        $result = $availability->search(AvailabilityQuery::fromInput($request->validated()));

        return redirect()
            ->route('hotels.results', $result->search)
            ->with('status', $result->message())
            ->with('availability_source', $result->source);
    }

    public function show(HotelSearch $search, AvailabilitySnapshotReader $snapshots, ContentAvailabilityEnricher $content): View
    {
        $page = max(1, (int) request()->query('page', 1));
        $snapshot = $snapshots->getHotelSummaries($search, $page, AvailabilitySnapshotReader::PER_PAGE);
        $hotels = new LengthAwarePaginator(
            $snapshot['hotels'],
            $snapshot['total'],
            $snapshot['per_page'],
            $snapshot['page'],
            ['path' => route('hotels.results', $search)],
        );

        return view('hotels.results', [
            'search' => $search,
            'hotels' => $hotels,
            'selections' => $search->rateSelections()->latest('id')->get(),
            'enrichment' => $content->forHotels(array_column($snapshot['hotels'], 'code')),
        ]);
    }

    public function rooms(HotelSearch $search, string $hotelCode, AvailabilitySnapshotReader $snapshots, ContentAvailabilityEnricher $content): View
    {
        $hotel = $snapshots->getRoomsForHotel($search, $hotelCode);
        abort_if($hotel === null, 404);
        abort_unless(preg_match('/^\d{1,10}$/', $hotelCode) === 1, 404);

        $data = [
            'search' => $search,
            'hotel' => $hotel,
            'selections' => $search->rateSelections()->latest('id')->get(),
            'audit' => $content->roomAudit($hotelCode, is_array($hotel['rooms'] ?? null) ? $hotel['rooms'] : []),
        ];

        if (request()->ajax()) {
            return view('hotels.rooms', $data);
        }

        return view('hotels.rooms-page', $data);
    }

    public function raw(HotelSearch $search, AvailabilitySnapshotReader $snapshots): View
    {
        return view('developer.search-raw', [
            'search' => $search,
            'raw' => $snapshots->getRawResponse($search),
        ]);
    }
}
