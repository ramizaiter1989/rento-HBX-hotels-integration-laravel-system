<x-layouts.app :title="'Snapshot '.$hotel->hbx_hotel_code">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Raw content snapshot</h1>
            <p class="mt-1 text-sm text-slate-600">
                Hotel {{ $hotel->hbx_hotel_code }}
                · {{ $language }}
                · {{ $snapshot->content_origin ?: 'unknown origin' }}
                · {{ number_format(strlen($pretty)) }} bytes
            </p>
            <p class="mt-1 text-sm text-slate-600">This payload is loaded only on this page. It is not part of the hotel Content page.</p>
        </div>
        <a href="{{ route('content.hotels.show', ['hotelCode' => $hotel->hbx_hotel_code, 'language' => $language]) }}" class="btn btn-secondary">Back to hotel</a>
    </div>
    <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap rounded-md bg-slate-950 p-4 text-xs leading-5 text-slate-100">{{ $pretty }}</pre>
</x-layouts.app>
