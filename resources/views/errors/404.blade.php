@extends('layouts.app')
@section('title', 'Data tidak ditemukan — Dapur Rantau')
@section('content')
<section class="page-container py-16 text-center"><h1 class="section-title">Data ini tidak tersedia.</h1><p class="mt-5 text-muted">Resep atau riwayat mungkin sudah dihapus atau tidak tersedia pada sesi ini.</p><x-button href="{{ route('home') }}#cari-resep" class="mt-8">Cari Resep</x-button></section>
@endsection
