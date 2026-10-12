<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#427A54">
    <meta name="description" content="Nggak perlu bingung mau masak apa. Temukan inspirasi masakan praktis dan hemat bersama Dapur Rantau.">
    <title>@yield('title', config('app.name').' — Rasa rumah, di tanah rantau')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-ink antialiased">
    <a href="#konten" class="sr-only z-50 rounded-lg bg-white p-4 focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Lewati ke konten utama</a>
    <header class="relative z-20 border-b border-line bg-cream/60">
        <div class="page-container flex h-22 items-center justify-between gap-4">
            <x-brand />
            <nav aria-label="Navigasi utama" class="hidden items-center gap-9 text-sm font-semibold lg:flex">
                <a href="{{ route('home') }}#beranda" @if(request()->routeIs('home')) aria-current="page" @endif class="nav-link {{ request()->routeIs('home') ? 'text-primary' : '' }}">Beranda</a>
                <a href="{{ route('home') }}#cari-resep" class="nav-link">Cari Resep</a>
                <a href="{{ route('favorites.index') }}" @if(request()->routeIs('favorites.*')) aria-current="page" @endif class="nav-link {{ request()->routeIs('favorites.*') ? 'text-primary' : '' }}">Favorit</a><a href="{{ route('history.index') }}" @if(request()->routeIs('history.*')) aria-current="page" @endif class="nav-link {{ request()->routeIs('history.*') ? 'text-primary' : '' }}">Riwayat</a>
            </nav>
            <div class="hidden lg:block"><x-button href="{{ route('home') }}#cari-resep">Mulai Masak <x-icon name="arrow" class="size-4" /></x-button></div>
            <button type="button" id="menu-toggle" aria-controls="mobile-menu" aria-expanded="false" aria-label="Buka menu navigasi" class="rounded-xl border border-line p-2.5 lg:hidden"><x-icon name="menu" /></button>
        </div>
        <nav id="mobile-menu" aria-label="Navigasi seluler" hidden class="page-container border-t border-line pb-5 lg:hidden">
            <div class="flex flex-col items-stretch gap-1 pt-3 text-sm font-semibold">
                <a href="{{ route('home') }}#beranda" class="rounded-lg px-3 py-3 text-primary" @if(request()->routeIs('home')) aria-current="page" @endif>Beranda</a>
                <a href="{{ route('home') }}#cari-resep" class="rounded-lg px-3 py-3 hover:bg-sage">Cari Resep</a>
                <a href="{{ route('favorites.index') }}" class="rounded-lg px-3 py-3 hover:bg-sage">Favorit</a><a href="{{ route('history.index') }}" class="rounded-lg px-3 py-3 hover:bg-sage">Riwayat</a>
                <x-button href="{{ route('home') }}#cari-resep" class="mt-2">Mulai Masak <x-icon name="arrow" class="size-4" /></x-button>
            </div>
        </nav>
    </header>
    <main id="konten" tabindex="-1">@if(session('status'))<div role="status" class="page-container pt-6"><p class="rounded-xl bg-sage p-4 text-sm font-semibold text-primary">{{ session('status') }}</p></div>@endif
    @yield('content')</main>
    <footer class="bg-ink text-white">
        <div class="page-container grid gap-9 py-12 md:grid-cols-[1fr_auto] md:items-start">
            <div><x-brand :light="true" /><p class="mt-5 max-w-sm text-sm leading-7 text-white/70">Teman masak di tanah rantau. Dari bahan sederhana, hadirkan kehangatan rasa rumah.</p></div>
            <nav aria-label="Navigasi footer" class="flex flex-wrap items-center gap-7 text-sm text-white/80">
                <a href="{{ route('home') }}#beranda" class="hover:text-accent">Beranda</a><a href="{{ route('home') }}#cari-resep" class="hover:text-accent">Cari Resep</a><a href="{{ route('home') }}#cara-kerja" class="hover:text-accent">Cara Kerja</a><a href="{{ route('favorites.index') }}" class="hover:text-accent">Favorit</a><a href="{{ route('history.index') }}" class="hover:text-accent">Riwayat</a>
            </nav>
        </div>
        <div class="page-container"><div class="flex flex-col gap-3 border-t border-white/15 py-6 text-xs text-white/60 sm:flex-row sm:justify-between"><p>&copy; {{ date('Y') }} Dapur Rantau. Semua hak dilindungi.</p><p>Dibuat dengan cinta untuk anak rantau <span class="text-accent" aria-hidden="true">♡</span></p></div></div>
    </footer>

</body>
</html>
