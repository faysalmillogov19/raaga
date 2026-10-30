@props(['titre' => null, 'description' => null, 'portail' => null, 'sombre' => true])

@php
    // RAAGA Invest : nom du portail sur les seules pages d'investissement ; ailleurs, le nom de la plateforme.
    $portail ??= request()->routeIs(...config('raaga.invest.routes')) ? config('raaga.invest.nom') : null;
    $nom = $portail ?? plateforme('identite.nomPlateforme');
    // Thème « nuit » (config raaga.theme_public) : habillage et contenu en fond sombre (« sombre », vrai par défaut ;
    // une page peut le refuser). Thème « clair » : rendu historique, inchangé.
    $nuit = \App\Support\ThemePublic::nuit();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $description ?? plateforme('identite.sousTitre') }}">

    <title>{{ collect([$titre, $nom])->filter()->implode(' · ') }}</title>

    <x-meta-icones />
    <link rel="preload" href="{{ asset('fonts/public-sans-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/public-sans-700.woff2') }}" as="font" type="font/woff2" crossorigin>

    {{-- Partage sur les réseaux sociaux. --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ collect([$titre, $nom])->filter()->implode(' · ') }}">
    <meta property="og:description" content="{{ $description ?? plateforme('identite.sousTitre') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('images/og-raaga.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/og-raaga.png') }}">

    {{ $head ?? '' }}
    <x-assets />
</head>
<body @class(['site-public flex min-h-screen flex-col bg-white font-public text-texte antialiased', 'theme-nuit' => $nuit, 'theme-lumiere' => \App\Support\ThemePublic::lumiere()])>
    <a href="#contenu" class="sr-only z-50 bg-jaune font-semibold text-encre focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:px-4 focus:py-3">
        {{ __('public.site.aller_au_contenu') }}
    </a>

    <x-public.bande-tricolore />

    <x-public.bandeau />

    @if ($nuit)
        <x-public.entete-nuit />
    @else
        <x-public.entete />
    @endif
    @if ($portail)
        <div class="portail-invest" data-portail>
            <p class="conteneur py-2 font-extrabold tracking-wide">{{ $portail }}</p>
        </div>
    @endif

    <main id="contenu" @class(['flex-1', 'nuit-page' => $nuit && $sombre]) tabindex="-1">
        {{-- Page sans fil d'Ariane (espace…) : boutons Retour et Suivant en haut du contenu ; jamais sur l'accueil ni sur les
             pages d'authentification (connexion, inscription), depuis le 30/10/2026. --}}
        @unless (\App\Support\NavigationHistorique::placee() || request()->routeIs('accueil', 'connexion', 'connexion.*', 'inscription'))
            <div class="conteneur pt-4"><x-navigation-historique :parent="route('accueil')" /></div>
        @endunless
        <x-public.encadre-demarche />
        {{ $slot }}
    </main>

    <x-public.bande-tricolore />
    <x-public.pied />
    <x-notification />
</body>
</html>
