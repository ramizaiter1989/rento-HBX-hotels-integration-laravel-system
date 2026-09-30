<x-layouts.app title="HBX booking list">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">HBX booking list</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">The TEST environment can return bookings that are not ours. Unknown rows are not imported as local Rento bookings.</p>
    </div>

    <form method="GET" action="{{ route('bookings.hbx') }}" class="card mb-6 grid gap-4 p-5 md:grid-cols-3">
        <input type="hidden" name="fetch" value="1">
        <label class="text-sm">Start<input class="field mt-1" type="date" name="start" value="{{ $filters['start'] }}" required></label>
        <label class="text-sm">End<input class="field mt-1" type="date" name="end" value="{{ $filters['end'] }}" required></label>
        <label class="text-sm">Filter type
            <select class="field mt-1" name="filter_type">
                @foreach (['CREATION', 'CHECKIN'] as $type)
                    <option value="{{ $type }}" @selected($filters['filter_type'] === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">Status
            <select class="field mt-1" name="status">
                @foreach (['ALL', 'CONFIRMED', 'CANCELLED'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">From<input class="field mt-1" type="number" min="1" name="from" value="{{ $filters['from'] }}"></label>
        <label class="text-sm">To<input class="field mt-1" type="number" min="1" name="to" value="{{ $filters['to'] }}"></label>
        <div class="md:col-span-3">
            <button class="btn btn-primary">Fetch HBX list</button>
        </div>
    </form>

    @if ($result)
        <p class="mb-3 text-sm text-slate-600">Supplier total {{ $result->data['bookings']['total'] ?? count($rows) }} · processTime {{ $result->processTime ?: '—' }} · {{ $result->durationMs }} ms</p>
        <section class="card overflow-hidden">
            @if ($rows === [])
                <p class="p-8 text-sm text-slate-600">HBX returned no bookings for this filter.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Ownership</th>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Client reference</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Holder</th>
                                <th class="px-4 py-3">Hotel</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php($supplier = $row['supplier'])
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-3">
                                        @if ($row['known'])
                                            <a class="font-medium underline" href="{{ route('bookings.show', $row['local']) }}">Local booking</a>
                                        @else
                                            <x-badge tone="amber">Unknown supplier booking</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $supplier['reference'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $supplier['clientReference'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $supplier['status'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $supplier['holder']['name'] ?? '' }} {{ $supplier['holder']['surname'] ?? '' }}</td>
                                    <td class="px-4 py-3">{{ $supplier['hotel']['name'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        <x-json-inspector title="Raw HBX response" :payload="$result->rawBody" />
    @else
        <section class="card p-8 text-sm text-slate-600">Choose a date range and fetch the supplier list. Nothing is imported automatically.</section>
    @endif
</x-layouts.app>
