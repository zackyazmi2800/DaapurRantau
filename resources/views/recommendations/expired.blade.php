@extends('layouts.app')
@section('title', 'Cari resep lagi — Dapur Rantau')
@section('content')
<section class="page-container py-20 text-center"><div class="mx-auto max-w-xl rounded-3xl border border-line bg-cream p-8 sm:p-12"><x-icon name="clock" class="mx-auto size-12 text-primary" /><h1 class="section-title">Rekomendasi ini sudah tidak tersedia. Yuk, cari resep lagi!</h1><p class="mt-5 leading-7 text-muted">Hasil hanya tersedia sementara pada sesi pencarianmu.</p><x-button href="{{ route('home') }}#cari-resep" class="mt-8">Cari Resep Lagi</x-button></div></section>
@endsection
