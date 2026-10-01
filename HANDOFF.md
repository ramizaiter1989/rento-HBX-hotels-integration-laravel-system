# Rento HBX Hotels Integration Laravel Lab

This is a local HBX / Hotelbeds laboratory for Rento Dubai. It is the reference Rami can run, test against HBX TEST, and then transform into the production system. It is not the production application.

Branch: `moamen-hbx-lab`

Do not commit `.env`. Do not put secrets in this file.

## Stack

- PHP 8.3+
- Laravel 13
- MySQL 8
- Blade, Alpine, Tailwind, Vite
- PHPUnit

Local URL: http://127.0.0.1:8000

Database: `rento_hbx_lab`

HBX TEST base URL: `https://api.test.hotelbeds.com`

Hotel rows live in MySQL. They are not in git. Migrations recreate the schema.

## Install

```text
git clone -b moamen-hbx-lab https://github.com/ramizaiter1989/rento-HBX-hotels-integration-laravel-system.git
cd rento-HBX-hotels-integration-laravel-system
composer install
npm install
npm run build
copy .env.example .env
php artisan key:generate
```

Set `DB_*`, `HBX_API_KEY`, and `HBX_SECRET` in the local `.env`. Then:

```text
php artisan migrate
php artisan serve
```

## HBX TEST quota

Observed on the TEST account:

- 50 requests per quota window
- reset interval 86400 seconds
- 8 requests per 4 seconds

Do not bypass these limits.

## Current live acceptance

- Hotel 712 was imported from Hotel Details. Origin stays `details`. Hash `b44f3024b023132755c3a877082a612cfed1402ae31bde2673515c27e03f28d1`.
- A 1000-hotel list sync completed: Fetched 1000, Imported 14, Updated 0, Unchanged 986, Failed 0, Details retained 1, Conflicts 0.
- The 10,000-hotel run was not completed. Run 2 returned HTTP 403, fetched 0, next from 1, because the TEST quota was exhausted.
- Reference sync, the content/availability merge, and a fresh booking lifecycle still need a manual HBX pass. Automated tests do not count as live verification.
- Actual booking modification is not implemented. It waits for a confirmed supplier contract.

## Commands

```text
php artisan hbx:status
php artisan hbx:content:sync-hotels --language=ENG --batch=50
php artisan hbx:content:sync-hotels --language=ENG --last-update=YYYY-MM-DD
php artisan hbx:content:sync-hotels --resume
php artisan hbx:content:sync-status
php artisan hbx:content:sync-stop
php artisan hbx:content:sync-abandon {run}
php artisan hbx:content:sync-reference --language=ENG
php artisan hbx:availability:cleanup
```

The 10,000-hotel command is documented in the test plan. Do not run it until quota across several windows is available:

```text
php artisan hbx:content:sync-hotels --language=ENG --batch=50 --limit=10000
```

`--limit` is stored on the new run. Resume fetches only the remaining hotels. Run 2 has no stored target because it predates that column. It is abandoned locally, so resume will not continue it. Start a new limited run when the ceiling matters.

Differential sync is not scheduled unless `HBX_CONTENT_DIFFERENTIAL_SCHEDULE` is set.

## Lab URLs

- http://127.0.0.1:8000/hotels/search
- http://127.0.0.1:8000/content/hotels
- http://127.0.0.1:8000/content/hotels/712
- http://127.0.0.1:8000/bookings
- http://127.0.0.1:8000/developer/hbx/sync
- http://127.0.0.1:8000/developer/hbx/test-matrix
- http://127.0.0.1:8000/developer/content/hotels/712/snapshot

Content Lab, the sync dashboard, and the test matrix do not call HBX when opened.

## Documents

- [docs/HBX_FINAL_LAB_TEST_PLAN.md](docs/HBX_FINAL_LAB_TEST_PLAN.md)
- [docs/RENTO_DUBAI_PRODUCTION_INTEGRATION_GUIDE.md](docs/RENTO_DUBAI_PRODUCTION_INTEGRATION_GUIDE.md)
- [docs/HBX_LAB_ARCHITECTURE.md](docs/HBX_LAB_ARCHITECTURE.md)
- [docs/HBX_LAB_COMMANDS_AND_ROUTES.md](docs/HBX_LAB_COMMANDS_AND_ROUTES.md)

## Next production step

Use the production integration guide. Carry the client, importer, availability cache, CheckRate, booking, and checkpoint patterns into Rento Dubai. Keep developer pages and this unauthenticated lab UI behind.
