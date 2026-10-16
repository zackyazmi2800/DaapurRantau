@extends('auth.shell')
@section('title', 'Reset password — Dapur Rantau')
@section('heading', 'Buat password baru')
@section('intro', 'Password minimal 8 karakter, maksimal 64 karakter. Reset password tidak menggantikan verifikasi email.')
@section('form')
<form action="{{ route('password.update') }}" method="POST" data-password-rules data-password-byte-limit="{{ config('hashing.driver', 'bcrypt') === 'bcrypt' ? 72 : 0 }}" novalidate class="space-y-5">@csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <x-auth-field name="email" label="Email" type="email" :value="$email" autocomplete="email" maxlength="255" />
    <x-auth-field name="password" label="Password baru" type="password" autocomplete="new-password" minlength="8" />
    <x-auth-field name="password_confirmation" label="Konfirmasi password" type="password" autocomplete="new-password"  />
    <p data-password-feedback role="status" aria-live="polite" aria-atomic="true" class="text-sm text-muted">Password minimal 8 karakter. Konfirmasi password harus sama.</p>
    <x-button type="submit" class="w-full">Simpan Password Baru</x-button>
</form>
<p class="mt-5 text-sm text-muted">Link tidak berlaku? <a href="{{ route('password.request') }}" class="nav-link underline">Minta link reset baru</a>.</p>
<a class="nav-link mt-3 inline-block text-sm underline" href="{{ route('login') }}">Kembali ke halaman masuk</a>
@endsection
