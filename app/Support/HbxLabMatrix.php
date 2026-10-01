<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Local implementation status for the HBX lab. This class does not call HBX.
 * LIVE VERIFIED is used only for supplier calls already observed in this lab.
 */
final class HbxLabMatrix
{
    /**
     * @return list<array{name: string, implementation: string, automated: string, live: string, entry: string, live_hbx: string, commercial_write: string, notes: string}>
     */
    public static function items(): array
    {
        return [
            self::item('AUTH', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'php artisan hbx:status', 'Yes', 'No', 'Signature is SHA256(API key + secret + unix seconds). Secrets stay in the environment.'),
            self::item('CONTENT', 'IMPLEMENTED', 'AUTOMATED TESTED', 'LIVE VERIFIED', 'php artisan hbx:content:sync-hotels', 'Yes, for sync', 'No', 'Hotel 712 Details import and a 1000-hotel list sync were accepted on HBX TEST. Details content is kept when a later list page differs.'),
            self::item('REFERENCE DATA', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'php artisan hbx:content:sync-reference --language=ENG', 'Yes, for sync', 'No', 'Zones are stored from destinations. Room type and characteristic descriptions are not separate endpoints.'),
            self::item('AVAILABILITY', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'GET /hotels/search', 'Yes, on search submit', 'No', 'Fresh snapshots are reused for 60 seconds. Expired snapshots can be read and cannot be booked.'),
            self::item('CONTENT + AVAILABILITY MERGE', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'GET /hotels/search/{search}', 'No, while reading a stored snapshot', 'No', 'Merge key is hotelCode. Room enrichment uses the exact roomCode. A missing content row does not drop the supplier room.'),
            self::item('CHECKRATE', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /hotels/search/{search}/check-rate', 'Yes', 'No', 'Availability net is kept beside the CheckRate net. A changed price is shown before booking.'),
            self::item('BOOKING', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings', 'Yes', 'Yes', 'Uses the existing booking service and the 20-character client reference. A timed-out POST is not retried.'),
            self::item('BOOKING DETAIL', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings/{booking}/refresh', 'Yes', 'No', 'Refresh reads supplier detail and compares it with the local row.'),
            self::item('BOOKING LIST', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'GET /bookings/hbx', 'Yes', 'No', 'Supplier list query. Opening the local bookings page does not call HBX.'),
            self::item('RECONCILIATION', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'GET /bookings/{booking}', 'Only when refresh is posted', 'No', 'Local status, supplier status, differences, and the last check are shown on the booking page.'),
            self::item('CANCELLATION SIMULATION', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings/{booking}/cancel-simulation', 'Yes', 'No', 'Simulation does not cancel the supplier booking.'),
            self::item('ACTUAL CANCELLATION', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings/{booking}/cancel', 'Yes', 'Yes', 'Cancellation runs only from an explicit POST after simulation. Loading the page does not cancel.'),
            self::item('MODIFICATION SIMULATION', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings/{booking}/modify-simulation', 'Yes', 'No', 'Simulation uses the supplier SIMULATION mode.'),
            self::item('ACTUAL MODIFICATION', 'NOT IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'POST /bookings/{booking}/modify', 'No', 'No', 'Execution stays disabled. The supplier contract for a safe live modification is not clear enough to guess. The action throws MODIFICATION_UNVERIFIED and does not call HBX.'),
            self::item('SYNC / RESUME', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'php artisan hbx:content:sync-hotels --resume', 'Yes, when a run is resumed', 'No', 'Resume continues only until the stored requested_limit. An abandoned run is not resumed. A running run cannot be abandoned until sync-stop has made it stopped. The 10,000-hotel run has not completed.'),
            self::item('DIFFERENTIAL SYNC', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'php artisan hbx:content:sync-hotels --last-update=YYYY-MM-DD', 'Yes', 'No', 'Differential runs stay separate from full runs. The schedule is off unless HBX_CONTENT_DIFFERENTIAL_SCHEDULE is set.'),
            self::item('REFERENCE SYNC', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'php artisan hbx:content:sync-reference --language=ENG', 'Yes', 'No', 'GET catalogs only. Replays upsert by supplier code. Reference sync is not a hotel checkpoint resume.'),
            self::item('ERROR HANDLING', 'IMPLEMENTED', 'AUTOMATED TESTED', 'PENDING LIVE VERIFICATION', 'GET /developer/hbx/test-matrix', 'No', 'No', 'Content and reference GETs retry connection failures, HTTP 429, and HTTP 5xx. Booking, cancellation, and modification are never blindly retried.'),
        ];
    }

    /**
     * @return array{name: string, implementation: string, automated: string, live: string, entry: string, live_hbx: string, commercial_write: string, notes: string}
     */
    private static function item(
        string $name,
        string $implementation,
        string $automated,
        string $live,
        string $entry,
        string $liveHbx,
        string $commercialWrite,
        string $notes,
    ): array {
        return [
            'name' => $name,
            'implementation' => $implementation,
            'automated' => $automated,
            'live' => $live,
            'entry' => $entry,
            'live_hbx' => $liveHbx,
            'commercial_write' => $commercialWrite,
            'notes' => $notes,
        ];
    }
}
