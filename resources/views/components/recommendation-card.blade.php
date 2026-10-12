@props(['recipe', 'search'])
<article class="flex min-w-0 flex-col overflow-hidden rounded-3xl border border-line bg-white shadow-soft transition duration-200 hover:-translate-y-1 hover:shadow-md motion-reduce:transform-none motion-reduce:transition-none">
    <x-recipe-image :image="$recipe['image'] ?? null" />
    <div class="flex flex-1 flex-col p-6">
        <div><x-match-badge :match="$recipe['match']" /></div>
        <h2 class="mt-5 text-2xl font-bold tracking-tight break-words">{{ $recipe['title'] }}</h2>
        <p class="mt-3 text-sm leading-7 break-words text-muted">{{ $recipe['description'] }}</p>
        <div class="mt-5"><x-recipe-meta :recipe="$recipe" /></div>
        <div class="my-6 border-t border-line pt-5 text-sm">
            <p class="font-semibold">{{ $recipe['match']['matched'] }} dari {{ $recipe['match']['required'] }} bahan wajib tersedia</p>
            @if ($recipe['match']['missing'])
                <p class="mt-3 text-muted">Butuh {{ $recipe['match']['missing_ingredients_count'] }} bahan tambahan</p>
                <ul class="mt-2 flex flex-wrap gap-2">@foreach ($recipe['match']['missing'] as $ingredient)<li class="max-w-full rounded-lg bg-cream px-2.5 py-1.5 break-words">{{ $ingredient['name'] }}</li>@endforeach</ul>
            @else
                <p class="mt-3 text-muted">Semua bahan tersedia</p>
                @if($recipe['match']['category'] === 'Tanpa Bahan Tambahan')<ul class="mt-2 flex flex-wrap gap-2">@foreach($recipe['ingredients'] as $ingredient)<li class="rounded-lg bg-sage px-2.5 py-1.5">{{ $ingredient['name'] }}{{ $ingredient['optional'] ? ' (opsional)' : '' }}</li>@endforeach</ul>@endif
            @endif
        </div>
        <x-button href="{{ route('recommendations.detail', [$search, $recipe['id']]) }}" class="mt-auto w-full" aria-label="Lihat resep {{ $recipe['title'] }}">Lihat Resep <x-icon name="arrow" class="size-4" /></x-button>
        <div class="mt-3"><x-favorite-button :recipe="$recipe" :search="$search" /></div>
    </div>
</article>
