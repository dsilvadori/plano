<button wire:click="toggle" type="button" class="inline-flex w-full min-w-[170px] items-center justify-center rounded-2xl px-4 py-3 text-sm font-semibold transition lg:w-auto {{ $item->completed_at ? 'bg-emerald-400/15 text-emerald-200 ring-1 ring-emerald-400/20' : 'bg-white/10 text-slate-100 ring-1 ring-white/10' }}">
    {{ $item->completed_at ? '✓ Concluída' : 'Marcar concluída' }}
</button>
