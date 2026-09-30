@props(['title' => 'Raw HBX response', 'payload' => null])
@php
    $text = '';
    if (is_string($payload) && $payload !== '') {
        $text = $payload;
    } elseif (is_array($payload)) {
        $text = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
@endphp
@if ($text !== '')
    <details class="mt-4 rounded-md border border-slate-200 bg-slate-50" x-data="{ copied: false }">
        <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-slate-700">{{ $title }}</summary>
        <div class="border-t border-slate-200 px-4 py-3">
            <button type="button" class="btn btn-secondary mb-3" @click="navigator.clipboard.writeText($refs.payload.textContent); copied = true">
                <span x-show="!copied">Copy</span>
                <span x-show="copied" x-cloak>Copied</span>
            </button>
            <pre x-ref="payload" class="max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-slate-950 p-4 text-xs leading-5 text-slate-100">{{ $text }}</pre>
        </div>
    </details>
@endif
