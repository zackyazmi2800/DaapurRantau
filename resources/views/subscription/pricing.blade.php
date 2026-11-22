@extends('layouts.app')
@section('title', 'Pilihan paket — Dapur Rantau')
@section('content')
<section class="page-container py-12 sm:py-16">
    <h1 class="section-title">Pilihan paket</h1>
    <p class="mt-3 max-w-2xl text-muted">Kuota akun diperbarui setiap pukul 00.00 WIB. Satu pencarian berhasil menggunakan satu kuota.</p>
    <div class="mt-8 grid gap-5 md:grid-cols-3">
        <article class="rounded-3xl border border-line p-6">
            <h2 class="text-xl font-bold">Free</h2>
            <p class="my-4 text-3xl font-bold">Rp0</p>
            <p class="text-muted">1 pencarian per hari.</p>
            <x-button href="{{ route('home') }}#cari-resep" variant="secondary" class="mt-6">Cari resep</x-button>
        </article>
        @foreach ($plans as $plan)
            <article class="rounded-3xl border border-line bg-cream p-6">
                <h2 class="text-xl font-bold">{{ $plan->name }}</h2>
                <p class="my-4 text-3xl font-bold">Rp{{ number_format($plan->price, 0, ',', '.') }}</p>
                <p class="text-muted">30 pencarian per hari, riwayat dan favorit selama {{ $plan->code === 'weekly' ? '7 hari' : '1 bulan kalender' }}.</p>
                @auth
                    <form class="mt-6" method="POST" action="{{ route('subscription.checkout') }}">
                        @csrf
                        <input type="hidden" name="plan" value="{{ $plan->code }}">
                        <x-button type="submit" :disabled="!$checkoutReady">Pilih {{ $plan->name }}</x-button>
                    </form>
                @else
                    <x-button href="{{ route('login') }}" class="mt-6">Masuk untuk membeli</x-button>
                @endauth
            </article>
        @endforeach
    </div>
    @auth
        @unless ($checkoutReady)
            <p class="mt-6 text-sm text-muted">Pembelian tersedia setelah kunci Midtrans Sandbox dikonfigurasi.</p>
        @endunless
    @endauth
    <p class="mt-6 text-sm text-muted">Pembayaran simulasi melalui Midtrans Sandbox. Perpanjangan dilakukan manual.</p>
</section>
@endsection
