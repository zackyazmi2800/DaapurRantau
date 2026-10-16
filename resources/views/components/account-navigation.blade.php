@auth
    <a href="{{ route('favorites.index') }}" class="nav-link">Favorit</a>
    <a href="{{ route('history.index') }}" class="nav-link">Riwayat</a>
@else
    <a href="{{ route('login') }}" class="nav-link">Masuk</a>
    <a href="{{ route('register') }}" class="nav-link font-bold text-primary">Daftar</a>
@endauth
