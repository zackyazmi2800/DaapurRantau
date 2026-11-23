<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <meta name="theme-color" content="#427A54">
    <title>Pembayaran sedang sibuk — Dapur Rantau</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white font-sans text-ink antialiased">
    <header class="border-b border-line bg-cream">
        <div class="page-container flex h-22 items-center"><x-brand /></div>
    </header>
    <main id="konten" class="page-container py-16 text-center">
        <h1 class="section-title">Pembayaran sedang sibuk</h1>
        <p class="mx-auto mt-5 max-w-xl text-muted">{{ $message }}</p>
        <x-button href="{{ route('subscription.show') }}" class="mt-8">Kembali ke Langganan saya</x-button>
    </main>
</body>
</html>
