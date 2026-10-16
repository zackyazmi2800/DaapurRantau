@extends('auth.shell')
@section('title', 'Daftar — Dapur Rantau')
@section('heading', 'Buat akunmu')
@section('intro', 'Verifikasi email dengan kode 6 digit sebelum menggunakan fitur akun.')
@section('form')
<form action="{{ route('register.store') }}" method="POST" data-password-rules data-password-byte-limit="{{ config('hashing.driver', 'bcrypt') === 'bcrypt' ? 72 : 0 }}" novalidate class="space-y-5">@csrf
    <x-auth-field name="name" label="Nama" autocomplete="name" maxlength="100" />
    <x-auth-field name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
    <x-auth-field name="password" label="Password" type="password" autocomplete="new-password" minlength="8" aria-describedby="password-help" />
    <p id="password-help" class="text-sm text-muted">Password minimal 8 karakter, maksimal 64 karakter.</p>
    <x-auth-field name="password_confirmation" label="Konfirmasi password" type="password" autocomplete="new-password"  />
    <p data-password-feedback role="status" aria-live="polite" aria-atomic="true" class="text-sm text-muted">Password minimal 8 karakter. Konfirmasi password harus sama.</p>
    <x-button type="submit" class="w-full">Daftar dan kirim kode</x-button>
</form>
<x-google-login />
<p class="mt-6 text-sm">Sudah punya akun? <a class="font-semibold text-primary underline" href="{{ route('login') }}">Masuk</a></p>
@endsection
