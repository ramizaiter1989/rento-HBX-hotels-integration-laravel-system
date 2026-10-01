<?php

use App\Support\PositiveConfigInt;

return [

    /*
    |--------------------------------------------------------------------------
    | HBX / Hotelbeds Booking API
    |--------------------------------------------------------------------------
    | Credentials stay in the environment. They are never sent to the browser.
    | mTLS paths are configuration only until certificates are installed.
    */

    'enabled' => (bool) env('HBX_ENABLED', false),

    'environment' => env('HBX_ENVIRONMENT', 'test'),

    'base_url' => rtrim((string) env('HBX_BASE_URL', 'https://api.test.hotelbeds.com'), '/'),

    'api_key' => env('HBX_API_KEY'),

    'secret' => env('HBX_SECRET'),

    'connect_timeout' => (int) env('HBX_CONNECT_TIMEOUT', 10),

    'timeout' => (int) env('HBX_TIMEOUT', 30),

    'booking_timeout' => (int) env('HBX_BOOKING_TIMEOUT', 45),

    'mtls' => [
        'enabled' => (bool) env('HBX_MTLS_ENABLED', false),
        'cert_path' => env('HBX_CLIENT_CERT_PATH'),
        'key_path' => env('HBX_CLIENT_KEY_PATH'),
    ],

    /*
    | Retry policy
    | - GET (status, booking detail, booking list): one extra attempt, and only
    |   when the connection fails before an HTTP response.
    | - POST availability and CheckRate: no retry. Results are snapshots.
    | - POST booking, DELETE cancellation, PUT booking change: never retry.
    */

    'endpoints' => [
        'status' => '/hotel-api/1.0/status',
        'availability' => '/hotel-api/1.0/hotels',
        'checkrates' => '/hotel-api/1.0/checkrates',
        'bookings' => '/hotel-api/1.0/bookings',
        'content_hotel_detail' => '/hotel-content-api/1.0/hotels/{hotelCode}/details',
        'content_hotels' => '/hotel-content-api/1.0/hotels',
        /*
        | Hotelbeds Content API 1.0 reference catalogs.
        | Zones are nested inside destinations. Room characteristic labels are
        | not a separate catalog; codes are taken from types/rooms.
        */
        'reference' => [
            'facility-groups' => '/hotel-content-api/1.0/types/facilitygroups',
            'facilities' => '/hotel-content-api/1.0/types/facilities',
            'rooms' => '/hotel-content-api/1.0/types/rooms',
            'categories' => '/hotel-content-api/1.0/types/categories',
            'category-groups' => '/hotel-content-api/1.0/types/groupcategories',
            'chains' => '/hotel-content-api/1.0/types/chains',
            'accommodations' => '/hotel-content-api/1.0/types/accommodations',
            'boards' => '/hotel-content-api/1.0/types/boards',
            'segments' => '/hotel-content-api/1.0/types/segments',
            'image-types' => '/hotel-content-api/1.0/types/imagetypes',
            'countries' => '/hotel-content-api/1.0/locations/countries',
            'destinations' => '/hotel-content-api/1.0/locations/destinations',
        ],
    ],

    /*
    | Hotels per Content sync request. The supplier allows up to 1000, but each
    | page is fully decoded in PHP. Ten hotels were about 600-760 KB raw JSON,
    | so the default stays at 50 and the sync refuses more than 100.
    */
    'content' => [
        'sync_batch_size' => PositiveConfigInt::from(env('HBX_CONTENT_SYNC_BATCH', 50), 50),
        'get_retry_attempts' => max(1, (int) env('HBX_CONTENT_GET_RETRY_ATTEMPTS', 3)),
        'get_retry_base_ms' => max(0, (int) env('HBX_CONTENT_GET_RETRY_BASE_MS', 250)),
        'get_retry_cap_ms' => max(0, (int) env('HBX_CONTENT_GET_RETRY_CAP_MS', 2000)),
        'photo_thumbnail_base' => 'https://photos.hotelbeds.com/giata/small',
        'reference_batch_size' => PositiveConfigInt::from(env('HBX_CONTENT_REFERENCE_BATCH', 100), 100),
        /*
        | Empty disables the differential content schedule. This is a Rento
        | operations choice. The HBX TEST quota may not support a daily run.
        | Example: 0 3 * * *
        */
        'differential_schedule' => env('HBX_CONTENT_DIFFERENTIAL_SCHEDULE'),
    ],

    /*
    | Rento availability policies. These are local cache, debug-retention,
    | and rate-freshness windows. They are not HBX contractual validity times.
    | Zero and negative environment values fall back to the defaults.
    */
    'availability' => [
        'cache_ttl_seconds' => PositiveConfigInt::from(env('HBX_AVAILABILITY_CACHE_TTL_SECONDS', 60), 60),
        'retention_days' => PositiveConfigInt::from(env('HBX_AVAILABILITY_RETENTION_DAYS', 7), 7),
        'checked_rate_ttl_seconds' => PositiveConfigInt::from(env('HBX_CHECKED_RATE_TTL_SECONDS', 300), 300),
    ],

];
