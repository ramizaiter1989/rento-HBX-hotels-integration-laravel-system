@php
    $website = is_string($hotel->web) && preg_match('/^https?:\/\/[^\s<>"\']+$/i', $hotel->web) === 1 ? $hotel->web : null;
    $email = is_string($hotel->email) && preg_match('/^[^\s<>"\']+@[^\s<>"\']+$/', $hotel->email) === 1 ? $hotel->email : null;
    $origin = $snapshot->content_origin ?? null;
@endphp

<x-layouts.app :title="'Hotel '.$hotel->hbx_hotel_code">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500">Local content · MySQL only</p>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $hotel->hbx_hotel_code }} · {{ $translation->name ?? 'Hotel '.$hotel->hbx_hotel_code }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                Origin: {{ $origin ?? '—' }}
                · {{ $language }}
                · Supplier update {{ $hotel->supplier_last_update?->toDateString() ?? '—' }}
            </p>
        </div>
        <a href="{{ route('content.hotels.index', ['language' => $language]) }}" class="btn btn-secondary">All hotels</a>
    </div>

    <div class="card mb-6 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" x-data="{ tab: 'overview' }">
        <div class="sm:col-span-2 lg:col-span-3">
            <nav class="flex flex-wrap gap-1 text-sm">
                @foreach ([
                    'overview' => 'Overview',
                    'rooms' => 'Rooms',
                    'images' => 'Images',
                    'facilities' => 'Facilities',
                    'room-facilities' => 'Room Facilities',
                    'stays' => 'Room Stays',
                    'phones' => 'Phones',
                    'boards' => 'Boards',
                    'segments' => 'Segments',
                    'terminals' => 'Terminals',
                    'points' => 'Points of Interest',
                    'translations' => 'Translations',
                    'snapshot' => 'Snapshot',
                ] as $id => $label)
                    <button type="button" class="rounded-md px-3 py-2" :class="tab === '{{ $id }}' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'" @click="tab = '{{ $id }}'">{{ $label }}</button>
                @endforeach
            </nav>
        </div>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'overview'">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'Hotel code' => (string) $hotel->hbx_hotel_code,
                    'Name' => $translation->name ?? '—',
                    'Address' => trim(implode(', ', array_filter([$translation->address_line ?? null, $translation->address_street ?? null, $hotel->address_number]))) ?: '—',
                    'City' => $translation->city ?? '—',
                    'Postal code' => $hotel->postal_code ?: '—',
                    'Country' => trim(($hotel->country_code ?: '').($labels['countries'][$hotel->country_code] ?? null ? ' · '.$labels['countries'][$hotel->country_code] : '').($hotel->country_iso_code ? ' · ISO '.$hotel->country_iso_code : '')) ?: '—',
                    'Destination' => trim(($hotel->destination_code ?: '').($labels['destinations'][$hotel->destination_code] ?? null ? ' · '.$labels['destinations'][$hotel->destination_code] : '').($hotel->destination_country_code ? ' · '.$hotel->destination_country_code : '').($hotel->zone_code ? ' · zone '.$hotel->zone_code : '').($labels['zones'] ? ' · '.$labels['zones'] : '')) ?: '—',
                    'Coordinates' => ($hotel->latitude !== null && $hotel->longitude !== null) ? $hotel->latitude.', '.$hotel->longitude : '—',
                    'Category' => trim(($hotel->category_code ?: '').($labels['categories'][$hotel->category_code] ?? null ? ' · '.$labels['categories'][$hotel->category_code] : '').($hotel->category_group_code ? ' · '.$hotel->category_group_code : '').($labels['category_groups'][$hotel->category_group_code] ?? null ? ' · '.$labels['category_groups'][$hotel->category_group_code] : '')) ?: '—',
                    'Chain' => trim(($hotel->chain_code ?: '').($labels['chains'][$hotel->chain_code] ?? null ? ' · '.$labels['chains'][$hotel->chain_code] : '')) ?: '—',
                    'Accommodation' => trim(($hotel->accommodation_type_code ?: '').($labels['accommodations'][$hotel->accommodation_type_code] ?? null ? ' · '.$labels['accommodations'][$hotel->accommodation_type_code] : '')) ?: '—',
                    'Ranking' => $hotel->ranking === null ? '—' : (string) $hotel->ranking,
                    'License' => $hotel->license ?: '—',
                    'Origin' => $origin ?? '—',
                    'Content hash' => $snapshot->content_hash ?? '—',
                    'Supplier lastUpdate' => $hotel->supplier_last_update?->toDateString() ?? '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                        <dd class="mt-1 break-all text-sm text-slate-900">{{ $value }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt>
                    <dd class="mt-1 text-sm">
                        @if ($email)
                            <a class="underline" href="mailto:{{ $email }}">{{ $email }}</a>
                        @else
                            {{ $hotel->email ?: '—' }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Website</dt>
                    <dd class="mt-1 break-all text-sm">
                        @if ($website)
                            <a class="underline" href="{{ $website }}" rel="noopener noreferrer">{{ $website }}</a>
                        @else
                            {{ $hotel->web ?: '—' }}
                        @endif
                    </dd>
                </div>
            </dl>
            @if ($translation?->description)
                <p class="mt-4 text-sm leading-6 text-slate-700">{{ $translation->description }}</p>
            @endif
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'rooms'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Room code</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Characteristic</th>
                            <th class="px-4 py-3">PMS room code</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">Commercial</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hotel->rooms as $room)
                            @php $roomTranslation = $room->translations->first(); @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ $room->room_code }}
                                    @if ($catalog = ($labels['rooms'][$room->room_code] ?? null))
                                        <span class="mt-1 block font-sans text-slate-500">{{ $catalog }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $room->type_code }}
                                    @if ($typeLabel = ($labels['room_types'][$room->type_code] ?? null))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $typeLabel }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $room->characteristic_code }}
                                    @if ($characteristicLabel = ($labels['room_characteristics'][$room->characteristic_code] ?? null))
                                        <span class="mt-1 block text-xs text-slate-500">{{ $characteristicLabel }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $room->pms_room_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $roomTranslation->description ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $roomTranslation->commercial_description ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="6">No rooms stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'images'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Thumbnail</th>
                            <th class="px-4 py-3">Path</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Visual order</th>
                            <th class="px-4 py-3">Room</th>
                            <th class="px-4 py-3">Source position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hotel->images as $image)
                            @php $thumbnail = \App\Support\ContentImageUrl::thumbnail($image->path); @endphp
                            <tr class="border-t border-slate-100 align-top">
                                <td class="px-4 py-3">
                                    @if ($thumbnail)
                                        <img src="{{ $thumbnail }}" alt="" width="96" height="64" loading="lazy" class="h-16 w-24 rounded bg-slate-100 object-cover">
                                    @else
                                        <span class="text-slate-500">Not displayed</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 break-all font-mono text-xs">{{ $image->path }}</td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs">{{ $image->image_type_code }}</span>
                                    @if ($imageLabel = ($labels['image_types'][$image->image_type_code] ?? null))
                                        <span class="mt-1 block">{{ $imageLabel }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $image->visual_order }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $image->room_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $image->source_position }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="6">No images stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'facilities'" x-cloak>
            <x-content.facilities :rows="$hotel->facilities" :labels="$labels['facilities']" :group-labels="$labels['facility_groups']" />
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'room-facilities'" x-cloak>
            @php
                $roomFacilities = $hotel->rooms->flatMap(fn ($room) => $room->facilities->map(fn ($facility) => ['room' => $room->room_code, 'facility' => $facility]));
            @endphp
            @if ($roomFacilities->isEmpty())
                <p class="text-sm text-slate-600">No room facilities stored.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Room</th>
                                <th class="px-4 py-3">Facility</th>
                                <th class="px-4 py-3">Group</th>
                                <th class="px-4 py-3">Order</th>
                                <th class="px-4 py-3">Number</th>
                                <th class="px-4 py-3">Distance</th>
                                <th class="px-4 py-3">Fee</th>
                                <th class="px-4 py-3">Yes / No</th>
                                <th class="px-4 py-3">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roomFacilities as $item)
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-3 font-mono text-xs">{{ $item['room'] }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-mono text-xs">{{ $item['facility']->facility_code }}</span>
                                        @if ($facilityLabel = ($labels['facilities'][$item['facility']->facility_code.':'.$item['facility']->facility_group_code] ?? null))
                                            <span class="mt-1 block">{{ $facilityLabel }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $item['facility']->facility_group_code }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->sort_order ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->number_value ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->distance ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->ind_fee === null ? '—' : ($item['facility']->ind_fee ? 'Yes' : 'No') }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->ind_yes_or_no === null ? '—' : ($item['facility']->ind_yes_or_no ? 'Yes' : 'No') }}</td>
                                    <td class="px-4 py-3">{{ $item['facility']->amount ?? '—' }} {{ $item['facility']->currency }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'stays'" x-cloak>
            @php $stays = $hotel->rooms->flatMap(fn ($room) => $room->stays->map(fn ($stay) => ['room' => $room->room_code, 'stay' => $stay])); @endphp
            @if ($stays->isEmpty())
                <p class="text-sm text-slate-600">No room stays stored.</p>
            @else
                <div class="space-y-4">
                    @foreach ($stays as $item)
                        <div class="rounded-md border border-slate-200">
                            <p class="px-4 py-3 text-sm font-medium">{{ $item['room'] }} · {{ $item['stay']->stay_type }} · order {{ $item['stay']->stay_order }}</p>
                            <x-content.facilities :rows="$item['stay']->facilities" :labels="$labels['facilities']" :group-labels="$labels['facility_groups']" />
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'phones'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Number</th><th class="px-4 py-3">Type</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->phones as $phone)
                            <tr class="border-t border-slate-100"><td class="px-4 py-3">{{ $phone->phone_number }}</td><td class="px-4 py-3">{{ $phone->phone_type }}</td></tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="2">No phones stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'boards'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Board code</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->boards as $board)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs">{{ $board->board_code }}</span>
                                    @if ($boardLabel = ($labels['boards'][$board->board_code] ?? null))
                                        <span class="ml-2">{{ $boardLabel }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600">No boards stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'segments'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Segment code</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->segments as $segment)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs">{{ $segment->segment_code }}</span>
                                    @if ($segmentLabel = ($labels['segments'][$segment->segment_code] ?? null))
                                        <span class="ml-2">{{ $segmentLabel }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600">No segments stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'terminals'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Distance</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->terminals as $terminal)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ $terminal->terminal_code }}</td>
                                <td class="px-4 py-3">{{ $terminal->terminal_type ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $terminal->distance ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="3">No terminals stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'points'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Facility</th><th class="px-4 py-3">Group</th><th class="px-4 py-3">Order</th><th class="px-4 py-3">Distance</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->interestPoints as $point)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ $point->translations->first()->poi_name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $point->facility_code }}</td>
                                <td class="px-4 py-3">{{ $point->facility_group_code }}</td>
                                <td class="px-4 py-3">{{ $point->sort_order }}</td>
                                <td class="px-4 py-3">{{ $point->distance ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="5">No points of interest stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'translations'" x-cloak>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Language</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">City</th><th class="px-4 py-3">Address</th></tr></thead>
                    <tbody>
                        @forelse ($hotel->translations as $row)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ $row->language }}</td>
                                <td class="px-4 py-3">{{ $row->name }}</td>
                                <td class="px-4 py-3">{{ $row->city ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $row->address_line ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="4">No translations stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sm:col-span-2 lg:col-span-3" x-show="tab === 'snapshot'" x-cloak>
            <p class="mb-4 text-sm text-slate-600">Snapshot metadata is loaded here. The raw JSON stays on the developer page and is not part of this response.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Origin</th>
                            <th class="px-4 py-3">Language</th>
                            <th class="px-4 py-3">Content hash</th>
                            <th class="px-4 py-3">Bytes</th>
                            <th class="px-4 py-3">Updated</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hotel->snapshots as $row)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ $row->content_origin ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $row->language }}</td>
                                <td class="px-4 py-3 break-all font-mono text-xs">{{ $row->content_hash }}</td>
                                <td class="px-4 py-3">{{ number_format((int) $row->payload_bytes) }}</td>
                                <td class="px-4 py-3">{{ $row->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    <a class="underline" href="{{ route('developer.content.snapshot', ['hotelCode' => $hotel->hbx_hotel_code, 'language' => $row->language]) }}">View raw JSON</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-600" colspan="6">No snapshot stored.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
