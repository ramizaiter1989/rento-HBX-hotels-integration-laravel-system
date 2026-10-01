<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelSnapshot;
use App\Models\ContentSyncFailure;
use App\Models\ContentSyncRun;
use App\Services\HBX\ContentReferenceStore;
use Illuminate\Contracts\View\View;

class ContentSyncDashboardController extends Controller
{
    public function __invoke(ContentReferenceStore $references): View
    {
        return view('developer.sync', [
            'runs' => ContentSyncRun::query()->latest('id')->limit(20)->get(),
            'failures' => ContentSyncFailure::query()->latest('id')->limit(25)->get(),
            'counts' => [
                'hotels' => ContentHotel::query()->count(),
                'list' => ContentHotelSnapshot::query()->where('language', 'ENG')->where('content_origin', 'list')->count(),
                'details' => ContentHotelSnapshot::query()->where('language', 'ENG')->where('content_origin', 'details')->count(),
                'rooms' => ContentHotelRoom::query()->count(),
                'images' => ContentHotelImage::query()->count(),
                'facilities' => ContentHotelFacility::query()->count(),
            ],
            'references' => $references->counts(),
            'commands' => [
                'Full sync' => 'php artisan hbx:content:sync-hotels --language=ENG --batch=50',
                'Differential sync' => 'php artisan hbx:content:sync-hotels --language=ENG --batch=50 --last-update=YYYY-MM-DD',
                'Resume' => 'php artisan hbx:content:sync-hotels --resume',
                'Status' => 'php artisan hbx:content:sync-status',
                'Stop' => 'php artisan hbx:content:sync-stop',
                'Abandon' => 'php artisan hbx:content:sync-abandon {run}',
                'Reference sync' => 'php artisan hbx:content:sync-reference --language=ENG',
            ],
        ]);
    }
}
