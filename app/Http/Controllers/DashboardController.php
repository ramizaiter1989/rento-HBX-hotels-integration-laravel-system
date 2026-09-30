<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HbxApiLog;
use App\Models\HotelBooking;
use App\Models\HotelSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $mysql = ['ok' => false, 'message' => 'Not checked'];
        $connected = false;

        try {
            DB::connection()->getPdo();
            $connected = true;
            $database = config('database.connections.'.config('database.default').'.database');
            $mysql = ['ok' => true, 'message' => 'Connected to '.$database];
        } catch (\Throwable) {
            $mysql = ['ok' => false, 'message' => 'MySQL connection failed. Set DB_PASSWORD for user rento_hbx in the local .env file. Do not use root.'];
        }

        return view('dashboard', [
            'mysql' => $mysql,
            'lastStatus' => $connected ? HbxApiLog::query()->where('operation', 'status')->latest('id')->first() : null,
            'bookingCount' => $connected ? HotelBooking::query()->count() : 0,
            'confirmedCount' => $connected ? HotelBooking::query()->where('status', 'CONFIRMED')->count() : 0,
            'cancelledCount' => $connected ? HotelBooking::query()->where('status', 'CANCELLED')->count() : 0,
            'searches' => $connected ? HotelSearch::query()->select(['id', 'destination_code', 'check_in', 'check_out', 'hotels_returned', 'created_at'])->latest('id')->limit(5)->get() : collect(),
            'operations' => $connected ? HbxApiLog::query()->select(['id', 'operation', 'http_status', 'local_duration_ms', 'hbx_reference', 'client_reference', 'created_at'])->latest('id')->limit(8)->get() : collect(),
            'errors' => $connected ? HbxApiLog::query()->select(['id', 'operation', 'error_code', 'error_message', 'created_at'])->where('successful', false)->latest('id')->limit(5)->get() : collect(),
        ]);
    }
}
