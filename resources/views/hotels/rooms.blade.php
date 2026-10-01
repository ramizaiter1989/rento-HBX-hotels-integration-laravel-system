@php
    $audit = $audit ?? [
        'content_available' => false,
        'rooms' => [],
        'unmatched_live' => [],
        'matched' => [],
        'orphan_content' => [],
        'images_unknown_room' => [],
        'live' => [],
    ];
@endphp
@if (! $search->isFresh())
    <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">Historical availability snapshot. Run a new search before selecting a rate. Snapshot age {{ $search->created_at?->diffForHumans() }}.</p>
@else
    <p class="mb-4 text-sm text-slate-600">Snapshot age {{ $search->created_at?->diffForHumans() }}. Expires {{ $search->expires_at?->format('Y-m-d H:i:s') }}.</p>
@endif
<p class="mb-4 text-xs text-slate-500">The supplier rateKey is kept for CheckRate and booking. It is not shown on this page. RECHECK rates require CheckRate before booking.</p>
@forelse ($hotel['rooms'] as $room)
    @php $matchedRoom = $audit['rooms'][(string) $room['code']] ?? null; @endphp
    <section class="mb-4 rounded-md border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">{{ $room['name'] }} <span class="text-sm font-normal text-slate-500">{{ $room['code'] }}</span></h3>
        @if ($matchedRoom)
            <p class="mt-1 text-sm text-slate-700">Content room {{ $matchedRoom['code'] }}@if ($matchedRoom['description']) · {{ $matchedRoom['description'] }}@endif</p>
            @if ($matchedRoom['commercial_description'])
                <p class="mt-1 text-sm text-slate-600">Commercial description {{ $matchedRoom['commercial_description'] }}</p>
            @endif
            @if ($matchedRoom['images'])
                <div class="mt-2 flex gap-2">
                    @foreach ($matchedRoom['images'] as $image)
                        <img src="{{ $image }}" alt="" width="96" height="64" loading="lazy" class="h-16 w-24 rounded bg-slate-100 object-cover">
                    @endforeach
                </div>
            @endif
        @else
            <p class="mt-1 text-sm text-slate-500">Content enrichment unavailable for room {{ $room['code'] }}. This supplier room stays bookable.</p>
        @endif
        <div class="mt-3 space-y-3">
            @foreach ($room['rates'] as $rate)
                @php
                    $selection = $selections->first(fn ($item) => $item->original_rate_key === $rate['rate_key'] || $item->rate_key === $rate['rate_key']);
                    $currentType = $selection?->rate_type ?? $rate['rate_type'];
                    $bookable = $currentType === 'BOOKABLE' && ($rate['rate_type'] !== 'RECHECK' || $selection?->checkrate_completed_at);
                @endphp
                <div class="rounded-md border border-slate-200 p-4">
                    <div class="flex flex-wrap gap-2">
                        <x-badge :tone="$rate['rate_type'] === 'BOOKABLE' ? 'green' : 'amber'">{{ $rate['rate_type'] ?: 'RATE' }}</x-badge>
                        @if ($rate['rate_class'])
                            <x-badge :tone="$rate['rate_class'] === 'NRF' ? 'rose' : 'slate'">{{ $rate['rate_class'] }}</x-badge>
                        @endif
                        @if ($rate['payment_type'])
                            <x-badge tone="sky">{{ $rate['payment_type'] }}</x-badge>
                        @endif
                    </div>
                    <div class="mt-3 grid gap-2 text-sm md:grid-cols-3">
                        <p><span class="text-slate-500">Net</span> <x-money :amount="$rate['net']" :currency="$hotel['currency']" /></p>
                        <p><span class="text-slate-500">Board</span> {{ $rate['board_code'] }} {{ $rate['board_name'] }}</p>
                        <p><span class="text-slate-500">CheckRate</span> {{ $rate['rate_type'] === 'RECHECK' ? 'Required before booking' : 'Optional while this rate is BOOKABLE' }}</p>
                        <p><span class="text-slate-500">Allotment snapshot</span> {{ $rate['allotment'] ?? '—' }}</p>
                        <p><span class="text-slate-500">Packaging</span> {{ $rate['packaging'] === null ? '—' : ($rate['packaging'] ? 'true' : 'false') }}</p>
                    </div>
                    @if ($rate['cancellation_policies'])
                        <div class="mt-3 text-sm">
                            <p class="font-medium">Cancellation policy from this availability snapshot</p>
                            <ul class="mt-1 list-disc pl-5 text-slate-700">
                                @foreach ($rate['cancellation_policies'] as $policy)
                                    <li><x-money :amount="$policy['amount']" :currency="$hotel['currency']" /> from {{ $policy['from'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($rate['taxes'])
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($rate['taxes'] as $tax)
                                <x-badge :tone="$tax['included'] ? 'green' : 'amber'">
                                    {{ $tax['included'] ? 'Included tax' : 'Excluded tax' }}
                                    {{ $tax['sub_type'] }}
                                    <x-money :amount="$tax['amount']" :currency="$tax['currency'] ?: $hotel['currency']" />
                                </x-badge>
                            @endforeach
                        </div>
                    @endif
                    @if ($rate['rate_comments'])
                        <p class="mt-3 whitespace-pre-wrap text-sm text-slate-700">{{ $rate['rate_comments'] }}</p>
                    @endif
                    @if ($rate['promotions'])
                        <p class="mt-2 text-sm text-slate-600">Promotions returned by HBX: {{ count($rate['promotions']) }}</p>
                    @endif
                    @if ($search->isFresh())
                        <div class="mt-4 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('hotels.check-rate', $search) }}">
                                @csrf
                                <input type="hidden" name="rate_key" value="{{ $rate['rate_key'] }}">
                                <button class="btn btn-safe">{{ $rate['rate_type'] === 'RECHECK' ? 'Check Rate (required)' : 'Check Rate' }}</button>
                            </form>
                            @if ($bookable && $selection && $selection->isWithinValidity())
                                <a class="btn btn-primary" href="{{ route('bookings.create', ['selection' => $selection->id]) }}">Book</a>
                            @elseif ($bookable)
                                <form method="POST" action="{{ route('hotels.select', $search) }}">
                                    @csrf
                                    <input type="hidden" name="rate_key" value="{{ $rate['rate_key'] }}">
                                    <button class="btn btn-primary">Book</button>
                                </form>
                            @else
                                <button class="btn btn-primary" type="button" disabled>Book</button>
                            @endif
                        </div>
                    @endif
                    @if ($rate['rate_type'] === 'RECHECK' && ! $selection?->checkrate_completed_at)
                        <p class="mt-2 text-xs text-amber-800">Booking is disabled until CheckRate succeeds.</p>
                    @endif
                    @if ($rate['rate_class'] === 'NRF')
                        <p class="mt-2 text-xs text-slate-500">NRF generally identifies a non-refundable product. The cancellation policy fields remain authoritative.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@empty
    <p class="text-sm text-slate-600">This hotel did not include room rates in the snapshot.</p>
@endforelse
