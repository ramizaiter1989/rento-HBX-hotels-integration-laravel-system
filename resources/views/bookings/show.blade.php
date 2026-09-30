<x-layouts.app title="Booking {{ $booking->hbx_reference ?: $booking->client_reference }}">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">HBX TEST ENVIRONMENT</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $booking->hotel_name ?: 'Booking' }}</h1>
            <p class="mt-1 text-sm text-slate-600">Client reference {{ $booking->client_reference }}</p>
        </div>
        <x-badge :tone="$booking->status === 'CONFIRMED' ? 'green' : ($booking->status === 'CANCELLED' ? 'rose' : 'amber')">{{ $booking->status }}</x-badge>
    </div>

    @if ($booking->status === 'ambiguous')
        <div class="mb-6 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            The booking call did not return. Do not submit it again. Reconcile with client reference {{ $booking->client_reference }} using Booking Detail and the HBX booking list.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card p-5 lg:col-span-2">
            <h2 class="text-base font-semibold">Booking summary</h2>
            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div><dt class="text-slate-500">HBX reference</dt><dd class="font-medium">{{ $booking->hbx_reference ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Creation date</dt><dd>{{ $booking->creation_date ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Holder</dt><dd>{{ $booking->holder_name }} {{ $booking->holder_surname }}</dd></div>
                <div><dt class="text-slate-500">Hotel</dt><dd>{{ $booking->hotel_name }} · {{ $booking->hotel_code }}</dd></div>
                <div><dt class="text-slate-500">Stay</dt><dd>{{ $booking->check_in?->toDateString() }} → {{ $booking->check_out?->toDateString() }}</dd></div>
                <div><dt class="text-slate-500">Destination / zone</dt><dd>{{ $booking->destination_name ?: '—' }} {{ $booking->zone_name }}</dd></div>
                <div><dt class="text-slate-500">Total net</dt><dd><x-money :amount="$booking->total_net" :currency="$booking->currency" /></dd></div>
                <div><dt class="text-slate-500">Pending amount</dt><dd><x-money :amount="$booking->pending_amount" :currency="$booking->currency" /></dd></div>
                <div><dt class="text-slate-500">Payment type</dt><dd>{{ $booking->payment_type ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">paymentDataRequired</dt><dd>{{ $booking->payment_data_required === null ? '—' : ($booking->payment_data_required ? 'true' : 'false') }}</dd></div>
                <div><dt class="text-slate-500">Supplier</dt><dd>{{ $booking->supplier_name ?: '—' }} {{ $booking->supplier_vat }}</dd></div>
                <div><dt class="text-slate-500">Remark</dt><dd>{{ $booking->remark ?: '—' }}</dd></div>
            </dl>
        </section>
        <section class="card p-5">
            <h2 class="text-base font-semibold">Modification permissions</h2>
            <p class="mt-3 text-sm">Cancellation allowed: {{ $booking->cancellation_allowed === null ? '—' : ($booking->cancellation_allowed ? 'true' : 'false') }}</p>
            <p class="mt-1 text-sm">Modification allowed: {{ $booking->modification_allowed === null ? '—' : ($booking->modification_allowed ? 'true' : 'false') }}</p>
            <p class="mt-4 text-sm text-slate-600">Actual HBX modification execution is pending supplier verification.</p>
            <button class="btn btn-primary mt-3" type="button" disabled>Execute modification</button>
        </section>
    </div>

    @foreach ($booking->rooms as $room)
        <section class="card mt-6 p-5">
            <h2 class="text-base font-semibold">{{ $room->room_name }} <span class="text-sm font-normal text-slate-500">{{ $room->room_code }}</span></h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($room->rate_class)<x-badge tone="slate">{{ $room->rate_class }}</x-badge>@endif
                @if ($room->rate_type)<x-badge tone="green">{{ $room->rate_type }}</x-badge>@endif
                @if ($room->payment_type)<x-badge tone="sky">{{ $room->payment_type }}</x-badge>@endif
                @if ($room->board_code)<x-badge tone="slate">{{ $room->board_code }} {{ $room->board_name }}</x-badge>@endif
            </div>
            <p class="mt-3 text-sm">Net <x-money :amount="$room->net" :currency="$room->currency ?: $booking->currency" /></p>
            <h3 class="mt-4 text-sm font-semibold">Guests</h3>
            <ul class="mt-2 text-sm">
                @foreach ($room->guests as $guest)
                    <li>Room {{ $guest->room_id }} · {{ $guest->type }} · {{ $guest->name }} {{ $guest->surname }} @if ($guest->age !== null) · age {{ $guest->age }} @endif</li>
                @endforeach
            </ul>
            <h3 class="mt-4 text-sm font-semibold">Cancellation policy</h3>
            <ul class="mt-2 text-sm">
                @forelse ($room->cancellationPolicies as $policy)
                    <li><x-money :amount="$policy->amount" :currency="$policy->currency ?: $booking->currency" /> from {{ $policy->raw_from_value }}</li>
                @empty
                    <li class="text-slate-500">None stored.</li>
                @endforelse
            </ul>
            <h3 class="mt-4 text-sm font-semibold">Taxes</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse ($room->taxes as $tax)
                    <li>
                        <x-badge :tone="$tax->included ? 'green' : 'amber'">{{ $tax->included ? 'Included' : 'Payable separately / at property' }}</x-badge>
                        {{ $tax->sub_type }} <x-money :amount="$tax->amount" :currency="$tax->currency" />
                    </li>
                @empty
                    <li class="text-slate-500">None stored.</li>
                @endforelse
            </ul>
            @if ($room->rate_comments)
                <h3 class="mt-4 text-sm font-semibold">Supplier comments</h3>
                <p class="mt-2 whitespace-pre-wrap text-sm text-slate-700">{{ $room->rate_comments }}</p>
            @endif
        </section>
    @endforeach

    <section class="card mt-6 p-5">
        <h2 class="text-base font-semibold">Stored confirmation vs current HBX state</h2>
        <p class="mt-1 text-sm text-slate-600">The original confirmation snapshot is not overwritten by refresh, simulation, or a later sync.</p>
        @if ($comparison['mismatches'])
            <p class="mt-3 text-sm font-medium text-amber-800">Mismatches: {{ implode(', ', $comparison['mismatches']) }}</p>
        @elseif ($booking->live_hbx_response)
            <p class="mt-3 text-sm text-emerald-800">No differences on the compared fields.</p>
        @endif
        <div class="mt-4 grid gap-4 md:grid-cols-2 text-sm">
            <div>
                <h3 class="font-semibold">Local stored confirmation</h3>
                <p class="mt-2">Status {{ $comparison['stored']['status'] ?? '—' }}</p>
                <p>Holder {{ $comparison['stored']['holder_name'] ?? '—' }} {{ $comparison['stored']['holder_surname'] ?? '' }}</p>
                <p>Total net <x-money :amount="$comparison['stored']['total_net'] ?? null" :currency="$comparison['stored']['currency'] ?? null" /></p>
            </div>
            <div>
                <h3 class="font-semibold">Current HBX state</h3>
                <p class="mt-2">Status {{ $comparison['live']['status'] ?? '—' }}</p>
                <p>Holder {{ $comparison['live']['holder_name'] ?? '—' }} {{ $comparison['live']['holder_surname'] ?? '' }}</p>
                <p>Total net <x-money :amount="$comparison['live']['total_net'] ?? null" :currency="$comparison['live']['currency'] ?? null" /></p>
                <p class="text-slate-500">Last sync {{ $booking->last_hbx_sync_at?->format('Y-m-d H:i:s') ?: '—' }}</p>
            </div>
        </div>
        @if ($booking->hbx_reference)
            <form method="POST" action="{{ route('bookings.refresh', $booking) }}" class="mt-4" x-data="{ sending: false }" @submit="sending = true">
                @csrf
                <button class="btn btn-secondary" :disabled="sending">Refresh from HBX</button>
            </form>
        @endif
    </section>

    <section class="card mt-6 p-5">
        <h2 class="text-base font-semibold">Simulations</h2>
        <p class="mt-1 text-sm text-slate-600">Simulation responses are hypothetical. They never replace the real booking.</p>
        @forelse ($booking->simulations as $simulation)
            <article class="mt-4 rounded-md border border-sky-200 bg-sky-50 p-4 text-sm">
                <x-badge tone="indigo">Simulation only</x-badge>
                <p class="mt-2 font-medium">{{ $simulation->type }} · {{ $simulation->simulated_at->format('Y-m-d H:i:s') }}</p>
                <p>Simulated status {{ $simulation->simulated_status ?: '—' }}</p>
                @if ($simulation->type === 'cancellation')
                    <p>Simulated amount <x-money :amount="$simulation->cancellation_amount" :currency="$simulation->currency" /></p>
                    <p>Simulated cancellation reference {{ $simulation->supplier_cancellation_reference ?: '—' }}</p>
                    <p class="mt-2 font-medium">Current real booking remains: {{ $booking->status }}</p>
                @else
                    <p>Simulated holder {{ $simulation->holder_name }} {{ $simulation->holder_surname }}</p>
                    <p class="mt-2 font-medium">Current real booking remains: {{ $booking->status }}</p>
                    <p class="mt-2 font-medium">Current real holder remains: {{ $booking->holder_name }} {{ $booking->holder_surname }}</p>
                @endif
                <x-json-inspector title="Simulation response" :payload="$simulation->response_payload" />
            </article>
        @empty
            <p class="mt-3 text-sm text-slate-600">No simulations yet.</p>
        @endforelse

        @if ($booking->hbx_reference && $booking->status === 'CONFIRMED')
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <form method="POST" action="{{ route('bookings.cancel-simulation', $booking) }}" class="rounded-md border border-sky-200 p-4" x-data="{ sending: false }" @submit="sending = true">
                    @csrf
                    <h3 class="font-semibold">Simulate cancellation</h3>
                    <p class="mt-1 text-sm text-slate-600">Safe preview. A CANCELLED status in this response does not cancel the booking.</p>
                    <button class="btn btn-safe mt-3" :disabled="sending">Simulate Cancellation</button>
                </form>
                <form method="POST" action="{{ route('bookings.modify-simulation', $booking) }}" class="rounded-md border border-sky-200 p-4">
                    @csrf
                    <h3 class="font-semibold">Simulate modification</h3>
                    <div class="mt-3 grid gap-3">
                        <label class="text-sm">Holder name<input class="field mt-1" name="holder_name" value="{{ $booking->holder_name }}" required></label>
                        <label class="text-sm">Holder surname<input class="field mt-1" name="holder_surname" value="{{ $booking->holder_surname }}" required></label>
                    </div>
                    <button class="btn btn-safe mt-3">Simulate Modification</button>
                </form>
            </div>

            <div class="mt-6 rounded-md border border-rose-300 bg-rose-50 p-4" x-data="{ open: false }">
                <h3 class="font-semibold text-rose-900">Actual cancellation</h3>
                <p class="mt-1 text-sm text-rose-900">HBX TEST ENVIRONMENT. This sends a real cancellation to the supplier.</p>
                <button type="button" class="btn btn-danger mt-3" @click="open = true">Actually Cancel</button>
                <div x-show="open" x-cloak class="fixed inset-0 z-20 flex items-center justify-center bg-slate-950/50 p-4">
                    <form method="POST" action="{{ route('bookings.cancel', $booking) }}" class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                        @csrf
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">HBX TEST ENVIRONMENT</p>
                        <h3 class="mt-2 text-lg font-semibold">You are about to actually cancel this HBX TEST booking.</h3>
                        <p class="mt-2 text-sm text-slate-600">Reference {{ $booking->hbx_reference }}. This cannot be treated as a simulation.</p>
                        <label class="mt-4 flex items-start gap-2 text-sm">
                            <input type="checkbox" name="confirm_cancel" value="1" required>
                            <span>I understand this cancels the TEST booking.</span>
                        </label>
                        <div class="mt-5 flex gap-2">
                            <button class="btn btn-danger" type="submit">Cancel TEST booking</button>
                            <button class="btn btn-secondary" type="button" @click="open = false">Keep booking</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </section>

    @if ($booking->cancellation_reference)
        <section class="card mt-6 p-5 text-sm">
            <h2 class="text-base font-semibold">Cancellation</h2>
            <p class="mt-2">Reference {{ $booking->cancellation_reference }}</p>
            <p>Amount <x-money :amount="$booking->cancellation_amount" :currency="$booking->currency" /></p>
            <p>Cancelled at {{ $booking->cancelled_at?->format('Y-m-d H:i:s') ?: '—' }}</p>
        </section>
    @endif

    <section class="card mt-6 p-5">
        <h2 class="text-base font-semibold">Operation timeline</h2>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @forelse ($booking->apiLogs->sortByDesc('id') as $log)
                <li class="py-3">
                    <p class="font-medium">{{ $log->operation }} · {{ $log->request_method }} · HTTP {{ $log->http_status ?? '—' }}</p>
                    <p class="text-slate-600">{{ $log->created_at->format('Y-m-d H:i:s') }} · {{ $log->local_duration_ms }} ms · processTime {{ $log->supplier_process_time ?: '—' }}</p>
                    @if ($log->error_message)<p class="text-rose-800">{{ $log->error_message }}</p>@endif
                    <x-json-inspector title="Request JSON" :payload="$log->request_payload" />
                    <x-json-inspector title="Response JSON" :payload="$log->response_payload" />
                </li>
            @empty
                <li class="py-3 text-slate-600">No operations recorded for this booking.</li>
            @endforelse
        </ul>
    </section>

    <x-json-inspector title="Original booking response" :payload="$booking->raw_booking_response" />
    <x-json-inspector title="Latest live HBX response" :payload="$booking->live_hbx_response" />
</x-layouts.app>
