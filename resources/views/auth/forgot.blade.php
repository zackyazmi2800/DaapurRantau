@extends('auth.shell')
@section('title', 'Lupa password — Dapur Rantau')
@section('heading', 'Lupa password?')
@section('intro', 'Masukkan email yang kamu gunakan untuk mendaftar. Kami akan mengirim link untuk membuat password baru. Jika mendaftar melalui Google, gunakan Masuk dengan Google.')
@section('form')
<form action="{{ route('password.email') }}" method="POST" novalidate class="space-y-5">@csrf
    <x-auth-field name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
    <x-button type="submit" class="w-full">Kirim Link Reset Password</x-button>
</form>
<a class="nav-link mt-5 inline-block text-sm underline" href="{{ route('login') }}">Kembali ke halaman masuk</a>
@endsection
