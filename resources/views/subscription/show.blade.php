@extends('layouts.app')
@section('title', 'Langganan saya — Dapur Rantau')
@section('content')
<section class="page-container py-12 sm:py-16">
    <h1 class="section-title">Langganan saya</h1>
    <div class="mt-6 rounded-3xl border border-line bg-cream p-6">
        <h2 class="text-2xl font-bold">{{ $active ? 'Premium aktif' : 'Akun Free' }}</h2>
        @if ($active)
            <p class="mt-2">Berlaku hingga {{ \Illuminate\Support\Carbon::parse($active->ends_at, 'UTC')->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }} WIB.</p>
        @endif
        <p class="mt-2">{{ $used }} pencarian berhasil hari ini. Batas paket saat ini {{ $active ? 30 : 1 }} per hari.</p>
        <x-button href="{{ route('pricing') }}" class="mt-4">{{ $active ? 'Perpanjang Premium' : 'Lihat paket' }}</x-button>
    </div>
    @if ($checkout)
        <div class="mt-6" data-snap-checkout data-token="{{ $checkout }}" data-return-url="{{ route('subscription.show') }}">
            <x-button id="open-snap">Buka pembayaran Sandbox</x-button>
            <p class="mt-2 text-muted" role="status" id="snap-message"></p>
        </div>
        <script defer src="https://app.sandbox.midtrans.com/snap/snap.js" data-client-key="{{ config('midtrans.client_key') }}"></script>
    @endif
    <h2 class="mt-10 text-xl font-bold">Pembayaran saya</h2>
    <ul class="mt-4 divide-y divide-line">
        @forelse ($payments as $payment)
            <li class="flex flex-wrap justify-between gap-3 py-4">
                <div><strong>{{ $payment->name }}</strong><p class="text-sm text-muted">{{ \Illuminate\Support\Carbon::parse($payment->created_at, 'UTC')->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB · Rp{{ number_format($payment->amount, 0, ',', '.') }}</p></div>
                <div>{{ $payment->disputed ? 'Sedang ditinjau' : ['pending' => 'Menunggu pembayaran', 'paid' => 'Berhasil', 'failed' => 'Gagal', 'expired' => 'Kedaluwarsa', 'review' => 'Sedang ditinjau', 'refunded' => 'Dana dikembalikan', 'chargeback' => 'Dibatalkan bank'][$payment->status] }}
                    @if ($payment->status === 'pending')
                        <a class="block text-primary underline" href="{{ route('subscription.show', ['bayar' => $payment->order_id]) }}">Lanjutkan pembayaran</a>
                        <form method="POST" action="{{ route('subscription.sync') }}">
                            @csrf
                            <input type="hidden" name="order_id" value="{{ $payment->order_id }}">
                            <button type="submit" class="text-primary underline">Perbarui status</button>
                        </form>
                    @endif
                </div>
            </li>
        @empty
            <li class="py-4 text-muted">Belum ada pembayaran.</li>
        @endforelse
    </ul>
    @if ($payments->hasPages())
        <nav aria-label="Halaman pembayaran" class="mt-4 flex gap-6">
            @if ($payments->previousPageUrl())
                <a class="text-primary underline" href="{{ $payments->previousPageUrl() }}">Sebelumnya</a>
            @endif
            @if ($payments->nextPageUrl())
                <a class="text-primary underline" href="{{ $payments->nextPageUrl() }}">Berikutnya</a>
            @endif
        </nav>
    @endif
</section>
@endsection
