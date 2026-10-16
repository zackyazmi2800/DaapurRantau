@extends('auth.shell')
@section('title', 'Verifikasi email — Dapur Rantau')
@section('heading', 'Periksa emailmu')
@section('intro', 'Kode berlaku 5 menit dan hanya bisa digunakan sekali. Maksimal 5 percobaan untuk setiap kode.')
@section('form')
<form action="{{ route('verification.verify') }}" method="POST" class="space-y-5">@csrf
    <label for="otp" class="block text-sm font-semibold">Kode OTP 6 digit</label>
    <input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required class="w-full rounded-xl border border-line bg-white px-4 py-3" @error('otp') aria-invalid="true" @enderror>
    <x-button type="submit" class="w-full">Verifikasi email</x-button>
</form>
<form action="{{ route('verification.send') }}" method="POST" class="mt-5">@csrf
    <x-button type="submit" variant="secondary" class="w-full">Kirim ulang kode</x-button>
</form>
<p class="mt-3 text-sm text-muted">Tunggu 60 detik sebelum mengirim ulang. Periksa folder spam juga.</p>
<a href="{{ route('login') }}" class="nav-link mt-4 inline-block text-sm underline">Kembali ke halaman masuk</a>
@endsection
