<x-layouts.app title="Raw availability response">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Raw HBX availability response</h1>
            <p class="mt-1 text-sm text-slate-600">
                Search {{ $search->id }}
                · {{ $search->destination_code ?: 'Hotel filter' }}
                · {{ number_format(strlen($raw)) }} bytes
            </p>
            <p class="mt-1 text-sm text-slate-600">This payload is loaded only on this page. It is not part of the hotel results HTML.</p>
        </div>
        <a href="{{ route('hotels.results', $search) }}" class="btn btn-secondary">Back to hotels</a>
    </div>
    <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap rounded-md bg-slate-950 p-4 text-xs leading-5 text-slate-100">{{ $raw }}</pre>
</x-layouts.app>
