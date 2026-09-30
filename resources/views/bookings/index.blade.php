<x-layouts.app title="Bookings">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Local bookings</h1>
            <p class="mt-1 text-sm text-slate-600">Only bookings created in this lab are listed here.</p>
        </div>
        <a href="{{ route('bookings.hbx') }}" class="btn btn-secondary">HBX booking list</a>
    </div>

    <section class="card overflow-hidden">
        @if ($bookings->isEmpty())
            <p class="p-8 text-sm text-slate-600">No local bookings yet. Search availability and confirm a TEST rate first.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">HBX reference</th>
                            <th class="px-4 py-3">Client reference</th>
                            <th class="px-4 py-3">Hotel</th>
                            <th class="px-4 py-3">Stay</th>
                            <th class="px-4 py-3">Total net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3"><x-badge :tone="$booking->status === 'CONFIRMED' ? 'green' : ($booking->status === 'CANCELLED' ? 'rose' : 'amber')">{{ $booking->status }}</x-badge></td>
                                <td class="px-4 py-3"><a class="font-medium underline" href="{{ route('bookings.show', $booking) }}">{{ $booking->hbx_reference ?: 'Pending' }}</a></td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $booking->client_reference }}</td>
                                <td class="px-4 py-3">{{ $booking->hotel_name }}</td>
                                <td class="px-4 py-3">{{ $booking->check_in?->toDateString() }} → {{ $booking->check_out?->toDateString() }}</td>
                                <td class="px-4 py-3"><x-money :amount="$booking->total_net" :currency="$booking->currency" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-4 py-3">{{ $bookings->links() }}</div>
        @endif
    </section>
</x-layouts.app>
