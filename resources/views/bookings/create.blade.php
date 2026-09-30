<x-layouts.app title="Create booking">
    @php($search = $selection->search)
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Booking review</h1>
        <p class="mt-1 text-sm text-slate-600">The supplier rate below is the one that will be sent. A client reference is stored before the HBX call.</p>
    </div>

    <section class="card mb-6 p-5">
        <div class="flex flex-wrap gap-2">
            <x-badge :tone="$selection->rate_type === 'BOOKABLE' ? 'green' : 'amber'">{{ $selection->rate_type }}</x-badge>
            @if ($selection->rate_class)
                <x-badge :tone="$selection->rate_class === 'NRF' ? 'rose' : 'slate'">{{ $selection->rate_class }}</x-badge>
            @endif
            @if ($selection->payment_type)
                <x-badge tone="sky">{{ $selection->payment_type }}</x-badge>
            @endif
        </div>
        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-slate-500">Hotel</dt><dd class="font-medium">{{ $selection->hotel_name }} · {{ $selection->hotel_code }}</dd></div>
            <div><dt class="text-slate-500">Stay</dt><dd>{{ $search->check_in->toDateString() }} → {{ $search->check_out->toDateString() }}</dd></div>
            <div><dt class="text-slate-500">Room</dt><dd>{{ $selection->room_name }} · {{ $selection->room_code }}</dd></div>
            <div><dt class="text-slate-500">Board</dt><dd>{{ $selection->board_code }} {{ $selection->board_name }}</dd></div>
            <div><dt class="text-slate-500">Net</dt><dd><x-money :amount="$selection->net" :currency="$selection->currency" /></dd></div>
            <div><dt class="text-slate-500">Rate valid until</dt><dd>{{ $selection->valid_until?->format('Y-m-d H:i:s') ?? 'not set' }}</dd></div>
            <div><dt class="text-slate-500">paymentDataRequired</dt><dd>{{ $selection->payment_data_required === null ? 'Not returned' : ($selection->payment_data_required ? 'true' : 'false — this does not mean the stay is free') }}</dd></div>
        </dl>
        @if ($selection->policiesData())
            <div class="mt-4 text-sm">
                <p class="font-medium">Cancellation policy on the selected rate</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($selection->policiesData() as $policy)
                        <li><x-money :amount="$policy['amount'] ?? null" :currency="$selection->currency" /> from {{ $policy['from'] ?? '—' }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-slate-500">This can differ from the confirmed booking policy. After confirmation, the booking response is authoritative.</p>
            </div>
        @endif
        @if ($selection->taxesData())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($selection->taxesData() as $tax)
                    <x-badge :tone="! empty($tax['included']) ? 'green' : 'amber'">
                        {{ ! empty($tax['included']) ? 'Included tax' : 'Payable separately' }}
                        {{ $tax['sub_type'] ?? 'Tax' }}
                        <x-money :amount="$tax['amount'] ?? null" :currency="$tax['currency'] ?? $selection->currency" />
                    </x-badge>
                @endforeach
            </div>
        @endif
        @if ($selection->rate_comments)
            <div class="mt-4 rounded-md bg-slate-50 p-3 text-sm whitespace-pre-wrap">{{ $selection->rate_comments }}</div>
        @endif
    </section>

    @if (! $selection->isWithinValidity())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">This rate selection has expired. Run a fresh hotel search or CheckRate before booking.</div>
    @endif

    <form method="POST" action="{{ route('bookings.store') }}" class="card p-6" x-data="{ sending: false }" @submit="sending = true">
        @csrf
        <input type="hidden" name="selection_id" value="{{ $selection->id }}">
        <input type="hidden" name="submission_token" value="{{ old('submission_token', $submissionToken) }}">

        <h2 class="text-base font-semibold">Holder</h2>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <label class="text-sm">First name<input class="field mt-1" name="holder_name" value="{{ old('holder_name') }}" required></label>
            <label class="text-sm">Surname<input class="field mt-1" name="holder_surname" value="{{ old('holder_surname') }}" required></label>
        </div>

        <h2 class="mt-8 text-base font-semibold">Guests</h2>
        <p class="mt-1 text-sm text-slate-600">{{ $search->rooms_count }} room(s) × {{ $search->adults_count }} adult(s) and {{ $search->children_count }} child(ren).</p>
        <div class="mt-4 space-y-4">
            @foreach ($guestSlots as $index => $slot)
                <div class="rounded-md border border-slate-200 p-4">
                    <p class="text-sm font-medium">Room {{ $slot['room_id'] }} · {{ $slot['type'] === 'AD' ? 'Adult' : 'Child' }}</p>
                    <input type="hidden" name="guests[{{ $index }}][room_id]" value="{{ $slot['room_id'] }}">
                    <input type="hidden" name="guests[{{ $index }}][type]" value="{{ $slot['type'] }}">
                    @if ($slot['type'] === 'CH')
                        <input type="hidden" name="guests[{{ $index }}][age]" value="{{ $slot['age'] }}">
                    @endif
                    <div class="mt-3 grid gap-4 md:grid-cols-2">
                        <label class="text-sm">Name<input class="field mt-1" name="guests[{{ $index }}][name]" value="{{ old('guests.'.$index.'.name') }}" required></label>
                        <label class="text-sm">Surname<input class="field mt-1" name="guests[{{ $index }}][surname]" value="{{ old('guests.'.$index.'.surname') }}" required></label>
                    </div>
                </div>
            @endforeach
        </div>

        <label class="mt-6 block text-sm">Remark
            <input class="field mt-1" name="remark" value="{{ old('remark', 'Rento HBX local booking test') }}">
        </label>

        <div class="mt-6 flex items-center gap-3">
            <button class="btn btn-primary" :disabled="sending || {{ $selection->isWithinValidity() ? 'false' : 'true' }}">
                <span x-show="!sending">Book on HBX TEST</span>
                <span x-show="sending" x-cloak>Sending booking…</span>
            </button>
            <p class="text-xs text-slate-500">The button locks after submit. A timeout will not create a second booking.</p>
        </div>
    </form>
</x-layouts.app>
