@props(['title', 'image', 'alt', 'time', 'category', 'description'])
<article class="group overflow-hidden rounded-3xl border border-line bg-white transition duration-300 hover:-translate-y-1 hover:shadow-soft motion-reduce:transform-none motion-reduce:transition-none">
    <div class="relative aspect-[4/3] overflow-hidden bg-cream">
        <img src="{{ asset('images/'.$image) }}" alt="{{ $alt }}" width="700" height="525" loading="lazy" class="size-full object-cover transition duration-500 group-hover:scale-105 motion-reduce:transform-none">
        <span class="absolute top-4 left-4 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-ink">Contoh tampilan</span>
    </div>
    <div class="p-6">
        <p class="mb-2 text-[11px] font-bold tracking-[0.13em] text-primary uppercase">{{ $category }}</p>
        <h3 class="text-xl font-bold tracking-tight">{{ $title }}</h3>
        <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
        <div class="mt-5 flex items-center gap-5 border-t border-line pt-4 text-xs font-medium text-muted">
            <span class="inline-flex items-center gap-1.5"><x-icon name="clock" class="size-4 text-primary" />{{ $time }} menit</span>
            <span class="inline-flex items-center gap-1.5"><x-icon name="chart" class="size-4 text-primary" />Mudah</span>
        </div>
    </div>
</article>
