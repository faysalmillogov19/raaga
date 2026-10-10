@props(['titre', 'intro' => null, 'espace' => \App\Enums\Espace::Backoffice])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titre }} · {{ $espace->nom() }}</title>
    <x-meta-icones />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-public bo font-public">
    <x-public.bande-tricolore />

    <main class="compte-fond min-h-[calc(100vh-5px)]">
        <div class="compte-carte">
            <div class="flex items-center gap-3">
                <x-public.armoiries class="compte-armoiries !mb-0" />
                <span class="font-extrabold text-vert-fonce">{{ $espace->nom() }}</span>
            </div>
            <h1 class="mt-5">{{ $titre }}</h1>
            @if ($intro)
                <p class="compte-intro">{{ $intro }}</p>
            @endif

            @if (session('statut'))
                <p class="alerte-compte alerte-compte-info" role="status">{{ session('statut') }}</p>
            @endif

            {{ $slot }}
        </div>

        <p class="compte-aide"><a href="{{ route('accueil') }}">{{ __('admin.connexion.site_public') }}</a></p>
    </main>
</body>
</html>
