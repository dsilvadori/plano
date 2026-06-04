@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100 placeholder:text-slate-500 focus:border-amber-300 focus:ring-amber-300']) }}>
