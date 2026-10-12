@props(['name', 'class' => 'size-5'])
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('arrow') <path d="M5 12h14m-6-6 6 6-6 6"/> @break
        @case('leaf') <path d="M20 4c-8-1-15 2-15 8a6 6 0 0 0 6 6c6 0 9-7 9-14Z"/><path d="M4 21 15 10"/> @break
        @case('pot') <path d="M5 11h14v5a5 5 0 0 1-5 5h-4a5 5 0 0 1-5-5v-5ZM3 11h18M2 14h3m14 0h3M8 7c-2-2 2-3 0-5m4 5c-2-2 2-3 0-5m4 5c-2-2 2-3 0-5"/> @break
        @case('sparkles') <path d="m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4L12 3Zm8-2v4m-2-2h4"/> @break
        @case('clock') <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/> @break
        @case('heart') <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/> @break
        @case('plus') <path d="M12 5v14M5 12h14"/> @break
        @case('close') <path d="m6 6 12 12M6 18 18 6"/> @break
        @case('menu') <path d="M4 6h16M4 12h16M4 18h16"/> @break
        @case('check') <path d="m5 12 4 4L19 6"/> @break
        @case('bowl') <path d="M3 11h18a9 9 0 0 1-18 0Zm5 9h8M7 7l3-4m3 4 3-4"/> @break
        @case('wallet') <rect x="3" y="6" width="18" height="14" rx="3"/><path d="M3 9V5a2 2 0 0 1 2-2h12v3m4 6h-6v5h6m-4-2.5h.01"/> @break
        @case('chart') <path d="M5 19v-5m7 5V9m7 10V4"/> @break
    @endswitch
</svg>
