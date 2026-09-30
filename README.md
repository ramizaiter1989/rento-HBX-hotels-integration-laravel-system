# Rento HBX Lab

Local Laravel laboratory for the HBX / Hotelbeds Hotel Booking API. It is a supplier-integration reference for Rento, not the production booking platform.

The lab exercises the manually verified TEST operations through a browser: status, availability, CheckRate, booking, booking detail, booking list, cancellation simulation, booking-change simulation, and actual TEST cancellation.

## Architecture

Laravel serves both the UI and the supplier integration.

- Blade pages, Alpine.js for confirmation and submit locks, Tailwind CSS, Vite
- One HTTP client, `App\Services\HBX\HbxClient`
- One signature implementation, `App\Services\HBX\HbxSignatureGenerator`
- Domain services for status, availability, CheckRate, booking, and booking management
- MySQL for the application data
- Automated tests fake HBX with Laravel's HTTP client. They do not call the network.

HBX credentials never reach JavaScript. The browser only receives supplier booking data that the server already stored.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, and `intl`
- Composer 2
- Node.js and npm
- MySQL 8.4 on `127.0.0.1:3306`

Verified on this machine during setup: PHP 8.3.32, Composer 2.10.2, Node 24.19.0, Laravel 13.10.1 / framework 13.33.0.

## MySQL

The application user is `rento_hbx`, limited to `rento_hbx_lab`. Do not point Laravel at `root`.

```text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rento_hbx_lab
DB_USERNAME=rento_hbx
DB_PASSWORD=
```

Put the existing local password in `.env` only. `.env` is gitignored. `.env.example` keeps the password empty.

## Installation

```bash
cd "HBX Group/rento-hbx-lab"
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_PASSWORD`, `HBX_API_KEY`, and `HBX_SECRET` in `.env`.

```bash
php artisan migrate
npm install
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000`.

## HBX configuration

`config/hbx.php` reads:

```text
HBX_ENABLED=true
HBX_ENVIRONMENT=test
HBX_BASE_URL=https://api.test.hotelbeds.com
HBX_API_KEY=
HBX_SECRET=
HBX_CONNECT_TIMEOUT=10
HBX_TIMEOUT=30
HBX_BOOKING_TIMEOUT=45
HBX_MTLS_ENABLED=false
HBX_CLIENT_CERT_PATH=
HBX_CLIENT_KEY_PATH=
```

mTLS is configuration only. Do not invent certificates. When `HBX_MTLS_ENABLED=true`, the HTTP client will attach the configured certificate and key paths. Those files must not be committed.

## X-Signature

Every request builds a fresh signature:

```text
timestamp = Unix time in seconds
X-Signature = lowercase hex SHA-256(API_KEY + SECRET + timestamp)
```

Concatenation has no separators. The secret is not an HTTP header. Headers are `Api-key`, `X-Signature`, and `Accept: application/json`. POST and PUT also send `Content-Type: application/json`.

## Booking workflow

1. Search with one filter: destination or hotel codes. The lab builds a search fingerprint, reuses a fresh snapshot when one exists, and otherwise calls HBX and stores the raw body. The stored snapshot is paged at 20 hotels. Rooms and rates load only for the hotel you open. The full supplier JSON stays on its own raw-response page.
2. Read `rateType`.
3. `BOOKABLE` can go to booking. CheckRate is optional.
4. `RECHECK` must call CheckRate. Booking stays disabled until that succeeds.
5. If CheckRate returns a new `rateKey`, discard the previous key.
6. The lab stores `clientReference` as `RENTO-{YYMMDD}-{RANDOM7}` (exactly 20 characters) before the booking call. HBX rejects longer values.
7. The confirmed booking response becomes the reservation record. Availability figures are only a snapshot.

`rateKey` is stored in a `LONGTEXT` column and is never parsed.

## Availability lifecycle

```
Availability request
    → fingerprint
    → fresh hotel_searches lookup
    → cache hit, or HBX then a new snapshot
    → short cache TTL
    → debug retention
    → hbx:availability:cleanup
```

These are Rento policies. They are not supplier guarantees.

| Policy | Setting | Default | Meaning |
| --- | --- | --- | --- |
| Cache TTL | `HBX_AVAILABILITY_CACHE_TTL_SECONDS` | 60 seconds | How long an identical normalized search reuses one snapshot. A cache hit updates `last_accessed_at` and does not move `expires_at`. |
| Debug retention | `HBX_AVAILABILITY_RETENTION_DAYS` | 7 days | How long an unbooked snapshot, and an availability API log, remain for inspection after `created_at`. |
| Checked-rate freshness | `HBX_CHECKED_RATE_TTL_SECONDS` | 300 seconds | How long a rate stays bookable after a successful CheckRate. A BOOKABLE rate taken directly from availability stays bookable only until that snapshot's `expires_at`. |

An expired snapshot can still be paged and opened on the raw developer page. Selecting or checking a rate from it is rejected with `AVAILABILITY_SNAPSHOT_EXPIRED` and does not call HBX. Booking re-checks `rate_selections.valid_until` immediately before `POST /bookings`. A null or elapsed `valid_until` returns `RATE_SELECTION_EXPIRED` and does not call HBX. Existing confirmed bookings are left as they are.

