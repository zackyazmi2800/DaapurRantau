@php($payment = request()->is('langganan', 'langganan/*'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <meta name="theme-color" content="#427A54">
    <title>{{ $payment ? 'Pembayaran belum dapat diproses' : 'Layanan belum tersedia' }} — Dapur Rantau</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white font-sans text-ink antialiased">
    <header class="border-b border-line bg-cream">
        <div class="page-container flex h-22 items-center"><x-brand /></div>
    </header>
    <main id="konten" class="page-container py-16 text-center">
        <h1 class="section-title">{{ $payment ? 'Pembayaran belum dapat diproses' : 'Layanan belum tersedia' }}</h1>
        <p class="mx-auto mt-5 max-w-xl text-muted">{{ $payment ? 'Layanan pembayaran sedang tidak tersedia.' : 'Dapur Rantau sedang tidak dapat diakses.' }} Silakan coba kembali beberapa saat lagi.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @if ($payment)
                <x-button href="{{ route('pricing') }}">Kembali ke Pilihan paket</x-button>
                <x-button href="{{ route('subscription.show') }}" variant="secondary">Langganan saya</x-button>
            @else
                <x-button href="{{ route('home') }}">Kembali ke Beranda</x-button>
            @endif
        </div>
    </main>
</body>
</html>
