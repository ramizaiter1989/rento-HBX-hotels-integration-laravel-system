<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HbxApiLog;
use App\Services\HBX\HbxStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class HbxStatusController extends Controller
{
    public function show(): View
    {
        return view('hbx.status', [
            'latest' => HbxApiLog::query()->where('operation', 'status')->latest('id')->first(),
            'configured' => filled(config('hbx.api_key')) && filled(config('hbx.secret')),
        ]);
    }

    public function test(HbxStatusService $status): RedirectResponse
    {
        $result = $status->check();

        return back()->with('status', 'HBX status: '.($result->data['status'] ?? 'unknown'));
    }
}
