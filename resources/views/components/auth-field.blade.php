@props(['name', 'label', 'type' => 'text', 'value' => null])
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($errors->has($name) ? ' auth-errors' : '')))
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($type !== 'password') value="{{ $value ?? old($name) }}" @endif
        @error($name) aria-invalid="true" @enderror
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('aria-describedby')->merge(['required' => true])->class('w-full rounded-xl border border-line bg-white px-4 py-3 text-ink') }}>
    @if($type === 'password')
        <button type="button" hidden data-password-toggle aria-controls="{{ $name }}" aria-pressed="false" class="mt-2 min-h-11 rounded-lg px-2 text-sm font-semibold text-primary hover:bg-sage">Tampilkan {{ strtolower($label) }}</button>
    @endif
</div>
