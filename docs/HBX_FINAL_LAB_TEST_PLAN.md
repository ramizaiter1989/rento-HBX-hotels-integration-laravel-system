# HBX final lab test plan

Run these checks yourself. Do not treat `php artisan test` as live HBX verification.

The current HBX TEST account, as observed in the HBX dashboard, allows 50 requests per quota window, resets every 86400 seconds, and allows 8 requests per 4 seconds. Do not try to bypass those limits.

Local URL: `http://127.0.0.1:8000`

## A. No supplier request

These pages read MySQL or static lab status.

| Test | How | Expected | Quota | Supplier booking state |
| --- | --- | --- | --- | --- |
| Content list | `GET /content/hotels` | Stored hotels, codes, and category labels when reference data exists | No | Unchanged |
| Hotel 712 | `GET /content/hotels/712` | Origin details. Facility codes remain visible. Labels appear beside them after reference sync | No | Unchanged |
| Hotel 11 | `GET /content/hotels/11` | Origin list, if that hotel was imported | No | Unchanged |
| Raw snapshot | `GET /developer/content/hotels/712/snapshot` | Pretty JSON for that hotel only | No | Unchanged |
| Sync dashboard | `GET /developer/hbx/sync` | Runs, counts, and copyable commands. No new HBX log row | No | Unchanged |
| Test matrix | `GET /developer/hbx/test-matrix` | Actual modification is NOT IMPLEMENTED. Content sync can show LIVE VERIFIED | No | Unchanged |
| Local bookings | `GET /bookings` | Stored bookings only | No | Unchanged |
| Stored results | `GET /hotels/search/{id}` | Snapshot age, LIVE or CACHE, content enrichment. Paging does not call HBX | No | Unchanged |
| Sync status | `php artisan hbx:content:sync-status` | Latest stored run | No | Unchanged |

Cleanup: none.

## B. Low-cost HBX TEST calls

| Test | How | Expected | Quota | Supplier booking state |
| --- | --- | --- | --- | --- |
| Status | `php artisan hbx:status` or POST `/hbx/status` | HTTP success and a compact status | Yes, 1 | Unchanged |
| One availability | Open `/hotels/search` and submit the form once | Results page says LIVE. A stored snapshot is created | Yes, 1 | Unchanged |
| Cache reuse | Submit the same search again within 60 seconds | Results page says CACHE. No second availability log for that fingerprint | No, while fresh | Unchanged |
| One Hotel Details | `php artisan hbx:content:hotel 712 --language=ENG --import` | Hotel 712 stays origin details | Yes, 1 | Unchanged |
| One Hotels page | `php artisan hbx:content:hotels --from=1 --to=10 --language=ENG` | Prints a page and does not import | Yes, 1 | Unchanged |
| One reference catalog | `php artisan hbx:content:sync-reference --language=ENG --type=facilities` | Imported or unchanged counts. Content Lab shows labels | Yes, one or more pages | Unchanged |
| All reference catalogs | `php artisan hbx:content:sync-reference --language=ENG` | One run per command, GET only | Yes, several pages | Unchanged |
| CheckRate | On a fresh room, press Check Rate | Availability net and CheckRate net are both shown. A difference is explicit | Yes, 1 | Unchanged |

Cleanup: none. These calls do not create a booking.

## C. Commercial test actions

Use HBX TEST hotel data and a client reference of 1 to 20 characters. Do not use a production credential.

| Test | How | Expected | Quota | Supplier booking state |
| --- | --- | --- | --- | --- |
| Booking | Complete guest details and POST `/bookings` | Local row, HBX reference, client reference, supplier status | Yes | Creates a test booking |
| Booking Detail | POST refresh on the booking | Local and supplier status compared | Yes | Unchanged |
| Booking list | `GET /bookings/hbx` | Supplier list includes the reference | Yes | Unchanged |
| Cancellation simulation | POST cancel simulation | Penalty or terms. Booking remains active | Yes | Unchanged |
| Actual cancellation | POST cancel only after reading the simulation | Supplier cancellation, then refresh Booking Detail and local status | Yes | Cancels the test booking |
| Modification simulation | POST modify simulation | Simulated terms. Holder is not changed | Yes | Unchanged |
| Actual modification | POST `/bookings/{booking}/modify` | Stays disabled. Error `MODIFICATION_UNVERIFIED`. No HBX PUT | No | Unchanged |

Cleanup for a test booking: run cancellation simulation, confirm the terms, then actual cancellation, then refresh Booking Detail.

Do not cancel by refreshing the page. Cancellation is the explicit POST only.

## 10,000-hotel acceptance

Do not start this until you intend to spend quota across more than one window.

```text
php artisan hbx:content:sync-hotels --language=ENG --batch=50 --limit=10000
```

With batch 50, 10,000 hotels need about 200 Hotels-list requests. The current TEST allowance is 50 requests per quota window, so this cannot finish in one window.

The earlier Run 2 failed with HTTP 403, fetched 0, and left next from at 1 because the quota was exhausted.

Ways to accept it later:

- Resume across quota resets with `php artisan hbx:content:sync-hotels --resume`.
- Ask HBX for a larger TEST quota.
- Consider a larger batch only after measuring memory and confirming the supplier page size. Do not raise the batch to 1000 just because the supplier range allows it. The lab ceiling remains 100 until that measurement exists.

A failed page does not move the checkpoint. A completed run cannot be resumed.
