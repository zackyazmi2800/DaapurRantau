@auth
    <div class="relative" data-profile-dropdown>
        <button type="button" id="profile-toggle" aria-label="Buka informasi akun" aria-expanded="false" aria-controls="profile-panel" class="flex min-h-11 items-center gap-2 rounded-xl border border-primary/20 bg-sage px-3 text-primary hover:bg-primary/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div id="profile-panel" hidden role="region" aria-labelledby="profile-heading" class="absolute right-0 z-50 mt-3 w-60 max-w-[calc(100vw-6rem)] rounded-2xl border border-line bg-white p-4 shadow-lg">
            <h2 id="profile-heading" class="text-xs font-semibold tracking-wide text-muted">Informasi Akun</h2>
            <p class="mt-3 text-sm font-bold wrap-anywhere">{{ auth()->user()->name }}</p>
            @if(auth()->user()->email)
                <p class="mt-1 text-sm text-muted wrap-anywhere">{{ auth()->user()->email }}</p>
            @endif
            <form action="{{ route('logout') }}" method="POST" data-toast-logout class="mt-4 border-t border-line pt-3">
                @csrf
                <button type="submit" class="min-h-11 w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-primary hover:bg-sage focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Keluar</button>
            </form>
        </div>
    </div>
@endauth
