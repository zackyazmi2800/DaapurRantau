@props(['image' => null, 'large' => false])
@php($photo = app(\App\Services\RecipeImageService::class)->forDisplay($image))
<figure {{ $attributes->class(['overflow-hidden rounded-2xl bg-cream']) }} data-recipe-photo>
    <img src="{{ $large ? $photo['image_url'] : $photo['image_small_url'] }}" alt="{{ $photo['image_alt'] }}"
        width="800" height="600" loading="{{ $large ? 'eager' : 'lazy' }}" decoding="async"
        class="aspect-[4/3] w-full object-cover" data-recipe-image data-fallback="{{ asset('images/recipe-placeholder.svg') }}">
    @if ($photo['image_source'] === 'unsplash')
        <figcaption class="px-4 py-3 text-xs leading-5 text-muted" data-photo-credit>
            Foto ilustrasi oleh <a class="rounded font-semibold underline hover:text-primary" href="{{ $photo['photographer_url'] }}" target="_blank" rel="noopener noreferrer">{{ $photo['photographer_name'] }}</a>
            di <a class="rounded font-semibold underline hover:text-primary" href="{{ $photo['photo_url'] }}" target="_blank" rel="noopener noreferrer">Unsplash</a>. Tampilan masakan dapat berbeda.
        </figcaption>
    @elseif ($photo['image_source'] === 'local')
        <figcaption class="px-4 py-3 text-xs text-muted" data-photo-credit>Foto masakan terkurasi.</figcaption>
    @endif
</figure>
