# HBX lab commands and routes

Local URL: `http://127.0.0.1:8000`

Supplier call means the action contacts HBX. Commercial write means the action can create, change, or cancel a supplier booking. Opening a GET page in this list does not do that by itself.

## Artisan commands

| Command | Purpose | Supplier call | Commercial write |
| --- | --- | --- | --- |
| `php artisan hbx:status` | Read the HBX status endpoint | Yes | No |
| `php artisan hbx:content:hotel {code} --language=ENG` | Fetch one Hotel Details response | Yes | No |
| `php artisan hbx:content:hotel {code} --language=ENG --import` | Fetch Hotel Details and store it | Yes | No |
| `php artisan hbx:content:hotels --from=1 --to=10 --language=ENG` | Read one Hotels list page without importing | Yes | No |
| `php artisan hbx:content:sync-hotels --language=ENG --batch=50` | Full resumable content sync | Yes | No |
| `php artisan hbx:content:sync-hotels --language=ENG --batch=50 --limit=1000` | Full sync with a stored target of 1000 hotels | Yes | No |
| `php artisan hbx:content:sync-hotels --language=ENG --last-update=YYYY-MM-DD` | Differential sync | Yes | No |
| `php artisan hbx:content:sync-hotels --resume` | Resume the latest full run | Yes | No |
| `php artisan hbx:content:sync-hotels --resume --last-update=YYYY-MM-DD` | Resume the matching differential run | Yes | No |
| `php artisan hbx:content:sync-status` | Print stored sync runs | No | No |
| `php artisan hbx:content:sync-stop` | Ask the running sync to stop after the current page | No | No |
| `php artisan hbx:content:sync-abandon {run}` | Close a failed or stopped run so resume will not select it | No | No |
| `php artisan hbx:content:sync-reference --language=ENG` | Import all reference catalogs | Yes | No |
| `php artisan hbx:content:sync-reference --language=ENG --type=facilities` | Import one catalog | Yes | No |
| `php artisan hbx:availability:cleanup` | Delete expired availability snapshots past retention | No | No |
| `php artisan test` | Automated suite. Must not call HBX | No | No |

`--limit` on a new run is the stored hotel target. Resume uses the remaining amount (`requested_limit - fetched`) and ignores a new `--limit`. A completed target cannot be resumed. A command without `--limit` is an explicit unlimited sync. `hbx:content:sync-status` and the sync dashboard show Requested target, Fetched total, and Remaining.

A hotel sync run accepts one worker. A second resume of that same run is refused until the first worker finishes or its claim expires. Two different runs, including a full run and a differential run, stay independent.

`hbx:content:sync-abandon {run}` sets a failed or stopped run to `abandoned`. It keeps the checkpoint and statistics and does not delete imported Content. Resume never continues an abandoned run. A completed run cannot be abandoned. A running run cannot be abandoned either: stop it with `hbx:content:sync-stop`, wait until its status is `stopped`, then abandon that id. The active worker does not watch for `abandoned`, and its checkpoint would replace that status.

`--type=zones` reads destinations, because zones are nested in that payload. `--type=room-types` and `--type=room-characteristics` read the rooms catalog. There is no separate characteristic-description endpoint.

Reference types: `facility-groups`, `facilities`, `rooms`, `categories`, `category-groups`, `chains`, `accommodations`, `boards`, `segments`, `image-types`, `countries`, `destinations`.

The hotel sync batch ceiling is 100. The reference batch ceiling is 1000 because those rows are short descriptions, not hotel trees.

## Web routes

| Route | Purpose | Supplier call | Commercial write |
| --- | --- | --- | --- |
| `GET /` | Dashboard | No | No |
| `GET /hotels/search` | Search form | No | No |
| `POST /hotels/search` | Availability search. Reuses a fresh snapshot or calls HBX | Yes, unless the fingerprint is fresh | No |
| `GET /hotels/search/{search}` | Stored results merged with local content | No | No |
| `GET /hotels/search/{search}/hotels/{hotelCode}/rooms` | Rooms and rates from the stored snapshot | No | No |
| `POST /hotels/search/{search}/check-rate` | CheckRate | Yes | No |
| `POST /hotels/search/{search}/select` | Select a bookable rate | No | No |
| `GET /bookings/create` | Guest details | No | No |
| `POST /bookings` | Create the supplier booking | Yes | Yes |
| `GET /bookings` | Local bookings | No | No |
| `GET /bookings/{booking}` | Local booking, reconciliation, and actions | No | No |
| `POST /bookings/{booking}/refresh` | Booking Detail | Yes | No |
| `GET /bookings/hbx` | Supplier booking list | Yes | No |
| `POST /bookings/{booking}/cancel-simulation` | Cancellation simulation | Yes | No |
| `POST /bookings/{booking}/cancel` | Actual cancellation | Yes | Yes |
| `POST /bookings/{booking}/modify-simulation` | Modification simulation | Yes | No |
| `POST /bookings/{booking}/modify` | Disabled. Does not call HBX | No | No |
| `GET /content` | Redirect to the content hotel list | No | No |
| `GET /content/hotels` | Stored hotel list | No | No |
| `GET /content/hotels/{hotelCode}` | Stored hotel detail | No | No |
| `GET /developer/content/hotels/{hotelCode}/snapshot` | Raw content snapshot | No | No |
| `GET /developer/hbx/searches/{search}/raw` | Raw availability snapshot | No | No |
| `GET /developer/hbx/logs` | Stored API logs | No | No |
| `GET /developer/hbx/sync` | Sync dashboard | No | No |
| `GET /developer/hbx/test-matrix` | Implementation matrix | No | No |
| `GET /developer/reference` | Developer notes | No | No |
| `GET /hbx/status` | Status page | No | No |
| `POST /hbx/status` | Live status call | Yes | No |

Changing an availability results page does not call HBX. An expired snapshot blocks a new booking until a fresh search or CheckRate.
