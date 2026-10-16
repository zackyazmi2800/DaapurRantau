@extends('layouts.app')
@section('content')
<section class="page-container py-12 sm:py-16">
    <div class="mx-auto max-w-lg rounded-3xl border border-line bg-cream/50 p-6 shadow-soft sm:p-8">
        <p class="eyebrow">AKUN DAPUR RANTAU</p>
        <h1 class="section-title">@yield('heading')</h1>
        <p class="mt-4 text-sm leading-7 text-muted">@yield('intro')</p>
        @if($errors->any())
            <div id="auth-errors" role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Periksa kembali isianmu</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->getMessages() as $field => $messages)
                        @foreach(array_unique($messages) as $message)
                            <li data-error-field="{{ $field }}">{{ $message }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="mt-6">@yield('form')</div>
    </div>
</section>
@endsection
