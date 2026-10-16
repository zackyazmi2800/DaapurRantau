@props(['search' => null])
@guest
    @if(request()->routeIs('recommendations.show') && is_array($search) && !empty($search['recipes']))
        @php
            $remaining = app(\App\Services\RecommendationOperationService::class)->remaining(request());
            // UI-only deduplication; never modifies quota or operation data.
            $noticeId = 'quota:'.$search['id'];
            $seen = session()->get('ui.quota_notices', []);
            $showNotice = !in_array($noticeId, $seen, true);
            if ($showNotice) {
                session()->put('ui.quota_notices', array_slice([...$seen, $noticeId], -100));
            }
        @endphp
        @if($showNotice)
            <x-toast-message :notification-id="$noticeId" type="info" :message="'Sisa pencarian gratis: '.$remaining.' dari 3 pencarian berhasil.'" />
            @if($remaining === 0)
                <x-toast-message :notification-id="$noticeId.':empty'" type="warning" message="Kuota gratis sudah habis. Masuk atau daftar untuk melanjutkan pencarian." :account-links="true" />
            @endif
        @endif
    @endif
@endguest
