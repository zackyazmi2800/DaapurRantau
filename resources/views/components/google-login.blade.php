@if(app(\App\Services\GoogleLoginService::class)->configured())
    <x-button href="{{ route('google.redirect') }}" variant="secondary" class="mt-4 w-full">Masuk dengan Google</x-button>
@else
    <x-button disabled variant="secondary" class="mt-4 w-full" aria-describedby="google-status">Masuk dengan Google</x-button>
    <p id="google-status" class="mt-2 text-sm text-muted">Login Google belum tersedia. Gunakan akun email dan password.</p>
@endif
