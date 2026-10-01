# HBX lab architecture

This repository is a supplier integration laboratory. It shows how Rento can synchronize HBX content, merge it with live availability, and complete a booking lifecycle. It is not the Rento Dubai production application.

## Content synchronization

```text
HBX Content API
    ↓
Content sync (one page at a time)
    ↓
Normalizer / importer
    ↓
MySQL normalized tables
    ↓
Content Lab and availability enrichment
```

```mermaid
flowchart TD
    HBX["HBX Content API"] --> Sync["Content sync"]
    Sync --> Importer["Normalizer / importer"]
    Importer --> MySQL["MySQL"]
    MySQL --> Lab["Content Lab and search enrichment"]
```

Hotel list pages and Hotel Details use the same importer. A page is checkpointed only after every hotel on that page has been processed. A failed HTTP page does not advance `next_from`. Replaying a page is idempotent.

`--limit` is stored on the run as `requested_limit`. `fetched` is the cumulative total. Resume continues only until `requested_limit - fetched` reaches zero. That ceiling is separate from the page checkpoint. Omitting `--limit` stores no target, and that run stays unlimited. A run that already reached its target cannot be resumed. Runs created before this column have a null target, so they stay unlimited.

Reference catalogs use GET only. They upsert by supplier code. They do not use the hotel page checkpoint. Running the reference command again updates changed descriptions and leaves unchanged rows in place.

## Search

```text
Customer
    ↓
Rento search
    ↓
Availability cache?
    ├─ fresh fingerprint → reuse snapshot
    └─ stale or missing → HBX Availability
                          ↓
                    stored snapshot
                          ↓
              local Content + Availability
                          ↓
                       results
```

```mermaid
flowchart TD
    Customer["Customer"] --> Rento["Rento search"]
    Rento --> Cache{"Fresh availability snapshot?"}
    Cache -->|yes| Reuse["Reuse snapshot"]
    Cache -->|no| HBX["HBX Availability"]
    HBX --> Snapshot["Stored snapshot"]
    Reuse --> Merge["Local Content + Availability"]
    Snapshot --> Merge
    Merge --> Results["Results"]
```

Content browsing reads MySQL only. Commercial availability stays on the existing live or cached HBX Availability path. The merge key is `hotelCode`. Room enrichment uses the exact `roomCode`.

A fresh snapshot is reused for the configured TTL, 60 seconds by default. An expired snapshot can still be inspected. It cannot be used to select or book a rate.

## Booking

```text
Selected rate
    ↓
CheckRate when required
    ↓
Guest details
    ↓
HBX Booking
    ↓
Local booking
    ↓
Booking Detail / reconciliation
```

```mermaid
flowchart TD
    Rate["Selected rate"] --> Check["CheckRate"]
    Check --> Guest["Guest details"]
    Guest --> Book["HBX Booking"]
    Book --> Local["Local booking"]
    Local --> Reconcile["Booking Detail / reconciliation"]
```

The availability net is kept beside the CheckRate net. A difference is shown before booking. Booking, cancellation, and modification are not blindly retried. A timed-out booking is reconciled with the client reference, Booking List, and Booking Detail.

## Cancellation

```text
Cancellation simulation
    ↓
show penalty and terms
    ↓
explicit confirmation
    ↓
actual cancellation
    ↓
Booking Detail / reconciliation
```

```mermaid
flowchart TD
    Sim["Cancellation simulation"] --> Terms["Show terms"]
    Terms --> Confirm["Explicit confirmation"]
    Confirm --> Cancel["Actual cancellation"]
    Cancel --> Detail["Booking Detail / reconciliation"]
```

Loading a booking page does not cancel it. Actual cancellation is a POST.

## Modification

Simulation is implemented. Actual execution is not. `executeModification()` throws `MODIFICATION_UNVERIFIED` and does not call HBX. The current supplier contract is not clear enough to guess a safe live modification.

## Content source precedence

```text
Hotel Details
    ↓
highest priority

Hotels list
    ↓
baseline

A later list import must not replace a Details snapshot.
```

```mermaid
flowchart TD
    Details["Hotel Details"] --> Stored["Stored hotel"]
    List["Hotels list"] --> Guard{"Details snapshot already stored?"}
    Guard -->|yes| Keep["Keep Details"]
    Guard -->|no| Store["Store list content"]
```

When a list page would replace Details, the importer returns `hotel-details-retained`. The sync counts that as Details retained, not as a Conflict.
