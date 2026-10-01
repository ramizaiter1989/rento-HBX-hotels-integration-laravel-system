<x-layouts.app title="Hotel search">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Hotel search</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">Availability is a snapshot. Use one filter only: a destination code or hotel codes. PMI is the default TEST example, not the only destination.</p>
        <p class="mt-2 text-xs text-slate-500">Search → Results → Hotel → Room/rate → CheckRate → Guest details → Booking → Confirmation</p>
    </div>

    <form method="POST" action="{{ route('hotels.search.store') }}" class="card p-6" x-data="{ sending: false, children: {{ (int) old('children', $defaults['children']) }} }" @submit="sending = true">
        @csrf
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label class="block text-sm">
                <span class="font-medium">Check-in</span>
                <input class="field mt-1" type="date" name="check_in" value="{{ old('check_in', $defaults['check_in']) }}" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Check-out</span>
                <input class="field mt-1" type="date" name="check_out" value="{{ old('check_out', $defaults['check_out']) }}" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Rooms</span>
                <input class="field mt-1" type="number" name="rooms" min="1" max="5" value="{{ old('rooms', $defaults['rooms']) }}" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Adults per room</span>
                <input class="field mt-1" type="number" name="adults" min="1" max="8" value="{{ old('adults', $defaults['adults']) }}" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Children per room</span>
                <input class="field mt-1" type="number" name="children" min="0" max="4" x-model.number="children" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Destination code</span>
                <input class="field mt-1" type="text" name="destination_code" value="{{ old('destination_code', $defaults['destination_code']) }}" maxlength="8">
            </label>
            <label class="block text-sm xl:col-span-2">
                <span class="font-medium">Hotel code</span>
                <input class="field mt-1" type="text" name="hotel_code" value="{{ old('hotel_code', $defaults['hotel_code']) }}" placeholder="Optional, for example 712. Leave empty when using a destination.">
            </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-4" x-show="children > 0" x-cloak>
            <template x-for="index in Number(children)" :key="index">
                <label class="block text-sm">
                    <span class="font-medium" x-text="`Child ${index} age`"></span>
                    <input class="field mt-1" type="number" min="0" max="17" :name="`child_ages[${index - 1}]`">
                </label>
            </template>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button class="btn btn-primary" :disabled="sending">
                <span x-show="!sending">Search HBX availability</span>
                <span x-show="sending" x-cloak>Contacting HBX…</span>
            </button>
            <p class="text-xs text-slate-500">Content API images are not loaded. Hotel content enrichment is pending.</p>
        </div>
    </form>
</x-layouts.app>
