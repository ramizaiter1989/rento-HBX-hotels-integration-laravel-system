<x-layouts.app title="Availability results">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Availability snapshot</h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ $search->destination_code ?: 'Hotel filter' }}
                · {{ $search->check_in->toDateString() }} → {{ $search->check_out->toDateString() }}
                · {{ $search->rooms_count }} room(s), {{ $search->adults_count }} adult(s), {{ $search->children_count }} child(ren) per room
            </p>
            <p class="mt-1 text-sm text-slate-600">{{ $hotels->total() }} hotels in this stored snapshot. This page shows {{ $hotels->count() }} of them. Changing page does not call HBX.</p>
            <p class="mt-1 text-sm text-slate-600">
                Stored {{ $search->created_at?->format('Y-m-d H:i:s') }}
                · Expires {{ $search->expires_at?->format('Y-m-d H:i:s') ?? 'not set' }}
                @if (session('availability_source') === 'CACHE_HIT')
                    · Reused snapshot
                @elseif (session('availability_source') === 'LIVE_HBX')
                    · Fresh HBX call
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('developer.search-raw', $search) }}" class="btn btn-secondary">Raw HBX response</a>
            <a href="{{ route('hotels.search') }}" class="btn btn-secondary">New search</a>
        </div>
    </div>

    @if ($search->isExpired())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">Historical availability snapshot. Run a new search before selecting a rate.</div>
    @endif

    @if ($selections->isNotEmpty())
        <section class="card mb-6 p-5">
            <h2 class="text-base font-semibold">Latest checked or selected rate</h2>
            @foreach ($selections->take(1) as $selection)
                <div class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                    <p><span class="text-slate-500">Hotel</span> {{ $selection->hotel_name }} ({{ $selection->hotel_code }})</p>
                    <p><span class="text-slate-500">Room</span> {{ $selection->room_name }} · {{ $selection->room_code }}</p>
                    <p><span class="text-slate-500">Current rate type</span> {{ $selection->rate_type }}</p>
                    <p><span class="text-slate-500">Net</span> <x-money :amount="$selection->net" :currency="$selection->currency" /></p>
                    <p><span class="text-slate-500">CheckRate</span> {{ $selection->checkrate_completed_at ? 'Completed '.$selection->checkrate_completed_at->format('Y-m-d H:i:s') : 'Not run' }}</p>
                    <p><span class="text-slate-500">Rate valid until</span> {{ $selection->valid_until?->format('Y-m-d H:i:s') ?? 'not set' }}</p>
                    <p><span class="text-slate-500">paymentDataRequired</span> {{ $selection->payment_data_required === null ? '—' : ($selection->payment_data_required ? 'true' : 'false') }}</p>
                </div>
                @if ($selection->original_rate_key !== $selection->rate_key)
                    <p class="mt-3 text-sm text-amber-800">CheckRate replaced the rateKey. Booking uses the latest key only.</p>
                @endif
                @if ($selection->bookingAllowed() && $selection->isWithinValidity())
                    <a href="{{ route('bookings.create', ['selection' => $selection->id]) }}" class="btn btn-primary mt-4">Continue to booking</a>
                @elseif (! $selection->bookingAllowed())
                    <p class="mt-3 text-sm text-rose-800">Booking stays disabled until this rate is BOOKABLE after CheckRate.</p>
                @else
                    <p class="mt-3 text-sm text-rose-800">This rate selection has expired. Run a fresh hotel search or CheckRate before booking.</p>
                @endif
            @endforeach
        </section>
    @endif

    @if ($hotels->total() === 0)
        <section class="card p-8 text-center">
            <h2 class="text-lg font-semibold">No hotels in this snapshot</h2>
            <p class="mt-2 text-sm text-slate-600">HBX returned total 0. That is an empty availability result, not automatically an API failure.</p>
        </section>
    @endif

    <div class="space-y-4">
        @foreach ($hotels as $hotel)
            <article
                class="card overflow-hidden"
                data-rooms-url="{{ route('hotels.rooms', ['search' => $search, 'hotelCode' => $hotel['code']]) }}"
                x-data="{
                    open: false,
                    loading: false,
                    loaded: false,
                    html: '',
                    async toggle() {
                        if (this.loaded) {
                            this.open = !this.open;
                            return;
                        }
                        this.loading = true;
                        const response = await fetch(this.$el.dataset.roomsUrl, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
                        });
                        this.html = response.ok ? await response.text() : 'Rooms could not be loaded from the stored snapshot.';
                        this.loaded = true;
                        this.open = true;
                        this.loading = false;
                    }
                }"
            >
                <div class="flex gap-4 p-5">
                    <div class="hidden h-24 w-32 shrink-0 items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 px-2 text-center text-[11px] text-slate-500 sm:flex">
                        No supplier image. Content API enrichment pending.
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold">{{ $hotel['name'] ?: 'Unnamed hotel' }}</h2>
                                <p class="mt-1 text-sm text-slate-600">
                                    Code {{ $hotel['code'] }}
                                    @if ($hotel['category']) · {{ $hotel['category'] }} @endif
                                    @if ($hotel['destination_name'] || $hotel['destination_code']) · {{ $hotel['destination_name'] }} {{ $hotel['destination_code'] }} @endif
                                    @if ($hotel['zone']) · {{ $hotel['zone'] }} @endif
                                </p>
                                @if ($hotel['latitude'] || $hotel['longitude'])
                                    <p class="mt-1 text-xs text-slate-500">{{ $hotel['latitude'] }}, {{ $hotel['longitude'] }}</p>
                                @endif
                            </div>
                            <div class="text-right text-sm">
                                <p class="text-slate-500">Price range</p>
                                <p class="font-semibold"><x-money :amount="$hotel['min_rate']" :currency="$hotel['currency']" /> – <x-money :amount="$hotel['max_rate']" :currency="$hotel['currency']" /></p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-secondary" @click="toggle()" x-text="loading ? 'Loading rooms' : (open ? 'Hide rooms' : 'Show rooms')"></button>
                            <a class="btn btn-secondary" href="{{ route('hotels.rooms', ['search' => $search, 'hotelCode' => $hotel['code']]) }}">Open rooms</a>
                        </div>
                    </div>
                </div>
                <div x-show="open" x-cloak class="border-t border-slate-200 bg-slate-50 p-5" x-html="html"></div>
            </article>
        @endforeach
    </div>

    @if ($hotels->hasPages())
        <div class="mt-6">{{ $hotels->links() }}</div>
    @endif
</x-layouts.app>
