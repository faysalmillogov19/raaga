@props(['name'])

{{--
    Icônes du projet : tracé au trait (24×24, trait 2), décoratives par défaut (aria-hidden).
    Quand l'icône porte du sens, la donner avec un texte (visible ou sr-only) à côté.
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"
     {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }}>
    @switch($name)
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
        @case('modifier')
            <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
            @break
        @case('supprimer')
            <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>
            @break
        @case('voir')
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>
            @break
        @case('tableau') <rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/> @break
        @case('tranches') <path d="M3 7h18M3 12h18M3 17h18"/><path d="M7 4v16"/> @break
        @case('dividendes') <circle cx='12' cy='12' r='9'/><path d='M12 7v10M9.5 9.5h4a1.75 1.75 0 0 1 0 3.5h-3a1.75 1.75 0 0 0 0 3.5h4'/> @break
        @case('simulations') <path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/> @break
        @case('kyc') <rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M14 10h4M14 14h4M6 16c.8-1.5 2-2 3-2s2.2.5 3 2"/> @break
        @case('conformite') <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="M12 8v5M12 16h.01"/> @break
        @case('souscriptions') <rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20M6 15h4"/> @break
        @case('remboursements') <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 8v8M9.5 10.5h4a1.5 1.5 0 0 1 0 3h-3a1.5 1.5 0 0 0 0 3h4"/> @break
        @case('registre') <path d="M4 4h12a4 4 0 0 1 4 4v12H8a4 4 0 0 1-4-4z"/><path d="M8 9h8M8 13h8"/> @break
        @case('audit') <path d="M9 3h6l1 2h3v16H5V5h3z"/><path d="m9 13 2 2 4-4"/> @break
        @case('regulation') <path d="M12 3 3 8h18z"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/> @break
        @case('observations') <path d="M21 12a8 8 0 0 1-11.7 7.1L3 21l1.9-6.3A8 8 0 1 1 21 12z"/> @break
        @case('utilisateurs') <circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/> @break
        @case('connexions') <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/> @break
        @case('referentiels') <path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2"/> @break
        @case('assistance') <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m5.6 5.6 3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/> @break
        @case('demandes') <path d="M4 4h16v12H8l-4 4z"/><path d="M8 9h8M8 12h5"/> @break
        @case('contenus') <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/> @break
        @case('approbations') <path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/> @break
        @case('securite') <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><rect x="9" y="10.5" width="6" height="5" rx="1"/><path d="M10.5 10.5V9a1.5 1.5 0 0 1 3 0v1.5"/> @break
        @case('parametres') <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/> @break
        @case('plateforme') <rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M7 9h4M7 12h7"/><circle cx="16.5" cy="9" r="1"/> @break
        @case('cadenas') <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/> @break
        @case('institutions') <path d="M3 21h18M5 21V10M19 21V10M9 21v-7h6v7"/><path d="M12 3 3 8h18z"/> @break
        @case('boutiques') <path d="M3 9 4.5 4h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 11.5V20h14v-8.5M10 20v-5h4v5"/> @break
        @case('conventions') <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h3"/><path d="m14 17 1.5 1.5L19 15"/> @break
        @case('cloche') <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/> @break
        @case('sortie') <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/> @break
        @case('repli') <path d="m15 18-6-6 6-6"/> @break
        @case('recherche') <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/> @break
        @case('lune') <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/> @break
        @case('soleil') <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/> @break
        @case('importer') <path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 20h16"/> @break
        @case('exporter') <path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/> @break
        @case('taches') <rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8 12 3 3 5-6"/> @break
        @case('pause') <path d="M9 5v14M15 5v14"/> @break
        @case('piece-pousse') <circle cx="10" cy="15" r="6"/><path d="M10 12.5v5M8.5 14h2.5a1 1 0 0 1 0 2H9"/><path d="M16 9.5c0-3 1.8-5.5 5-5.5 0 3-1.8 5.5-5 5.5z"/><path d="M16 9.5V12"/> @break
        @case('boutique-auvent') <path d="M3 10 4.5 4h15L21 10"/><path d="M3 10a2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0 2.25 2.25 0 0 0 4.5 0"/><path d="M5 12.5V20h14v-7.5M10 20v-5h4v5"/> @break
        @case('parking') <rect x="4" y="3" width="16" height="18" rx="3"/><path d="M10 17V7h3a3 3 0 0 1 0 6h-3"/> @break
        @case('services') <rect x="3" y="8" width="18" height="12" rx="2"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18M11 13v2h2v-2"/> @break
        @case('lecture') <path d="M7 4.5v15l12-7.5z"/> @break
        @case('poignee') <path d="M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01" stroke-width="3"/> @break
    @endswitch
</svg>
