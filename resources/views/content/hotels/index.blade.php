<x-layouts.app title="Content hotels">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Content hotels</h1>
            <p class="mt-1 text-sm text-slate-600">Imported hotels from the local MySQL content tables. This page does not call HBX.</p>
        </div>
    </div>

    <form method="get" action="{{ route('content.hotels.index') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-4">
        <label class="block min-w-64 flex-1 text-sm">
            <span class="mb-1 block font-medium text-slate-700">Hotel code or name</span>
            <input class="field" type="search" name="q" value="{{ $term }}" placeholder="712 or hotel name">
        </label>
        <input type="hidden" name="language" value="{{ $language }}">
        <button class="btn btn-primary" type="submit">Search</button>
        @if ($term !== '')
            <a class="btn btn-secondary" href="{{ route('content.hotels.index', ['language' => $language]) }}">Clear</a>
        @endif
    </form>

    <section class="card overflow-hidden">
        @if ($hotels->isEmpty())
            <p class="p-8 text-sm text-slate-600">No imported hotels match this search.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">City</th>
                            <th class="px-4 py-3">Country</th>
                            <th class="px-4 py-3">Destination</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Ranking</th>
                            <th class="px-4 py-3">Origin</th>
                            <th class="px-4 py-3">Language</th>
                            <th class="px-4 py-3">Supplier update</th>
                            <th class="px-4 py-3">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hotels as $hotel)
                            @php
                                $translation = $hotel->translations->first();
                                $snapshot = $hotel->snapshots->first();
                                $origin = $snapshot->content_origin ?? null;
                            @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3 font-mono text-xs">
                                    <a class="font-medium underline" href="{{ route('content.hotels.show', ['hotelCode' => $hotel->hbx_hotel_code, 'language' => $language]) }}">{{ $hotel->hbx_hotel_code }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $translation->name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $translation->city ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $hotel->country_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $hotel->destination_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $hotel->category_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $hotel->ranking ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($origin)
                                        <x-badge :tone="$origin === 'details' ? 'sky' : 'slate'">{{ $origin }}</x-badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $translation->language ?? $snapshot->language ?? $language }}</td>
                                <td class="px-4 py-3">{{ $hotel->supplier_last_update?->toDateString() ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $hotel->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-4 py-3">{{ $hotels->links() }}</div>
        @endif
    </section>
</x-layouts.app>
