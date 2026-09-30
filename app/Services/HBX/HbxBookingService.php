<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Exceptions\HBX\HbxAmbiguousResultException;
use App\Exceptions\HBX\HbxApiException;
use App\Exceptions\HBX\HbxValidationException;
use App\Models\HotelBooking;
use App\Models\RateSelection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class HbxBookingService
{
    public function __construct(
        private readonly HbxClient $client,
        private readonly ClientReferenceGenerator $references,
        private readonly BookingResponsePersister $persister,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $guests
     */
    public function book(RateSelection $selection, string $holderName, string $holderSurname, array $guests, string $remark, string $submissionToken): HotelBooking
    {
        $selection->loadMissing('search');

        $existing = HotelBooking::query()->where('submission_token', $submissionToken)->first();

        if ($existing) {
            return $existing;
        }

        $this->assertSelectionBookableNow($selection);

        [$booking, $created] = $this->persistPendingBooking(
            $selection,
            $holderName,
            $holderSurname,
            $guests,
            $remark,
            $submissionToken,
        );

        if (! $created) {
            return $booking;
        }

        try {
            $selection->refresh();
            $this->assertSelectionBookableNow($selection);
        } catch (HbxValidationException $exception) {
            if ($exception->supplierCode === 'RATE_SELECTION_EXPIRED') {
                $booking->forceFill(['status' => HotelBooking::STATUS_FAILED])->save();
            } else {
                $booking->delete();
            }

            throw $exception;
        }

        $payload = [
            'holder' => [
                'name' => $holderName,
                'surname' => $holderSurname,
            ],
            'rooms' => [[
                'rateKey' => $selection->rate_key,
                'paxes' => array_map(function (array $guest): array {
                    $pax = [
                        'roomId' => (int) $guest['room_id'],
                        'type' => $guest['type'],
                        'name' => $guest['name'],
                        'surname' => $guest['surname'],
                    ];

                    if (($guest['type'] ?? null) === 'CH' && isset($guest['age'])) {
                        $pax['age'] = (int) $guest['age'];
                    }

                    return $pax;
                }, $guests),
            ]],
            'clientReference' => $booking->client_reference,
            'remark' => $remark,
        ];

        try {
            $result = $this->client->post(
                (string) config('hbx.endpoints.bookings'),
                $payload,
                'booking',
                [
                    'hotel_booking_id' => $booking->id,
                    'hotel_search_id' => $selection->hotel_search_id,
                    'client_reference' => $booking->client_reference,
                ]
            );
        } catch (HbxAmbiguousResultException $exception) {
            $booking->forceFill(['status' => HotelBooking::STATUS_AMBIGUOUS])->save();

            throw $exception;
        } catch (HbxApiException $exception) {
            $booking->forceFill(['status' => HotelBooking::STATUS_FAILED])->save();

            throw $exception;
        }

        if ($booking->status !== HotelBooking::STATUS_PENDING) {
            return $booking;
        }

        $this->persister->applyConfirmation($booking, $result->data, $result->rawBody);

        return $booking->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $guests
     * @return array{0: HotelBooking, 1: bool}
     */
    private function persistPendingBooking(
        RateSelection $selection,
        string $holderName,
        string $holderSurname,
        array $guests,
        string $remark,
        string $submissionToken,
    ): array {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($selection, $holderName, $holderSurname, $guests, $remark, $submissionToken): array {
                    $existing = HotelBooking::query()
                        ->where('submission_token', $submissionToken)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return [$existing, false];
                    }

                    $booking = HotelBooking::query()->create([
                        'hotel_search_id' => $selection->hotel_search_id,
                        'rate_selection_id' => $selection->id,
                        'client_reference' => $this->allocateReference(),
                        'submission_token' => $submissionToken,
                        'status' => HotelBooking::STATUS_PENDING,
                        'holder_name' => $holderName,
                        'holder_surname' => $holderSurname,
                        'hotel_code' => $selection->hotel_code,
                        'hotel_name' => $selection->hotel_name,
                        'category_name' => $selection->category_name,
                        'destination_name' => $selection->destination_name,
                        'zone_name' => $selection->zone_name,
                        'check_in' => $selection->search?->check_in,
                        'check_out' => $selection->search?->check_out,
                        'currency' => $selection->currency,
                        'total_net' => $selection->net,
                        'payment_type' => $selection->payment_type,
                        'payment_data_required' => $selection->payment_data_required,
                        'remark' => $remark,
                    ]);

                    $this->persister->storeIntent($booking, $guests, $selection->rate_key);

                    return [$booking, true];
                });
            } catch (UniqueConstraintViolationException $exception) {
                $existing = HotelBooking::query()->where('submission_token', $submissionToken)->first();

                if ($existing) {
                    return [$existing, false];
                }

                if (! str_contains($exception->getMessage(), 'client_reference') || $attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw new HbxValidationException(
            'A unique client reference could not be allocated.',
            'INVALID_DATA',
            null,
            [],
            'booking'
        );
    }

    private function assertSelectionBookableNow(RateSelection $selection): void
    {
        if (! $selection->bookingAllowed()) {
            throw new HbxValidationException(
                'This rate is RECHECK. Run CheckRate and use the returned rateKey before booking.',
                'CHECKRATE_REQUIRED',
                null,
                [],
                'booking'
            );
        }

        if (! $selection->isWithinValidity()) {
            throw new HbxValidationException(
                'This rate selection has expired. Run a fresh hotel search or CheckRate before booking.',
                'RATE_SELECTION_EXPIRED',
                null,
                [],
                'booking'
            );
        }
    }

    private function allocateReference(): string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $reference = $this->references->validate($this->references->generate());

            if (! HotelBooking::query()->where('client_reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new HbxValidationException(
            'A unique client reference could not be allocated.',
            'INVALID_DATA',
            null,
            [],
            'booking'
        );
    }
}
