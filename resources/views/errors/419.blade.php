@extends('layouts.app')
@section('title', 'Sesi perlu diperbarui — Dapur Rantau')
@section('content')
<section class="page-container py-16 text-center"><h1 class="section-title">Sesi halamanmu sudah berakhir.</h1><p class="mt-5 text-muted">Buka kembali halaman dan coba lagi. Data yang belum dikirim mungkin perlu diisi ulang.</p><x-button href="{{ route('home') }}#cari-resep" class="mt-8">Kembali ke Beranda</x-button></section>
@endsection
