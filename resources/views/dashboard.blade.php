<x-layouts.app title="Dashboard">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <p class="mt-1 text-sm text-slate-600">Local view of the HBX TEST booking laboratory. Credentials stay on the server.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <section class="card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">HBX environment</p>
            <p class="mt-2 text-lg font-semibold">{{ config('hbx.environment') }}</p>
            <p class="mt-1 break-all text-sm text-slate-600">{{ config('hbx.base_url') }}</p>
        </section>
        <section class="card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">HBX connection</p>
            @if ($lastStatus)
                <p class="mt-2"><x-badge :tone="$lastStatus->successful ? 'green' : 'rose'">{{ $lastStatus->successful ? 'Last call succeeded' : 'Last call failed' }}</x-badge></p>
                <p class="mt-2 text-sm text-slate-600">{{ $lastStatus->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }} · HTTP {{ $lastStatus->http_status ?? '—' }}</p>
            @else
                <p class="mt-2 text-sm text-slate-600">No status call recorded yet.</p>
                <a href="{{ route('hbx.status') }}" class="mt-3 inline-block text-sm font-medium text-slate-900 underline">Test HBX connection</a>
            @endif
        </section>
        <section class="card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">MySQL</p>
            <p class="mt-2"><x-badge :tone="$mysql['ok'] ? 'green' : 'rose'">{{ $mysql['ok'] ? 'Connected' : 'Not connected' }}</x-badge></p>
            <p class="mt-2 text-sm text-slate-600">{{ $mysql['message'] }}</p>
        </section>
        <section class="card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Local bookings</p>
            <p class="mt-2 text-lg font-semibold">{{ $bookingCount }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $confirmedCount }} confirmed · {{ $cancelledCount }} cancelled</p>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
            <h2 class="text-base font-semibold">Latest hotel searches</h2>
            @if ($searches->isEmpty())
                <p class="mt-3 text-sm text-slate-600">No searches yet.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($searches as $search)
                        <li class="flex items-center justify-between py-2">
                            <a href="{{ route('hotels.results', $search) }}" class="font-medium hover:underline">
                                {{ $search->destination_code ?: 'Hotel codes' }}
                                · {{ $search->check_in->toDateString() }} → {{ $search->check_out->toDateString() }}
                            </a>
                            <span class="text-slate-500">{{ $search->hotels_returned ?? 0 }} hotels</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="card p-5">
            <h2 class="text-base font-semibold">Latest supplier errors</h2>
            @if ($errors->isEmpty())
                <p class="mt-3 text-sm text-slate-600">No failed HBX calls recorded.</p>
            @else
                <ul class="mt-3 space-y-3 text-sm">
                    @foreach ($errors as $error)
                        <li>
                            <p class="font-medium">{{ $error->operation }} · {{ $error->error_code ?? 'error' }}</p>
                            <p class="text-slate-600">{{ $error->error_message }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="card mt-6 p-5">
        <h2 class="text-base font-semibold">Latest booking operations</h2>
        @if ($operations->isEmpty())
            <p class="mt-3 text-sm text-slate-600">No HBX operations yet.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">When</th>
                            <th class="py-2 pr-4">Operation</th>
                            <th class="py-2 pr-4">HTTP</th>
                            <th class="py-2 pr-4">Duration</th>
                            <th class="py-2">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operations as $operation)
                            <tr class="border-t border-slate-100">
                                <td class="py-2 pr-4">{{ $operation->created_at->format('Y-m-d H:i:s') }}</td>
                                <td class="py-2 pr-4">{{ $operation->operation }}</td>
                                <td class="py-2 pr-4">{{ $operation->http_status ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $operation->local_duration_ms }} ms</td>
                                <td class="py-2">{{ $operation->client_reference ?: $operation->hbx_reference ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
