<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;
use App\Models\BookingSimulation;
use App\Models\HotelBooking;
use App\Support\DecimalString;
use App\Support\JsonDecimals;
use Illuminate\Support\Carbon;

final class HbxBookingManagementService
{
    public function __construct(
        private readonly HbxClient $client,
        private readonly BookingResponsePersister $persister,
    ) {}

    public function detail(HotelBooking $booking): HbxResult
    {
        $reference = $this->reference($booking);

        return $this->client->get(
            config('hbx.endpoints.bookings').'/'.rawurlencode($reference),
            [],
            'booking_detail',
            $this->context($booking)
        );
    }

    public function refresh(HotelBooking $booking): HotelBooking
    {
        $result = $this->detail($booking);
        $this->persister->applyLive($booking, $result->data, $result->rawBody);

        return $booking->refresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): HbxResult
    {
        return $this->client->get(
            (string) config('hbx.endpoints.bookings'),
            [
                'start' => $filters['start'],
                'end' => $filters['end'],
                'filterType' => $filters['filter_type'],
                'status' => $filters['status'],
                'from' => $filters['from'],
                'to' => $filters['to'],
            ],
            'booking_list'
        );
    }

    public function simulateCancellation(HotelBooking $booking): BookingSimulation
    {
        $reference = $this->reference($booking);
        $query = [
            'cancellationFlag' => 'SIMULATION',
            'language' => 'ENG',
        ];

        $result = $this->client->delete(
            config('hbx.endpoints.bookings').'/'.rawurlencode($reference),
            $query,
            'cancellation_simulation',
            $this->context($booking)
        );

        $node = $result->bookingNode();
        $hotel = is_array($node['hotel'] ?? null) ? $node['hotel'] : [];

        return BookingSimulation::query()->create([
            'hotel_booking_id' => $booking->id,
            'type' => 'cancellation',
            'simulated_status' => $node['status'] ?? null,
            'cancellation_amount' => DecimalString::from($hotel['cancellationAmount'] ?? $node['cancellationAmount'] ?? null),
            'currency' => $hotel['currency'] ?? $node['currency'] ?? $booking->currency,
            'supplier_cancellation_reference' => $node['cancellationReference'] ?? null,
            'request_payload' => JsonDecimals::encode($query),
            'response_payload' => $result->rawBody,
            'simulated_at' => Carbon::now(),
        ]);
    }

    public function cancel(HotelBooking $booking): HotelBooking
    {
        $reference = $this->reference($booking);
        $result = $this->client->delete(
            config('hbx.endpoints.bookings').'/'.rawurlencode($reference),
            [
                'cancellationFlag' => 'CANCELLATION',
                'language' => 'ENG',
            ],
            'cancellation',
            $this->context($booking)
        );

        $this->persister->applyCancellation($booking, $result->data, $result->rawBody);

        return $booking->refresh();
    }

    public function simulateModification(HotelBooking $booking, string $holderName, string $holderSurname): BookingSimulation
    {
        $reference = $this->reference($booking);
        $detail = $this->detail($booking);
        $source = $detail->bookingNode();

        if ($source === []) {
            throw new HbxValidationException(
                'Booking Detail did not return a booking, so the modification simulation was not sent.',
                'INVALID_DATA',
                null,
                [],
                'modification_simulation'
            );
        }

        $policies = is_array($source['modificationPolicies'] ?? null) ? $source['modificationPolicies'] : [];

        if (array_key_exists('modification', $policies) && $policies['modification'] === false) {
            throw new HbxValidationException(
                'HBX does not allow modification of this booking. The simulation was not sent.',
                'INVALID_DATA',
                null,
                [],
                'modification_simulation'
            );
        }

        $snapshot = $this->copyBooking($source);

        if (! isset($snapshot['holder']) || ! is_array($snapshot['holder'])) {
            $snapshot['holder'] = [];
        }

        $snapshot['holder']['name'] = $holderName;
        $snapshot['holder']['surname'] = $holderSurname;

        $payload = [
            'mode' => 'SIMULATION',
            'booking' => $snapshot,
        ];

        $result = $this->client->put(
            config('hbx.endpoints.bookings').'/'.rawurlencode($reference),
            $payload,
            'modification_simulation',
            $this->context($booking)
        );

        $node = $result->bookingNode();
        $holder = is_array($node['holder'] ?? null) ? $node['holder'] : [];

        return BookingSimulation::query()->create([
            'hotel_booking_id' => $booking->id,
            'type' => 'modification',
            'simulated_status' => $node['status'] ?? null,
            'holder_name' => $holder['name'] ?? $holderName,
            'holder_surname' => $holder['surname'] ?? $holderSurname,
            'currency' => $node['currency'] ?? $booking->currency,
            'request_payload' => JsonDecimals::encode($payload),
            'response_payload' => $result->rawBody,
            'simulated_at' => Carbon::now(),
        ]);
    }

    public function executeModification(): never
    {
        throw new HbxValidationException(
            'Actual HBX modification execution is pending supplier verification.',
            'MODIFICATION_UNVERIFIED',
            null,
            [],
            'modification'
        );
    }

    private function reference(HotelBooking $booking): string
    {
        if (! is_string($booking->hbx_reference) || $booking->hbx_reference === '') {
            throw new HbxValidationException(
                'This local booking has no HBX reference yet.',
                'INVALID_DATA',
                null,
                [],
                'booking_detail'
            );
        }

        return $booking->hbx_reference;
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<string, mixed>
     */
    private function copyBooking(array $booking): array
    {
        $copy = [];

        foreach ($booking as $key => $value) {
            $copy[$key] = is_array($value) ? $this->copyBooking($value) : $value;
        }

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(HotelBooking $booking): array
    {
        return [
            'hotel_booking_id' => $booking->id,
            'hotel_search_id' => $booking->hotel_search_id,
            'hbx_reference' => $booking->hbx_reference,
            'client_reference' => $booking->client_reference,
        ];
    }
}
