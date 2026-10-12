@props(['steps'])
<section data-cooking-guide aria-labelledby="steps-title">
    <p class="eyebrow">SAATNYA MASAK</p>
    <h2 id="steps-title" class="mt-2 text-2xl font-bold">Langkah memasak</h2>
    <div class="mt-5 rounded-2xl bg-cream p-5">
        <p data-preparation-count class="font-semibold" role="status">Periksa checklist bahan terlebih dahulu.</p>
        <progress data-preparation-progress max="1" value="0" aria-label="Progres persiapan bahan wajib" class="mt-3 h-3 w-full accent-primary"></progress>
        <ul data-preparation-missing class="mt-3 list-inside list-disc text-sm"></ul>
    </div>
    <div data-cooking-locked class="mt-5 rounded-2xl border border-line p-5" role="status">
        <p class="font-semibold">Lengkapi bahan terlebih dahulu untuk melihat langkah memasak.</p>
        <p data-cooking-missing class="mt-2 text-sm text-muted"></p>
    </div>
    <div data-cooking-unlocked hidden>
        <p class="mt-5 font-semibold text-primary">Semua bahan sudah siap. Yuk, mulai masak!</p>
        @if($steps)
        <div data-cooking-active>
            <p data-cooking-counter role="status" class="mt-4 text-sm text-muted"></p>
            <progress data-cooking-progress max="{{ count($steps) }}" value="1" aria-label="Progres langkah memasak" class="mt-2 h-3 w-full accent-primary"></progress>
            @foreach($steps as $step)
                <article data-cooking-step @if(!$loop->first) hidden @endif class="mt-5 rounded-2xl border border-line bg-white p-5 shadow-soft sm:p-7">
                    <h3 tabindex="-1" class="text-xl font-bold break-words">{{ $step['step_number'] }}. {{ $step['title'] }}</h3>
                    @if($step['duration_minutes'])<p class="mt-2 text-sm text-muted">Sekitar {{ $step['duration_minutes'] }} menit</p>@endif
                    <p class="mt-4 whitespace-pre-line leading-8 break-words">{{ $step['instruction'] }}</p>
                    @if($step['cooking_tip'])<aside class="mt-5 rounded-xl bg-cream p-4"><h4 class="font-bold text-primary">Tips langkah ini</h4><p class="mt-2 leading-7 break-words">{{ $step['cooking_tip'] }}</p></aside>@endif
                </article>
            @endforeach
            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:justify-between">
                <x-button data-cooking-previous variant="secondary">Langkah Sebelumnya</x-button>
                <x-button data-cooking-next>Langkah Selanjutnya</x-button>
            </div>
        </div>
        <div data-cooking-finished hidden class="mt-5 rounded-2xl bg-cream p-6">
            <h3 tabindex="-1" class="text-xl font-bold">Masakanmu sudah selesai. Selamat menikmati!</h3>
            <div class="mt-5 flex flex-wrap gap-3"><x-button data-cooking-restart>Ulangi Panduan</x-button><x-button variant="secondary" href="{{ route('home') }}#cari-resep">Lihat Resep Lain</x-button></div>
        </div>
        @else<p class="mt-5">Langkah memasak belum tersedia pada resep ini.</p>@endif
    </div>
    <noscript><p class="mt-4">Aktifkan JavaScript untuk membuka panduan setelah memeriksa bahan wajib.</p></noscript>
</section>
