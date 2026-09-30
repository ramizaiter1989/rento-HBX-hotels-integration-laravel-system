<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\HBX\HbxApiException;
use App\Http\Requests\CancelBookingRequest;
use App\Http\Requests\SimulateModificationRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\HotelBooking;
use App\Models\RateSelection;
use App\Services\HBX\BookingSnapshotComparer;
use App\Services\HBX\HbxBookingManagementService;
use App\Services\HBX\HbxBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function index(): View
    {
        return view('bookings.index', [
            'bookings' => HotelBooking::query()->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $selection = RateSelection::query()->with('search')->find($request->integer('selection'));

        if (! $selection) {
            return redirect()->route('hotels.search')->with('hbx_error', [
                'heading' => 'No rate selected',
                'code' => 'INVALID_DATA',
                'message' => 'Select a rate from an availability snapshot before booking.',
                'http_status' => null,
                'operation' => 'booking',
                'detail' => null,
            ]);
        }

        if (! $selection->bookingAllowed()) {
            return redirect()->route('hotels.results', $selection->hotel_search_id)->with('hbx_error', [
                'heading' => 'CheckRate is required',
                'code' => 'CHECKRATE_REQUIRED',
                'message' => 'RECHECK rates stay blocked until CheckRate succeeds and returns a BOOKABLE rate.',
                'http_status' => null,
                'operation' => 'booking',
                'detail' => null,
            ]);
        }

        return view('bookings.create', [
            'selection' => $selection,
            'submissionToken' => (string) Str::uuid(),
            'guestSlots' => $this->guestSlots($selection),
        ]);
    }

    public function store(StoreBookingRequest $request, HbxBookingService $bookings): RedirectResponse
    {
        $selection = RateSelection::query()->findOrFail($request->integer('selection_id'));
        $token = $request->string('submission_token')->toString();

        try {
            $booking = $bookings->book(
                $selection,
                $request->string('holder_name')->toString(),
                $request->string('holder_surname')->toString(),
                array_values($request->input('guests', [])),
                $request->input('remark') ?: 'Rento HBX local booking test',
                $token,
            );
        } catch (HbxApiException $exception) {
            $booking = HotelBooking::query()->where('submission_token', $token)->first();

            if ($booking) {
                return redirect()
                    ->route('bookings.show', $booking)
                    ->with('hbx_error', $exception->viewData());
            }

            throw $exception;
        }

        $message = match ($booking->status) {
            'CONFIRMED' => 'HBX confirmed this TEST booking.',
            HotelBooking::STATUS_AMBIGUOUS => 'The booking request ended without a supplier response. Do not submit it again.',
            HotelBooking::STATUS_FAILED => 'HBX did not confirm this booking.',
            HotelBooking::STATUS_PENDING => 'A booking attempt is already stored for this submission.',
            default => 'Booking response stored.',
        };

        return redirect()->route('bookings.show', $booking)->with('status', $message);
    }

    public function show(HotelBooking $booking, BookingSnapshotComparer $comparer): View
    {
        $booking->load([
            'rooms.guests',
            'rooms.taxes',
            'rooms.cancellationPolicies',
            'simulations',
            'apiLogs',
            'rateSelection',
        ]);

        return view('bookings.show', [
            'booking' => $booking,
            'comparison' => $comparer->compare($booking->raw_booking_response, $booking->live_hbx_response),
        ]);
    }

    public function refresh(HotelBooking $booking, HbxBookingManagementService $hbx): RedirectResponse
    {
        $hbx->refresh($booking);

        return back()->with('status', 'Current HBX state refreshed. The original confirmation snapshot was kept.');
    }

    public function simulateCancellation(HotelBooking $booking, HbxBookingManagementService $hbx): RedirectResponse
    {
        $simulation = $hbx->simulateCancellation($booking);

        return back()->with('status', 'Cancellation simulation stored. The real booking status was not changed. Simulation status: '.($simulation->simulated_status ?? 'unknown').'.');
    }

    public function cancel(CancelBookingRequest $request, HotelBooking $booking, HbxBookingManagementService $hbx): RedirectResponse
    {
        $hbx->cancel($booking);

        return back()->with('status', 'Actual TEST cancellation was sent. Refresh the booking if you need to reconcile the live HBX state.');
    }

    public function simulateModification(SimulateModificationRequest $request, HotelBooking $booking, HbxBookingManagementService $hbx): RedirectResponse
    {
        $hbx->simulateModification(
            $booking,
            $request->string('holder_name')->toString(),
            $request->string('holder_surname')->toString(),
        );

        return back()->with('status', 'Modification simulation stored. The real holder was not changed.');
    }

    public function modify(HotelBooking $booking, HbxBookingManagementService $hbx): never
    {
        $hbx->executeModification();
    }

    /**
     * @return list<array{room_id: int, type: string, age: int|null}>
     */
    private function guestSlots(RateSelection $selection): array
    {
        $search = $selection->search;
        $slots = [];
        $ages = array_values($search->child_ages ?? []);

        for ($room = 1; $room <= (int) $search->rooms_count; $room++) {
            for ($adult = 0; $adult < (int) $search->adults_count; $adult++) {
                $slots[] = ['room_id' => $room, 'type' => 'AD', 'age' => null];
            }

            for ($child = 0; $child < (int) $search->children_count; $child++) {
                $slots[] = ['room_id' => $room, 'type' => 'CH', 'age' => $ages[$child] ?? null];
            }
        }

        return $slots;
    }
}
