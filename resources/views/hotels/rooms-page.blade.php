<x-layouts.app title="Hotel rooms">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $hotel['name'] ?: 'Hotel rooms' }}</h1>
            <p class="mt-1 text-sm text-slate-600">Code {{ $hotel['code'] }}. Rooms are loaded from the stored availability snapshot.</p>
        </div>
        <a href="{{ route('hotels.results', $search) }}" class="btn btn-secondary">Back to hotels</a>
    </div>
    <section class="card p-5">
        @include('hotels.rooms')
    </section>
</x-layouts.app>
