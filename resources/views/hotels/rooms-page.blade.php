<x-layouts.app title="Hotel rooms">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $hotel['name'] ?: 'Hotel rooms' }}</h1>
            <p class="mt-1 text-sm text-slate-600">Supplier hotel code {{ $hotel['code'] }}. Rooms are loaded from the stored availability snapshot.</p>
            <p class="mt-1 text-xs text-slate-500">Search → Results → Hotel → Room/rate → CheckRate → Guest details → Booking → Confirmation</p>
        </div>
        <a href="{{ route('hotels.results', $search) }}" class="btn btn-secondary">Back to hotels</a>
    </div>
    @if ($audit['hotel'] ?? null)
        <section class="card mb-6 p-5 text-sm">
            <h2 class="text-base font-semibold">{{ $audit['hotel']['name'] ?: 'Stored content' }}</h2>
            <p class="mt-2 text-slate-700">
                {{ $audit['hotel']['category_code'] }} {{ $audit['hotel']['category_label'] }}
                · {{ $audit['hotel']['destination_code'] }} {{ $audit['hotel']['destination_label'] }}
                · {{ $audit['hotel']['city'] }}
                · Content {{ $audit['hotel']['origin'] ?? 'stored' }}
            </p>
            @if ($audit['hotel']['description'])
                <p class="mt-2 text-slate-700">{{ $audit['hotel']['description'] }}</p>
            @endif
        </section>
    @else
        <p class="mb-6 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">Content enrichment unavailable. Live rooms below are unchanged.</p>
    @endif
    <section class="card mb-6 p-5 text-sm">
        <h2 class="text-base font-semibold">Room matching</h2>
        <p class="mt-2">Live rooms: {{ implode(', ', $audit['live'] ?? []) ?: '—' }}</p>
        <p class="mt-1">Matched: {{ implode(', ', $audit['matched'] ?? []) ?: '—' }}</p>
        <p class="mt-1">Unmatched live room codes: {{ implode(', ', $audit['unmatched_live'] ?? []) ?: '—' }}</p>
        <p class="mt-1">Orphan content rooms: {{ implode(', ', $audit['orphan_content'] ?? []) ?: '—' }}</p>
        <p class="mt-1">Images referencing unknown room codes: {{ implode(', ', $audit['images_unknown_room'] ?? []) ?: '—' }}</p>
    </section>
    <section class="card p-5">
        @include('hotels.rooms')
    </section>
</x-layouts.app>
