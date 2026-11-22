@extends('layouts.app')
@section('title', 'Premium diperlukan — Dapur Rantau')
@section('content')
<section class="page-container py-16">
    <h1 class="section-title">Favorit dan riwayat memerlukan Premium</h1>
    <p class="mt-4 text-muted">Koleksi yang pernah kamu simpan bisa dibuka kembali saat Premium aktif.</p>
    <x-button href="{{ route('pricing') }}" class="mt-6">Lihat paket Premium</x-button>
</section>
@endsection
