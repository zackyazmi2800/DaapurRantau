@extends('layouts.app')
@section('title', 'Status pencarian — Dapur Rantau')
@section('content')
<section class="page-container py-16">
    <div class="mx-auto max-w-3xl rounded-3xl border border-line bg-cream/50 p-8">
        <h1 class="section-title">Pencarian belum dapat dilanjutkan</h1>
        <p role="alert" class="mt-5 leading-7 text-muted">{{ $message }}</p>
        <x-button href="{{ route('home') }}#cari-resep" class="mt-8">Kembali ke bahanmu</x-button>
        <a href="{{ route('account.required') }}" class="nav-link ml-4 text-primary">Login / Daftar</a>
    </div>
</section>
@endsection
