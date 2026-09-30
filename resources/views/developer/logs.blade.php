<x-layouts.app title="HBX API logs">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">HBX API logs</h1>
        <p class="mt-1 text-sm text-slate-600">Auth headers, the API key, the secret, and signatures are not stored.</p>
    </div>

    <form method="GET" class="card mb-6 grid gap-4 p-5 md:grid-cols-3">
        <label class="text-sm">Operation<input class="field mt-1" name="operation" value="{{ $filters['operation'] ?? '' }}"></label>
        <label class="text-sm">Endpoint<input class="field mt-1" name="endpoint" value="{{ $filters['endpoint'] ?? '' }}"></label>
        <label class="text-sm">Status code<input class="field mt-1" name="http_status" value="{{ $filters['http_status'] ?? '' }}"></label>
        <label class="text-sm">Booking reference<input class="field mt-1" name="hbx_reference" value="{{ $filters['hbx_reference'] ?? '' }}"></label>
        <label class="text-sm">Client reference<input class="field mt-1" name="client_reference" value="{{ $filters['client_reference'] ?? '' }}"></label>
        <label class="text-sm">Date<input class="field mt-1" type="date" name="date" value="{{ $filters['date'] ?? '' }}"></label>
        <div class="md:col-span-3"><button class="btn btn-primary">Filter</button></div>
    </form>

    <section class="card overflow-hidden">
        @if ($logs->isEmpty())
            <p class="p-8 text-sm text-slate-600">No HBX calls match these filters.</p>
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($logs as $log)
                    <article class="p-4 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge :tone="$log->successful ? 'green' : 'rose'">{{ $log->successful ? 'OK' : 'Failed' }}</x-badge>
                            <span class="font-semibold">{{ $log->operation }}</span>
                            <span>{{ $log->request_method }}</span>
                            <span>HTTP {{ $log->http_status ?? '—' }}</span>
                            <span class="text-slate-500">{{ $log->created_at->format('Y-m-d H:i:s') }}</span>
                        </div>
                        <p class="mt-2 break-all text-slate-600">{{ $log->endpoint }}</p>
                        <p class="text-slate-600">processTime {{ $log->supplier_process_time ?: '—' }} · {{ $log->local_duration_ms }} ms · {{ $log->client_reference ?: $log->hbx_reference ?: 'no reference' }}</p>
                        @if ($log->error_message)<p class="mt-1 text-rose-800">{{ $log->error_code }} {{ $log->error_message }}</p>@endif
                        <x-json-inspector title="Request JSON" :payload="$log->request_payload" />
                        @if (($log->response_bytes ?? 0) > 32768)
                            <p class="mt-3 text-sm text-slate-600">Response is {{ number_format((int) $log->response_bytes) }} bytes and is not embedded on this page.</p>
                        @else
                            <x-json-inspector title="Response JSON" :payload="$log->response_payload" />
                        @endif
                    </article>
                @endforeach
            </div>
            <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
        @endif
    </section>
</x-layouts.app>
