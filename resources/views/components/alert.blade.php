@props(['error'])
<div class="mb-6 rounded-md border border-rose-200 bg-white px-4 py-4 shadow-sm">
    <p class="text-base font-semibold text-rose-800">{{ $error['heading'] ?? 'HBX request failed' }}</p>
    @if (! empty($error['code']))
        <p class="mt-2 text-sm text-slate-700">Supplier error code: <span class="font-mono">{{ $error['code'] }}</span></p>
    @endif
    <p class="mt-1 text-sm text-slate-800">{{ $error['message'] ?? '' }}</p>
    @if (! empty($error['detail']))
        <details class="mt-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-600">Technical detail</summary>
            <pre class="mt-2 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">{{ json_encode($error['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif
</div>
