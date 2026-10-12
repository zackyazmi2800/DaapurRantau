@extends('layouts.app')
@section('title', 'Layanan belum tersedia — Dapur Rantau')
@section('content')
<section class="page-container py-16 text-center"><h1 class="section-title">Maaf, layanan sedang mengalami gangguan.</h1><p class="mt-5 text-muted">Silakan coba kembali nanti.</p><x-button href="{{ route('home') }}" class="mt-8">Kembali ke Beranda</x-button></section>
@endsection
