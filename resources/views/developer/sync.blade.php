<x-layouts.app title="Content sync">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Content sync</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">This page reads MySQL only. Opening it does not call HBX. Run the commands in a terminal when you intend to use quota.</p>
    </div>

    <section class="card mb-6 p-5">
        <h2 class="text-base font-semibold">Stored content</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                'Hotels' => $counts['hotels'],
                'ENG list origin' => $counts['list'],
                'ENG details origin' => $counts['details'],
                'Rooms' => $counts['rooms'],
                'Images' => $counts['images'],
                'Facilities' => $counts['facilities'],
            ] as $label => $value)
                <div>
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-lg font-semibold">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="card mb-6 p-5">
        <h2 class="text-base font-semibold">Reference catalogs</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($references as $type => $value)
                <div>
                    <dt class="text-slate-500">{{ $type }}</dt>
                    <dd class="font-semibold">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="card mb-6 p-5">
        <h2 class="text-base font-semibold">Commands</h2>
        <div class="mt-3 space-y-3">
            @foreach ($commands as $label => $command)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <pre class="mt-1 overflow-x-auto rounded-md bg-slate-900 px-3 py-2 text-sm text-slate-100">{{ $command }}</pre>
                </div>
            @endforeach
        </div>
    </section>

    <section class="card mb-6 overflow-x-auto">
        <h2 class="px-5 pt-5 text-base font-semibold">Latest runs</h2>
        <table class="mt-3 min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-3">Run</th>
                    <th class="px-3 py-3">Type</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">Language</th>
                    <th class="px-3 py-3">Total</th>
                    <th class="px-3 py-3">Next from</th>
                    <th class="px-3 py-3">Requested target</th>
                    <th class="px-3 py-3">Fetched total</th>
                    <th class="px-3 py-3">Remaining</th>
                    <th class="px-3 py-3">Imported</th>
                    <th class="px-3 py-3">Updated</th>
                    <th class="px-3 py-3">Unchanged</th>
                    <th class="px-3 py-3">Failed</th>
                    <th class="px-3 py-3">Details retained</th>
                    <th class="px-3 py-3">Conflicts</th>
                    <th class="px-3 py-3">Started</th>
                    <th class="px-3 py-3">Progress</th>
                    <th class="px-3 py-3">Completed</th>
                    <th class="px-3 py-3">lastUpdateTime</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($runs as $run)
                    <tr class="border-t border-slate-100">
                        <td class="px-3 py-3">{{ $run->id }}</td>
                        <td class="px-3 py-3">{{ $run->sync_type }}</td>
                        <td class="px-3 py-3">{{ $run->status }}</td>
                        <td class="px-3 py-3">{{ $run->language }}</td>
                        <td class="px-3 py-3">{{ $run->supplier_total ?? '—' }}</td>
                        <td class="px-3 py-3">{{ $run->next_from }}</td>
                        <td class="px-3 py-3">{{ $run->requested_limit ?? 'none' }}</td>
                        <td class="px-3 py-3">{{ $run->fetched }}</td>
                        <td class="px-3 py-3">{{ $run->remaining() === null ? 'none' : $run->remaining() }}</td>
                        <td class="px-3 py-3">{{ $run->imported }}</td>
                        <td class="px-3 py-3">{{ $run->updated }}</td>
                        <td class="px-3 py-3">{{ $run->unchanged }}</td>
                        <td class="px-3 py-3">{{ $run->failed }}</td>
                        <td class="px-3 py-3">{{ $run->details_retained }}</td>
                        <td class="px-3 py-3">{{ $run->conflicts }}</td>
                        <td class="px-3 py-3">{{ $run->started_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-3 py-3">{{ $run->last_progress_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="px-3 py-3">{{ $run->completed_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="px-3 py-3">{{ $run->last_update_time?->toDateString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-6 text-slate-600" colspan="19">No sync runs stored.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="px-5 pt-5 text-base font-semibold">Failed hotel imports</h2>
        <p class="px-5 pt-1 text-sm text-slate-600">A failed hotel stays on the failed page until that page is imported successfully. The message is the first line of the import error.</p>
        <table class="mt-3 min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Run</th>
                    <th class="px-4 py-3">Hotel code</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Message</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($failures as $failure)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">{{ $failure->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $failure->content_sync_run_id }}</td>
                        <td class="px-4 py-3">{{ $failure->hbx_hotel_code ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $failure->failure_type }}</td>
                        <td class="px-4 py-3">{{ $failure->message }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-slate-600" colspan="5">No failed hotel imports stored.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
