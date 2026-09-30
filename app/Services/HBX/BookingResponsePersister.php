<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Models\BookingCancellationPolicy;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\BookingTax;
use App\Models\HotelBooking;
use App\Support\DecimalString;
use Illuminate\Support\Carbon;

final class BookingResponsePersister
{
    public function __construct(private readonly AvailabilityResultReader $reader) {}

    /**
     * @param  list<array<string, mixed>>  $intendedGuests
     */
    public function storeIntent(HotelBooking $booking, array $intendedGuests, string $rateKey): void
    {
        $room = $booking->rooms()->create([
            'room_code' => $booking->rateSelection?->room_code,
            'room_name' => $booking->rateSelection?->room_name,
            'rate_class' => $booking->rateSelection?->rate_class,
            'rate_type' => $booking->rateSelection?->rate_type,
            'rate_key' => $rateKey,
            'net' => $booking->rateSelection?->net,
            'currency' => $booking->currency,
            'board_code' => $booking->rateSelection?->board_code,
            'board_name' => $booking->rateSelection?->board_name,
            'payment_type' => $booking->payment_type,
            'packaging' => $booking->rateSelection?->packaging,
            'allotment' => $booking->rateSelection?->allotment,
            'rate_comments' => $booking->rateSelection?->rate_comments,
        ]);

        foreach ($intendedGuests as $guest) {
            $room->guests()->create([
                'room_id' => (int) $guest['room_id'],
                'type' => $guest['type'],
                'name' => $guest['name'],
                'surname' => $guest['surname'],
                'age' => $guest['age'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyConfirmation(HotelBooking $booking, array $data, string $rawBody): void
    {
        $this->applySupplierBooking($booking, $data, $rawBody, preserveRaw: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyLive(HotelBooking $booking, array $data, string $rawBody): void
    {
        $this->applySupplierBooking($booking, $data, $rawBody, preserveRaw: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyCancellation(HotelBooking $booking, array $data, string $rawBody): void
    {
        $node = is_array($data['booking'] ?? null) ? $data['booking'] : [];
        $hotel = is_array($node['hotel'] ?? null) ? $node['hotel'] : [];
        $amount = DecimalString::from($hotel['cancellationAmount'] ?? $node['cancellationAmount'] ?? null);

        $booking->forceFill([
            'status' => $node['status'] ?? 'CANCELLED',
            'hbx_reference' => $node['reference'] ?? $booking->hbx_reference,
            'cancellation_reference' => $node['cancellationReference'] ?? $booking->cancellation_reference,
            'cancellation_amount' => $amount,
            'currency' => $hotel['currency'] ?? $node['currency'] ?? $booking->currency,
            'live_hbx_response' => $rawBody,
            'last_hbx_sync_at' => Carbon::now(),
            'cancelled_at' => ($node['status'] ?? null) === 'CANCELLED' ? Carbon::now() : $booking->cancelled_at,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySupplierBooking(HotelBooking $booking, array $data, string $rawBody, bool $preserveRaw): void
    {
        $node = is_array($data['booking'] ?? null) ? $data['booking'] : [];
        $hotel = is_array($node['hotel'] ?? null) ? $node['hotel'] : [];
        $holder = is_array($node['holder'] ?? null) ? $node['holder'] : [];
        $supplier = is_array($hotel['supplier'] ?? null) ? $hotel['supplier'] : [];
        $policies = is_array($node['modificationPolicies'] ?? null) ? $node['modificationPolicies'] : [];
        $currency = $node['currency'] ?? $hotel['currency'] ?? $booking->currency;

        $attributes = [
            'status' => $node['status'] ?? $booking->status,
            'hbx_reference' => $node['reference'] ?? $booking->hbx_reference,
            'holder_name' => $holder['name'] ?? $booking->holder_name,
            'holder_surname' => $holder['surname'] ?? $booking->holder_surname,
            'hotel_code' => isset($hotel['code']) ? (string) $hotel['code'] : $booking->hotel_code,
            'hotel_name' => $hotel['name'] ?? $booking->hotel_name,
            'category_name' => $hotel['categoryName'] ?? $booking->category_name,
            'destination_name' => $hotel['destinationName'] ?? $booking->destination_name,
            'zone_name' => $hotel['zoneName'] ?? $booking->zone_name,
            'check_in' => $hotel['checkIn'] ?? $booking->check_in,
            'check_out' => $hotel['checkOut'] ?? $booking->check_out,
            'currency' => $currency,
            'total_net' => DecimalString::from($node['totalNet'] ?? $hotel['totalNet'] ?? null) ?? $booking->total_net,
            'pending_amount' => DecimalString::from($node['pendingAmount'] ?? null) ?? $booking->pending_amount,
            'supplier_name' => $supplier['name'] ?? $booking->supplier_name,
            'supplier_vat' => $supplier['vatNumber'] ?? $booking->supplier_vat,
            'creation_date' => isset($node['creationDate']) ? (string) $node['creationDate'] : $booking->creation_date,
            'modification_allowed' => array_key_exists('modification', $policies) ? (bool) $policies['modification'] : $booking->modification_allowed,
            'cancellation_allowed' => array_key_exists('cancellation', $policies) ? (bool) $policies['cancellation'] : $booking->cancellation_allowed,
            'remark' => $node['remark'] ?? $booking->remark,
            'live_hbx_response' => $rawBody,
            'last_hbx_sync_at' => Carbon::now(),
        ];

        if ($preserveRaw || $booking->raw_booking_response === null) {
            $attributes['raw_booking_response'] = $rawBody;
        }

        $booking->forceFill($attributes)->save();
        $this->replaceRooms($booking, $hotel, $currency);
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function replaceRooms(HotelBooking $booking, array $hotel, ?string $currency): void
    {
        if (! isset($hotel['rooms']) || ! is_array($hotel['rooms']) || $hotel['rooms'] === []) {
            return;
        }

        $booking->rooms()->delete();

        foreach ($hotel['rooms'] as $room) {
            if (! is_array($room)) {
                continue;
            }

            $rate = is_array($room['rates'][0] ?? null) ? $room['rates'][0] : [];
            $presented = isset($rate['rateKey']) || $rate !== []
                ? $this->reader->rate($rate + ['rateKey' => $rate['rateKey'] ?? ''])
                : null;

            if ($presented !== null && ($presented['rate_key'] ?? '') === '' && ! isset($rate['rateKey'])) {
                $presented['rate_key'] = null;
            }

            $model = BookingRoom::query()->create([
                'hotel_booking_id' => $booking->id,
                'supplier_room_id' => isset($room['id']) ? (int) $room['id'] : null,
                'room_code' => $room['code'] ?? null,
                'room_name' => $room['name'] ?? null,
                'status' => $room['status'] ?? null,
                'board_code' => $rate['boardCode'] ?? null,
                'board_name' => $rate['boardName'] ?? null,
                'rate_class' => $rate['rateClass'] ?? null,
                'rate_type' => $rate['rateType'] ?? null,
                'rate_key' => isset($rate['rateKey']) && is_string($rate['rateKey']) ? $rate['rateKey'] : null,
                'net' => DecimalString::from($rate['net'] ?? null),
                'currency' => $currency,
                'allotment' => isset($rate['allotment']) ? (int) $rate['allotment'] : null,
                'packaging' => array_key_exists('packaging', $rate) ? (bool) $rate['packaging'] : null,
                'payment_type' => $rate['paymentType'] ?? null,
                'rate_comments' => is_string($rate['rateComments'] ?? null) ? $rate['rateComments'] : null,
                'promotions' => isset($rate['promotions']) ? \App\Support\JsonDecimals::encode($rate['promotions']) : null,
                'raw_data' => \App\Support\JsonDecimals::encode($room),
            ]);

            if ($model->payment_type && $booking->payment_type === null) {
                $booking->forceFill(['payment_type' => $model->payment_type])->save();
            }

            foreach ($room['paxes'] ?? [] as $pax) {
                if (! is_array($pax)) {
                    continue;
                }

                BookingGuest::query()->create([
                    'booking_room_id' => $model->id,
                    'room_id' => (int) ($pax['roomId'] ?? $room['id'] ?? 1),
                    'type' => (string) ($pax['type'] ?? 'AD'),
                    'name' => (string) ($pax['name'] ?? ''),
                    'surname' => (string) ($pax['surname'] ?? ''),
                    'age' => isset($pax['age']) ? (int) $pax['age'] : null,
                ]);
            }

            foreach ((is_array($presented) ? $presented['taxes'] : []) as $tax) {
                BookingTax::query()->create([
                    'booking_room_id' => $model->id,
                    'sub_type' => $tax['sub_type'],
                    'amount' => $tax['amount'],
                    'currency' => $tax['currency'] ?? $currency,
                    'included' => (bool) $tax['included'],
                    'client_amount' => $tax['client_amount'],
                    'client_currency' => $tax['client_currency'],
                ]);
            }

            foreach ((is_array($presented) ? $presented['cancellation_policies'] : []) as $policy) {
                BookingCancellationPolicy::query()->create([
                    'booking_room_id' => $model->id,
                    'amount' => $policy['amount'],
                    'currency' => $currency,
                    'raw_from_value' => $policy['from'],
                ]);
            }
        }
    }
}
