@props(['light' => false])
<a href="{{ route('home') }}" aria-label="Dapur Rantau, beranda" {{ $attributes->class(['inline-flex items-center gap-2.5 rounded-lg']) }}>
    <span @class(['flex size-10 items-center justify-center rounded-2xl', 'bg-primary text-white' => !$light, 'bg-white/10 text-accent' => $light])><x-icon name="pot" class="size-6" /></span>
    <span class="text-xl font-extrabold tracking-tight">Dapur<span @class(['text-primary' => !$light, 'text-accent' => $light])>Rantau</span><span class="text-primary">.</span></span>
</a>
