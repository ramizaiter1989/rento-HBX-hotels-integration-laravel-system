# Rento HBX Hotels Integration Laravel Lab

Project: Rento HBX Hotels Integration Laravel Lab

Local URL: http://127.0.0.1:8000

Database: `rento_hbx_lab`

This branch is a working copy of the local lab, including the current `.env`. Hotel rows live in that MySQL database. They are not exported in git. Migrations recreate the schema.

## Current implemented areas

- HBX Booking API
- Availability
- CheckRate
- booking persistence
- booking management
- cancellation simulation
- actual cancellation
- Content API
- single Hotel Details import
- bulk Hotels synchronization
- Details-over-list precedence
- Content Lab
- resumable C6 synchronization
- checkpointing
- sync stop/status commands
- transient Content GET retries

## Current Content Lab

- http://127.0.0.1:8000/content
- http://127.0.0.1:8000/content/hotels
- http://127.0.0.1:8000/content/hotels/712

## Current content state

- 1000-hotel acceptance completed successfully
- Failed: 0
- Conflicts: 0
- Details retained: 1
- hotel 712 is stored from Hotel Details
- other synchronized hotels normally use `content_origin=list`

## C6

- `content_sync_runs`
- `--resume`
- `hbx:content:sync-status`
- `hbx:content:sync-stop`
- checkpoint after each completed page

## HBX TEST account

- max quota 50 requests
- quota reset interval 86400 seconds
- 8 requests per 4 seconds

## Current 10,000-hotel acceptance run

- Run 2
- status failed because supplier returned HTTP 403
- fetched 0
- next from 1

Resume command:

```text
php artisan hbx:content:sync-hotels --resume
```

## Next Content development area

HBX reference/master data including:

- facilities
- facility groups
- room types
- room characteristics
- categories
- category groups
- chains
- accommodation types
- boards
- segments
- image types
- countries
- destinations
- zones

## Checkout

```text
git clone -b moamen-hbx-lab https://github.com/ramizaiter1989/rento-HBX-hotels-integration-laravel-system.git
cd rento-HBX-hotels-integration-laravel-system
composer install
npm install
npm run build
php artisan migrate
php artisan serve
```

`.env` is already in this branch. Use the MySQL database it names. Do not replace it with `.env.example`.
