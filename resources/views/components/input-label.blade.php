@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-slate-200']) }}>
    {{ $value ?? $slot }}
</label>
