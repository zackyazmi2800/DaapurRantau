@extends('auth.shell')
@section('title', 'Masuk — Dapur Rantau')
@section('heading', 'Selamat datang kembali')
@section('intro', 'Masuk dengan akun terverifikasi untuk menyimpan favorit, riwayat, dan melanjutkan pencarian.')
@section('form')
<form action="{{ route('login.store') }}" method="POST" novalidate class="space-y-5">@csrf
    <x-auth-field name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
    <x-auth-field name="password" label="Password" type="password" autocomplete="current-password" maxlength="128" />
    <a href="{{ route('password.request') }}" class="nav-link block text-sm underline">Lupa Password?</a>
    <x-button type="submit" class="w-full">Masuk</x-button>
</form>
<x-google-login />
<p class="mt-6 text-sm">Belum punya akun? <a class="font-semibold text-primary underline" href="{{ route('register') }}">Daftar</a></p>
@endsection
