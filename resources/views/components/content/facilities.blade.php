@props(['rows', 'roomCode' => null, 'labels' => [], 'groupLabels' => []])

<div class="overflow-x-auto">
    <table class="min-w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                @if ($roomCode !== null)
                    <th class="px-4 py-3">Room</th>
                @endif
                <th class="px-4 py-3">Facility</th>
                <th class="px-4 py-3">Group</th>
                <th class="px-4 py-3">Order</th>
                <th class="px-4 py-3">Number</th>
                <th class="px-4 py-3">Distance</th>
                <th class="px-4 py-3">Fee</th>
                <th class="px-4 py-3">Yes / No</th>
                <th class="px-4 py-3">Amount</th>
                <th class="px-4 py-3">Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-slate-100">
                    @if ($roomCode !== null)
                        <td class="px-4 py-3 font-mono text-xs">{{ $roomCode }}</td>
                    @endif
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs">{{ $row->facility_code }}</span>
                        @if ($name = ($labels[$row->facility_code.':'.$row->facility_group_code] ?? null))
                            <span class="mt-1 block text-slate-700">{{ $name }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs">{{ $row->facility_group_code }}</span>
                        @if ($groupLabel = ($groupLabels[$row->facility_group_code] ?? null))
                            <span class="mt-1 block text-slate-700">{{ $groupLabel }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $row->sort_order ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $row->number_value ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $row->distance ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $row->ind_fee === null ? '—' : ($row->ind_fee ? 'Yes' : 'No') }}</td>
                    <td class="px-4 py-3">{{ $row->ind_yes_or_no === null ? '—' : ($row->ind_yes_or_no ? 'Yes' : 'No') }}</td>
                    <td class="px-4 py-3">
                        @if ($row->amount !== null)
                            {{ $row->amount }} {{ $row->currency }}
                            @if ($row->application_type)
                                <span class="text-slate-500">{{ $row->application_type }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $values = array_filter([
                                $row->time_from ? 'from '.$row->time_from : null,
                                $row->time_to ? 'to '.$row->time_to : null,
                                $row->date_to ? 'until '.$row->date_to->toDateString() : null,
                            ]);
                        @endphp
                        {{ $values === [] ? '—' : implode(' · ', $values) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-6 text-slate-600" colspan="{{ $roomCode !== null ? 10 : 9 }}">None stored.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
