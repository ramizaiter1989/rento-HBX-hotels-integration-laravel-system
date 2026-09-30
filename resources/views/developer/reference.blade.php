<x-layouts.app title="Developer reference">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">HBX developer reference</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">Internal notes from the manually verified TEST flow. Pending items are not claimed as complete.</p>
    </div>

    <div class="space-y-4">
        @foreach ([
            ['HBX Status', 'GET /hotel-api/1.0/status confirms credentials and connectivity. A successful TEST response uses status OK. It is a diagnostic, not part of the customer booking path.'],
            ['Availability', 'POST /hotel-api/1.0/hotels. Send stay, occupancies, and exactly one filter: destination, geolocation, or hotels. The lab fingerprints the normalized search, reuses a fresh hotel_searches snapshot inside the cache TTL, and otherwise stores the raw supplier body. Results show 20 hotels per page from that stored snapshot, and rooms load only when requested. Allotment numbers such as 43, 42, then 41 can move between calls and are not local stock.'],
            ['Availability lifecycle', 'Cache TTL (HBX_AVAILABILITY_CACHE_TTL_SECONDS, default 60) decides whether an identical search reuses a snapshot. Reuse does not extend that TTL. Debug retention (HBX_AVAILABILITY_RETENTION_DAYS, default 7) is how long unbooked snapshots and old availability logs stay for inspection. Checked-rate freshness (HBX_CHECKED_RATE_TTL_SECONDS, default 300) is the booking window after a successful CheckRate. A direct BOOKABLE selection is bookable only until the snapshot expires. None of these windows is an HBX contractual guarantee. php artisan hbx:availability:cleanup removes unbooked snapshots and availability logs older than retention. Searches linked to a booking are kept. The daily schedule runs only when a server calls php artisan schedule:run, or while php artisan schedule:work is running. Local development does not need a permanent worker.'],
            ['rateKey', 'An opaque supplier token. Store and resend it exactly. Do not decode, split, rebuild, trim, or derive business rules from its contents.'],
            ['rateType BOOKABLE', 'The rate may proceed to Booking. CheckRate is optional. If CheckRate is called, the returned rateKey replaces the previous one.'],
            ['rateType RECHECK', 'CheckRate is mandatory before Booking. The booking action stays disabled until CheckRate succeeds and the current rate type is BOOKABLE.'],
            ['CheckRate', 'POST /hotel-api/1.0/checkrates with rooms[].rateKey. It can refresh rateKey, rateType, net, allotment, cancellation policies, taxes, rate comments, and paymentDataRequired.'],
            ['Booking', 'POST /hotel-api/1.0/bookings. Persist a unique client reference before the call. Do not retry the POST after a timeout. Reconcile with Booking List and Booking Detail.'],
            ['Booking Detail', 'GET /hotel-api/1.0/bookings/{reference}. Refresh updates the current HBX state and keeps the original confirmation snapshot.'],
            ['Booking List', 'GET /hotel-api/1.0/bookings with start, end, filterType, status, from, and to. TEST results can include other users. Match local rows by HBX reference or client reference. Do not import unknown rows.'],
            ['Cancellation Simulation', 'DELETE with cancellationFlag=SIMULATION. The response may say CANCELLED and may include a cancellation reference. That is hypothetical. A follow-up Booking Detail can still show CONFIRMED. Never overwrite the real status from a simulation.'],
            ['Actual Cancellation', 'DELETE with cancellationFlag=CANCELLATION. This is destructive on TEST. Persist cancellationReference, amount, currency, and the supplier response. Do not retry automatically after a timeout.'],
            ['Booking Change Simulation', 'GET the current Booking Detail, copy that supplier booking, change only the requested holder name and surname, then PUT with mode SIMULATION. The booking object keeps the live hotel, rooms, rates, and totals. A simulated holder does not change the stored booking. Actual execution mode is not verified and stays disabled.'],
            ['NOR and NRF', 'NOR is the normal/flexible class in supplier data. NRF generally identifies a non-refundable product. Always read cancellationPolicies. Do not infer the fee from the class alone.'],
            ['AT_WEB', 'Observed payment type. Display it. This lab does not collect card numbers, PAN, or CVV.'],
            ['paymentDataRequired', 'false means that CheckRate did not ask for extra payment data. It does not mean the rate is free. totalNet remains payable to the supplier contract.'],
            ['Taxes', 'Keep included taxes separate from amounts payable at the property. Do not add an excluded city tax into totalNet.'],
            ['Cancellation policies', 'Preserve the supplier from timestamp, including the offset such as +02:00. Amounts can change between Availability, CheckRate, and the confirmed booking. Each stage is authoritative only for itself.'],
            ['Content API and mTLS', 'A developer command can request one hotel from the Hotels Content API. Add --import to store that hotel locally. hbx:content:hotels reads one page of at most 10 hotels and does not import them. hbx:content:sync-hotels imports pages of 50 hotels by default and can resume a stopped run. The Content lab at /content/hotels reads those imported rows from MySQL and does not call HBX. A raw snapshot opens only on its developer page. Hotel search does not read content, and image files are not downloaded. ENG is the canonical structural language. Production credentials, production mTLS, and actual booking-change execution remain pending. Do not invent hotel images or an execution mode.'],
        ] as [$heading, $body])
            <section class="card p-5">
                <h2 class="text-base font-semibold">{{ $heading }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $body }}</p>
            </section>
        @endforeach
    </div>
</x-layouts.app>
