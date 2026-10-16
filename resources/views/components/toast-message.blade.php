@props(['message', 'type' => 'info', 'notificationId', 'accountLinks' => false])
<template data-toast data-toast-id="{{ $notificationId }}" data-toast-type="{{ $type }}">
    <p class="text-sm leading-6 wrap-anywhere">{{ $message }}</p>
    @if($accountLinks)
        <div class="mt-2 flex flex-wrap gap-4 text-sm font-semibold">
            <a href="{{ route('login') }}" class="rounded underline">Masuk</a>
            <a href="{{ route('register') }}" class="rounded underline">Daftar</a>
        </div>
    @endif
</template>
