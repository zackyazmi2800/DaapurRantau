@props(['search' => null])
@php
    // Consume temporary presentation messages once; validation bags stay untouched.
    $status = session()->pull('status');
    $statusType = session()->pull('status_type', 'success');
    if (is_string($status) && (str_starts_with($status, 'Jika ') || str_starts_with($status, 'Kode '))) {
        $statusType = 'info';
    } elseif (is_string($status) && (str_starts_with($status, 'Sesi ') || str_starts_with($status, 'Verifikasi email sebelum'))) {
        $statusType = 'warning';
    }
@endphp
<div data-toast-system data-guest="{{ auth()->guest() ? 'true' : 'false' }}" data-home="{{ request()->routeIs('home') ? 'true' : 'false' }}">
    <div data-toast-stack aria-label="Pemberitahuan sementara" class="pointer-events-none fixed inset-x-4 top-[calc(6.5rem+env(safe-area-inset-top))] z-30 flex max-h-[calc(100dvh-8rem)] flex-col gap-3 overflow-y-auto p-1 sm:right-6 sm:left-auto sm:w-96 sm:max-w-[calc(100vw-3rem)]"></div>
    @if(is_string($status) && $status !== '')
        <x-toast-message :message="$status" :type="$statusType" :notification-id="(string) \Illuminate\Support\Str::uuid()" />
        <noscript><p role="status" class="page-container py-4 text-sm text-primary">{{ $status }}</p></noscript>
    @endif
    <x-guest-quota :search="$search" />
</div>
