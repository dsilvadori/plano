<button type="button" {{ $attributes->class('theme-toggle') }} data-theme-toggle aria-label="Alternar tema" aria-pressed="false">
    <span class="theme-toggle-label theme-toggle-label-dark">Tema escuro</span>
    <span class="theme-toggle-label theme-toggle-label-light">Tema claro</span>
    <span class="theme-toggle-track" aria-hidden="true">
        <span class="theme-toggle-thumb">
            <svg class="theme-toggle-icon theme-toggle-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1.5m0 13V20m8-8h-1.5M5.5 12H4m13.66-5.66-1.06 1.06M7.4 16.6l-1.06 1.06m11.32 0-1.06-1.06M7.4 7.4 6.34 6.34M15.5 12A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5Z" />
            </svg>
            <svg class="theme-toggle-icon theme-toggle-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 14.2A7.5 7.5 0 0 1 9.8 3 9 9 0 1 0 21 14.2Z" />
            </svg>
        </span>
    </span>
</button>
