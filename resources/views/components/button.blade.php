@props(['href' => null, 'variant' => 'primary'])
@php
    $classes = 'inline-flex items-center justify-center gap-2.5 rounded-xl px-6 py-3.5 text-sm font-bold transition duration-200 disabled:cursor-wait disabled:opacity-70 motion-reduce:transition-none '.($variant === 'primary' ? 'bg-primary text-white shadow-sm hover:bg-primary-dark hover:shadow-md' : 'border border-line bg-white text-ink hover:bg-cream');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
