@props(['recipe', 'search' => null])
@if ($recipe['favorite_id'] ?? null)
    <form method="POST" action="{{ route('favorites.destroy', $recipe['favorite_id']) }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary" class="w-full"><x-icon name="heart" />Batalkan Favorit</x-button><p class="mt-2 text-xs text-primary">Tersimpan di favoritmu</p></form>
@elseif ($search)
    <form method="POST" action="{{ route('favorites.store', [$search, $recipe['id']]) }}">@csrf<x-button type="submit" variant="secondary" class="w-full"><x-icon name="heart" />Simpan Favorit</x-button></form>
@endif
