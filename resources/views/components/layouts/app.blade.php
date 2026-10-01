<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rento HBX Lab' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="bg-amber-400 px-4 py-2 text-center text-sm font-semibold tracking-wide text-amber-950">
        HBX {{ strtoupper((string) config('hbx.environment')) }} ENVIRONMENT
        <span class="font-normal">· Local supplier laboratory · Not production Rento</span>
    </div>

    <header class="border-b border-slate-800 bg-slate-900 text-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4">
            <a href="{{ route('dashboard') }}" class="text-lg font-semibold tracking-tight">Rento HBX Lab</a>
            <nav class="flex flex-wrap gap-1 text-sm">
                @foreach ([
                    'dashboard' => 'Dashboard',
                    'hotels.search' => 'Hotel Search',
                    'bookings.index' => 'Bookings',
                    'content.hotels.index' => 'Content',
                    'developer.sync' => 'Sync',
                    'developer.test-matrix' => 'Test matrix',
                    'hbx.status' => 'HBX Status',
                    'developer.logs' => 'HBX API Logs',
                    'developer.reference' => 'Developer Reference',
                ] as $route => $label)
                    @php
                        $active = request()->routeIs($route)
                            || ($route === 'bookings.index' && request()->routeIs('bookings.*'))
                            || ($route === 'content.hotels.index' && request()->routeIs('content.*'));
                    @endphp
                    <a href="{{ route($route) }}" @class([
                        'rounded-md px-3 py-2',
                        'bg-white text-slate-900' => $active,
                        'text-slate-200 hover:bg-slate-800' => ! $active,
                    ])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if (session('hbx_error'))
            <x-alert :error="session('hbx_error')" />
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-semibold">The form needs attention.</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
