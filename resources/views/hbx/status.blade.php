<x-layouts.app title="HBX status">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">HBX status</h1>
        <p class="mt-1 text-sm text-slate-600">GET /hotel-api/1.0/status. Credentials are not shown.</p>
    </div>

    <section class="card p-5">
        <dl class="grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-slate-500">Environment</dt><dd class="font-medium">{{ config('hbx.environment') }}</dd></div>
            <div><dt class="text-slate-500">Base URL</dt><dd>{{ config('hbx.base_url') }}</dd></div>
            <div><dt class="text-slate-500">Credentials</dt><dd>{{ $configured ? 'Configured locally' : 'Missing. Set HBX_API_KEY and HBX_SECRET in .env' }}</dd></div>
            <div><dt class="text-slate-500">mTLS</dt><dd>{{ config('hbx.mtls.enabled') ? 'Enabled' : 'Disabled' }}</dd></div>
            <div><dt class="text-slate-500">HTTP status</dt><dd>{{ $latest->http_status ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Connection</dt><dd>{{ $latest ? ($latest->successful ? 'Last call succeeded' : 'Last call failed') : 'Not tested' }}</dd></div>
            <div><dt class="text-slate-500">HBX processTime</dt><dd>{{ $latest->supplier_process_time ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Local duration</dt><dd>{{ $latest ? $latest->local_duration_ms.' ms' : '—' }}</dd></div>
            <div><dt class="text-slate-500">Last successful connection</dt><dd>{{ optional($latest)->successful ? $latest->created_at->format('Y-m-d H:i:s') : '—' }}</dd></div>
        </dl>
        @if ($latest && $latest->response_payload)
            @php($payload = \App\Support\JsonDecimals::decode($latest->response_payload))
            <p class="mt-4 text-sm">HBX status: <span class="font-semibold">{{ $payload['status'] ?? '—' }}</span></p>
            <p class="text-sm text-slate-600">HBX timestamp: {{ $payload['auditData']['timestamp'] ?? '—' }}</p>
        @endif
        <form method="POST" action="{{ route('hbx.status.test') }}" class="mt-6" x-data="{ sending: false }" @submit="sending = true">
            @csrf
            <button class="btn btn-primary" :disabled="sending">Test HBX Connection</button>
        </form>
    </section>
</x-layouts.app>
