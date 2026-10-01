<x-layouts.app title="HBX test matrix">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">HBX test matrix</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">Local status only. Opening this page does not call HBX. Automated tests are not live supplier verification.</p>
    </div>

    <div class="space-y-4">
        @foreach ($items as $item)
            <article class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="text-lg font-semibold">{{ $item['name'] }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <x-badge :tone="$item['implementation'] === 'IMPLEMENTED' ? 'green' : 'rose'">{{ $item['implementation'] }}</x-badge>
                        <x-badge tone="slate">{{ $item['automated'] }}</x-badge>
                        <x-badge :tone="$item['live'] === 'LIVE VERIFIED' ? 'green' : 'amber'">{{ $item['live'] }}</x-badge>
                    </div>
                </div>
                <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Route or command</dt>
                        <dd class="mt-1 font-mono text-xs">{{ $item['entry'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Live HBX required</dt>
                        <dd class="mt-1">{{ $item['live_hbx'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Commercial write</dt>
                        <dd class="mt-1">{{ $item['commercial_write'] }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-sm text-slate-700">{{ $item['notes'] }}</p>
            </article>
        @endforeach
    </div>
</x-layouts.app>
