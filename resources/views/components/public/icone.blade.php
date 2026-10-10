@props(['nom'])

{{-- Icônes en trait (24×24), décoratives par défaut. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"
     {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }}>
    @switch($nom)
        @case('aide')
            <circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>
            @break
        @case('utilisateur')
            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
            @break
        @case('menu')
            <path d="M3 6h18M3 12h18M3 18h18"/>
            @break
        @case('fermer')
            <path d="M18 6 6 18M6 6l12 12"/>
            @break
        @case('fleche')
            <path d="M5 12h14M13 6l6 6-6 6"/>
            @break
        @case('chevron')
            <path d="m9 6 6 6-6 6"/>
            @break
        @case('telephone')
            <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>
            @break
        @case('courriel')
            <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>
            @break
        @case('horloge')
            <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
            @break
        @case('lieu')
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>
            @break
        @case('danger')
            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>
            @break
        @case('info')
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
            @break
        @case('check')
            <path d="M20 6 9 17l-5-5"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
    @endswitch
</svg>
