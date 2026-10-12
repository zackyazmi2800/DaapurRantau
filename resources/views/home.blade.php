@extends('layouts.app')

@section('content')
    <section id="beranda" aria-labelledby="hero-title" class="overflow-hidden bg-cream/60">
        <div class="page-container grid items-center gap-12 pt-12 pb-16 lg:grid-cols-2 lg:gap-14 lg:pt-18 lg:pb-20">
            <div>
                <p class="mb-6 inline-flex items-center gap-2 rounded-full border border-primary/15 bg-sage px-3.5 py-2 text-xs font-semibold text-primary"><x-icon name="leaf" class="size-4" /> Bahan sederhana, cerita rasa istimewa</p>
                <h1 id="hero-title" class="text-[2.7rem] leading-[1.13] font-extrabold tracking-[-0.045em] sm:text-6xl lg:text-[3rem]">Masak Apa Hari Ini?<br><span class="text-primary">Cek Bahanmu,<br>Temukan Resepnya!</span></h1>
                <p class="mt-6 max-w-lg text-base leading-7 text-muted">Nggak perlu bingung mau masak apa. Masukkan bahan yang kamu punya, biarkan Dapur Rantau bantu carikan ide masakan yang praktis dan hemat.</p>
                <div class="mt-8 flex flex-wrap items-center gap-5"><x-button href="#cari-resep">Cari Resep Sekarang <x-icon name="arrow" class="size-4" /></x-button><a href="#cara-kerja" class="inline-flex items-center gap-2 rounded-lg py-3 text-sm font-semibold hover:text-primary">Gimana caranya? <span aria-hidden="true">↗</span></a></div>
                <div class="mt-9 flex flex-wrap gap-x-5 gap-y-3 text-xs font-medium text-muted"><span class="flex items-center gap-1.5"><x-icon name="check" class="size-4 text-primary" /> Praktis buat anak kos</span><span class="flex items-center gap-1.5"><x-icon name="check" class="size-4 text-primary" /> Bersahabat di kantong</span></div>
            </div>
            <div class="relative mx-auto w-full max-w-lg px-3 pb-6 lg:px-0">
                <div class="absolute -top-6 -right-12 size-48 rounded-full border border-primary/10" aria-hidden="true"></div>
                <div class="absolute top-0 -right-6 size-48 rounded-full border border-primary/10" aria-hidden="true"></div>
                <div class="relative aspect-[1.03] overflow-hidden rounded-[2.5rem] bg-sage shadow-soft lg:rounded-tl-[6rem] lg:rounded-br-[6rem]">
                    <img src="{{ asset('images/nasi-rumahan.jpg') }}" alt="Sepiring nasi dengan lauk dan sayuran, inspirasi hidangan sederhana di rumah" width="1400" height="1400" fetchpriority="high" class="size-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-ink/45 via-transparent to-transparent"></div>
                    <p class="absolute right-7 bottom-9 left-7 text-sm font-medium text-white">Sesederhana bahannya.<br><span class="text-2xl font-bold tracking-tight">Sehangat rasa rumah.</span></p>
                </div>
                <div class="absolute top-6 -left-1 flex items-center gap-3 rounded-2xl border border-line bg-white p-3 pr-5 shadow-soft sm:-left-5"><span class="rounded-xl bg-sage p-2.5 text-primary"><x-icon name="wallet" class="size-5" /></span><div><p class="text-xs text-muted">Masak sendiri</p><p class="text-sm font-bold">Lebih hemat, lebih nikmat</p></div></div>
                <div class="absolute right-0 bottom-0 flex items-center gap-3 rounded-2xl border border-line bg-white px-5 py-4 shadow-soft sm:-right-4"><span class="flex size-10 items-center justify-center rounded-full bg-accent/20 text-orange-800"><x-icon name="heart" class="size-5" /></span><div><p class="text-sm font-bold">Dari dapur kecilmu</p><p class="mt-0.5 text-xs text-muted">Untuk hari yang lebih hangat</p></div></div>
            </div>
        </div>
    </section>

    <section id="cari-resep" aria-labelledby="ingredient-title" class="page-container scroll-mt-8 py-16 sm:py-20">
        <div class="mx-auto max-w-3xl text-center"><p class="eyebrow">MULAI DARI DAPURMU</p><h2 id="ingredient-title" class="section-title">Bahan apa yang kamu punya?</h2><p class="mt-4 text-sm leading-7 text-muted sm:text-base">Cek kulkas, lihat isi dapur. Bahan sederhana bisa jadi masakan istimewa.</p></div>
        <div class="mx-auto mt-8 max-w-3xl rounded-3xl border border-line bg-white p-5 shadow-soft sm:p-8">
            <x-ingredient-input />
        </div>
    </section>

    <section id="cara-kerja" aria-labelledby="steps-title" class="scroll-mt-8 bg-cream/60 py-16 sm:py-20">
        <div class="page-container"><div class="text-center"><p class="eyebrow">NGGAK PERLU RIBET</p><h2 id="steps-title" class="section-title">Dari bahan ke hidangan, dalam 3 langkah</h2><p class="mt-4 text-sm text-muted">Begini cara Dapur Rantau akan menemani kamu memasak.</p></div>
            <ol class="mt-12 grid gap-9 md:grid-cols-3 md:gap-12">
                @foreach ([['leaf', 'Masukkan bahan', 'Tulis bahan yang ada di kulkas atau dapurmu. Mulai dari apa yang kamu punya.'], ['sparkles', 'AI mencari ide masakan', 'Dapur Rantau akan membantu menemukan ide resep yang sesuai dengan bahanmu.'], ['bowl', 'Pilih resep dan mulai memasak', 'Pilih yang bikin kamu lapar, ikuti langkahnya, dan nikmati masakan sendiri.']] as [$icon, $title, $description])
                    <li class="relative text-center"><div class="relative mx-auto mb-6 flex size-18 items-center justify-center rounded-3xl border border-primary/10 bg-white text-primary shadow-sm"><x-icon :name="$icon" class="size-8" /><span class="absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full bg-accent text-xs font-bold text-ink">{{ $loop->iteration }}</span></div><h3 class="text-lg font-bold">{{ $title }}</h3><p class="mx-auto mt-3 max-w-xs text-sm leading-7 text-muted">{{ $description }}</p></li>
                @endforeach
            </ol>
        </div>
    </section>

@endsection
