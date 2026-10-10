@props(['nom'])

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"
     {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }}>
    @switch($nom)
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
        @case('conventions') <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h3"/><path d="m14 17 1.5 1.5L19 15"/> @break
        @case('cloche') <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/> @break
        @case('sortie') <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/> @break
        @case('repli') <path d="m15 18-6-6 6-6"/> @break
        @case('check') <path d="M20 6 9 17l-5-5"/> @break
    @endswitch
</svg>