Rows created before this lifecycle have a null fingerprint and null `expires_at`. They are historical records, not reusable cache entries.

The authoritative availability body stays on `hotel_searches.response_payload`. Availability rows in `hbx_api_logs` store a short summary (byte size, hotel total, process metadata) and do not store the hotel tree or rate keys. CheckRate, booking, cancellation, and simulation logs still store their response bodies.

Cleanup:

```bash
php artisan hbx:availability:cleanup --dry-run
php artisan hbx:availability:cleanup
```

`--dry-run` prints counts and deletes nothing. A normal run deletes unbooked `hotel_searches` older than the retention cutoff. Rate selections cascade with those searches. A search linked to any booking is kept. The same run deletes `hbx_api_logs` whose operation is `availability` and whose `created_at` is older than the cutoff. It does not delete booking, CheckRate, cancellation, or simulation logs.

The command is scheduled daily with `withoutOverlapping()`. Laravel does not run that schedule by itself. A deployed environment needs a system scheduler calling `php artisan schedule:run` every minute, or a process running `php artisan schedule:work`. Local development does not need a permanent worker for this milestone. The queue connection stays `sync`.

## Cancellation and modification

Cancellation simulation uses `DELETE` with `cancellationFlag=SIMULATION`. A simulated `CANCELLED` status is stored on `booking_simulations` and does not change the booking.

Actual cancellation uses `cancellationFlag=CANCELLATION`, requires an explicit confirmation, and is visually separate from simulation.

Booking-change simulation first loads the current Booking Detail, copies that supplier booking, changes only the requested holder name and surname, and sends `PUT` with `"mode": "SIMULATION"`. It does not update the stored holder, status, confirmation snapshot, or current HBX state.

Actual booking-change execution is disabled. The execution mode has not been verified.

## Retry policy

- GET status, booking detail, and booking list: one extra attempt, only when the connection fails before an HTTP response.
- POST availability and CheckRate: no retry. The data is a snapshot.
- POST booking, DELETE cancellation, and PUT booking change: never retry.
- A booking timeout is stored as `ambiguous`. Reconcile with the client reference, Booking Detail, and Booking List.

## Money and taxes

Amounts are decimal strings and MySQL `DECIMAL` columns. PHP floats are rejected for money. Excluded taxes, including city tax, are not added into `totalNet`. Cancellation timestamps keep the supplier offset, for example `+02:00`.

## Security

- `.env`, certificates, and private keys are gitignored.
- API logs store sanitized bodies, never auth headers.
- The UI does not collect card numbers, PAN, or CVV.
- `paymentDataRequired=false` only means extra payment fields were not required.

## Tests

```bash
php artisan test
```

Automated tests use an in-memory SQLite database so they cannot wipe `rento_hbx_lab` and cannot call HBX. The running application uses MySQL.

Manual TEST calls:

```bash
php artisan hbx:status
php artisan hbx:content:hotel 712 --language=ENG
php artisan hbx:content:hotel 712 --language=ENG --import
php artisan hbx:content:hotels --from=1 --to=10 --language=ENG
php artisan hbx:content:hotels --from=1 --to=10 --language=ENG --last-update=2026-09-28
php artisan hbx:content:sync-hotels --language=ENG --batch=50 --limit=100
php artisan hbx:content:sync-hotels --language=ENG --batch=50 --limit=10000
php artisan hbx:content:sync-hotels --resume
php artisan hbx:content:sync-status
```

`hbx:content:hotel` is a developer command for one Hotels Content API hotel. Without `--import` it only prints the response. With `--import` it stores that hotel in the local content tables. `hbx:content:hotels` reads one page of at most 10 hotels and does not import them. `hbx:content:sync-hotels` imports one page at a time, 50 hotels by default and never more than 100 per request. It saves a checkpoint after each completed page. `--resume` continues a stopped, failed, or interrupted run from that position. `--limit=10000` is the controlled acceptance run and was not executed from this lab setup. None of these commands run during search, and none download images. ENG is the canonical structural language. There is no artisan command that creates a booking. Booking, cancellation, and modification stay behind the browser confirmation flow.

## Known limitations

- Content sync imports hotels through `hbx:content:sync-hotels`. The default page is 50 hotels, with a ceiling of 100, because each response is fully decoded. Search still does not read those tables. Hotel cards use a labeled placeholder instead of supplier photos. The full catalog has not been synced.
- Actual booking modification cannot be executed.
- Production credentials, production mTLS, and production booking validation are out of scope.
- HBX TEST booking list can contain bookings that are not local. They are marked unknown and are not imported.
- Allotment is displayed as a snapshot, not as stock.
- Prices and cancellation amounts can differ between availability, CheckRate, and the confirmed booking. Each stored stage stands on its own.
- Availability responses are still decoded once inside the HTTP client so the existing booking path stays unchanged. The duplicate copy in `hbx_api_logs` is no longer stored. The raw snapshot remains on `hotel_searches`.

## Pending HBX work

- Actual booking-change execution mode
- Content API reference catalogs
- Production credentials
- Production mTLS
- Production booking validation
