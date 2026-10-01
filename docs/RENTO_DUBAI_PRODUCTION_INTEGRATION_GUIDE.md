# Rento Dubai production integration guide

This repository is a supplier integration reference. Rami should transfer its contracts into Rento Dubai. Do not deploy this lab as the production site.

The lab UI, developer pages, and single-operator routes are here so the HBX behavior can be seen. Rento owns the customer experience around them.

## Ownership

HBX owns:

- the supplier hotel catalog source
- supplier room inventory
- live availability
- supplier prices
- supplier rate keys
- supplier cancellation rules
- supplier booking status

Rento owns:

- customer accounts
- UX
- the normalized content database
- local hotel enrichment
- the availability cache
- markup and pricing rules
- payments
- local booking and order records
- analytics
- customer support
- notifications
- recommendations
- Rento AI and other business logic

## Request flow

```text
HBX Content
    → Rento synchronization
    → local Content DB

User search
    → HBX Availability, or a fresh local snapshot
    → merge with local Content by hotelCode
    → Rento results

Selected rate
    → CheckRate
    → show the current price and cancellation terms

Guest and payment
    → Rento validates payment and business state

Booking
    → HBX
    → local Rento booking record

After booking
    → Booking Detail and reconciliation

Cancellation
    → simulation
    → customer confirmation
    → actual supplier cancellation
    → reconciliation

Modification
    → simulation
    → explicit confirmation
    → execution only after the supplier contract is verified
    → reconciliation
```

Room matching uses the exact supplier `roomCode`. Do not fuzzy-match on room names. If a live room has no content row, keep the room and mark enrichment unavailable.

## Background flow

Initial load: a full resumable content sync, one page at a time, checkpointed after each completed page.

Ongoing load: a differential sync using `lastUpdateTime`. Differential runs stay separate from full runs and must not reset a full-sync checkpoint.

Reference data: a periodic `hbx:content:sync-reference` run. Replay is safe because rows upsert by supplier code.

Hotel Details: enrich selected hotels only. Do not fetch Details for all 305,000+ hotels as a default. A practical policy is a Rento business decision, for example commercially active hotels, hotels that were searched or viewed, hotels that were booked, promoted destinations, or hotels chosen by an admin. That policy is not an HBX requirement.

`HBX_CONTENT_DIFFERENTIAL_SCHEDULE` is empty by default. Set a cron expression only after the production quota is known. A daily run is not assumed here. The observed TEST account allows 50 requests per 86400 seconds, which is not enough for a large daily catalog walk.

## What can be reused

These lab patterns are the parts worth carrying into Rento:

- signature generation: SHA256 of the API key, secret, and unix timestamp in seconds
- the HBX HTTP client and its read/write retry split
- the availability fingerprint and snapshot
- the content client and importer
- CheckRate, including the stored availability net beside the checked net
- the booking service and the 20-character client reference
- booking detail, list, cancellation simulation, and actual cancellation
- the sync checkpoint: advance only after a page succeeds
- normalized hotel, room, and reference codes

## What should stay in the lab

- developer raw JSON pages
- the test matrix and sync dashboard
- lab navigation and the amber TEST banner
- manual debug screens
- unauthenticated access

This lab has no customer login. Any local booking URL can be opened by whoever can reach the lab. Production must authorize each booking to the Rento customer or staff role that owns it. Search snapshot IDs need the same rule.

## Actual modification

Leave execution disabled until HBX confirms the live booking-change contract. Simulation exists. The execute action throws `MODIFICATION_UNVERIFIED` and does not send a PUT. Do not invent the missing request shape in production.

## Retry boundary

Safe reads may use a bounded retry: Content GET, reference GET, and the existing idempotent reads. The lab retries content and reference GETs on connection failure, HTTP 429, and HTTP 5xx.

Never blindly retry:

- Booking
- cancellation
- modification

Use the local request state, the client reference, and Booking Detail to recover an uncertain write.

## Production checklist

Review these before any live traffic. They are Rento operational responsibilities unless an item is already fixed by the supplier contract in this project. TLS verification stays on. Do not disable certificate checks.

- production HBX credentials in a secret manager
- production base URL
- HBX production certification or approval, if HBX requires it for the account
- production quota and rate limits
- network allowlisting, if HBX requires it
- queue workers, if Rento moves sync off the web process
- scheduler, with the differential cron left empty until the quota is known
- monitoring and alerting
- database backup
- log retention, with secrets and guest data excluded
- privacy of guest names and contact details
- payment integration owned by Rento
- markup, currency, and taxation
- customer cancellation UX that shows the simulation before the real cancel
- duplicate-booking protection through the client reference
- reconciliation jobs
- operational support and deployment topology

The lab does not process card numbers or CVV. Do not add them in order to satisfy HBX unless a later, documented supplier contract requires a payment field.
