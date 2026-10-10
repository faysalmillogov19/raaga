@props(['titre' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $description ?? plateforme('identite.sousTitre') }}">
    <meta name="theme-color" content="#005C2B">

    <title>{{ collect([$titre, plateforme('identite.nomPlateforme')])->filter()->implode(' · ') }}</title>

    <link rel="icon" href="{{ \App\Support\Armoiries::url() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-public flex min-h-screen flex-col bg-white font-public text-texte antialiased">
    <a href="#contenu" class="sr-only z-50 bg-jaune font-semibold text-encre focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:px-4 focus:py-3">
        {{ __('public.site.aller_au_contenu') }}
    </a>

    <x-public.bande-tricolore />

    <x-public.bandeau />

    <x-public.entete />

    <main id="contenu" class="flex-1" tabindex="-1">
        {{ $slot }}
    </main>

    <x-public.bande-tricolore />
    <x-public.pied />
</body>
</html>
