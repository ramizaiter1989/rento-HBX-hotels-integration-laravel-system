<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HbxApiLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HbxLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = HbxApiLog::query()
            ->select([
                'id',
                'hotel_booking_id',
                'hotel_search_id',
                'operation',
                'request_method',
                'endpoint',
                'http_status',
                'successful',
                'supplier_process_time',
                'local_duration_ms',
                'error_code',
                'error_message',
                'hbx_reference',
                'client_reference',
                'created_at',
            ])
            ->selectRaw('request_payload')
            ->selectRaw('CASE WHEN response_payload IS NULL OR LENGTH(response_payload) > 32768 THEN NULL ELSE response_payload END AS response_payload')
            ->selectRaw('CASE WHEN response_payload IS NULL THEN 0 ELSE LENGTH(response_payload) END AS response_bytes')
            ->when($request->filled('operation'), fn ($query) => $query->where('operation', $request->string('operation')))
            ->when($request->filled('endpoint'), fn ($query) => $query->where('endpoint', 'like', '%'.$request->string('endpoint').'%'))
            ->when($request->filled('http_status'), fn ($query) => $query->where('http_status', $request->integer('http_status')))
            ->when($request->filled('hbx_reference'), fn ($query) => $query->where('hbx_reference', $request->string('hbx_reference')))
            ->when($request->filled('client_reference'), fn ($query) => $query->where('client_reference', $request->string('client_reference')))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('created_at', $request->date('date')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('developer.logs', [
            'logs' => $logs,
            'filters' => $request->only(['operation', 'endpoint', 'http_status', 'hbx_reference', 'client_reference', 'date']),
        ]);
    }
}
