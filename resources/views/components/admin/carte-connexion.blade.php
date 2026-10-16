@props(['titre', 'intro' => null, 'espace' => \App\Enums\Espace::Backoffice])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titre }} · {{ $espace->nom() }}</title>
    <x-meta-icones />
    <link rel="preload" href="{{ asset('fonts/public-sans-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/public-sans-700.woff2') }}" as="font" type="font/woff2" crossorigin>
    <x-assets />
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
