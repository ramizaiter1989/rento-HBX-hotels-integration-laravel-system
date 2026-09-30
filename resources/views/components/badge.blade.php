@props(['tone' => 'slate'])
@php
    $tones = [
        'slate' => 'bg-slate-100 text-slate-800',
        'green' => 'bg-emerald-100 text-emerald-900',
        'amber' => 'bg-amber-100 text-amber-950',
        'rose' => 'bg-rose-100 text-rose-900',
        'sky' => 'bg-sky-100 text-sky-900',
        'indigo' => 'bg-indigo-100 text-indigo-900',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold '.$tones[$tone]]) }}>{{ $slot }}</span>
