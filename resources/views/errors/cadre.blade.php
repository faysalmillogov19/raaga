{{--
    Cadre des pages d'erreur (404, 403, 419, 500, 503). Autonome : il n'utilise ni la session ni le
    menu, et chaque paramètre de la plateforme est lu avec un repli, pour s'afficher même quand la
    base de données est indisponible (cas typique d'une erreur 500).
    Variables : $code, $titre, $message ; section « complement » facultative.
--}}
@php
    use App\Support\ImagesPlateforme;

    $nom = rescue(fn () => plateforme('identite.nomPlateforme'), null, false) ?: 'Raaga';
    $armoiries = rescue(fn () => ImagesPlateforme::url('armoiries'), null, false) ?: asset(ImagesPlateforme::DEFAUTS['armoiries'][0]);
    $numeroVert = rescue(fn () => plateforme('contacts.numeroVert'), null, false);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $titre }} · {{ $nom }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css'])
</head>
<body class="site-public flex min-h-screen flex-col bg-white font-public text-texte antialiased">
    <div class="bande-tricolore" aria-hidden="true"></div>

    <header class="border-b border-gris-clair">
        <div class="conteneur flex min-h-[72px] items-center">
            {{-- Comme sur le site : armoiries seules dans l'en-tête. --}}
            <a href="{{ url('/') }}" class="inline-flex min-h-[44px] items-center">
                <img src="{{ $armoiries }}" alt="{{ __('public.erreurs.accueil_alt', ['nom' => $nom]) }}" class="h-12 w-auto">
            </a>
        </div>
    </header>

    <main id="contenu" class="flex flex-1 items-center">
        <div class="conteneur max-w-2xl py-16 text-center">
            <p class="text-sm font-bold uppercase tracking-wide text-vert-fonce">{{ __('public.erreurs.code', ['code' => $code]) }}</p>
            <h1 class="mt-3">{{ $titre }}</h1>
            <p class="mt-4 text-lg leading-relaxed">{{ $message }}</p>

            @yield('complement')

            <div class="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                <a href="{{ url('/') }}" class="btn-primaire">{{ __('public.erreurs.retour_accueil') }}</a>
                <a href="{{ url('/faq') }}" class="btn-secondaire">{{ __('public.erreurs.aide') }}</a>
            </div>

            @if ($numeroVert)
                <p class="mt-8 text-gris">
                    {{ __('public.communs.numero_vert') }} :
                    <a href="tel:{{ preg_replace('/\s+/', '', $numeroVert) }}" class="lien-fort">{{ rescue(fn () => \App\Support\Telephone::formaterLocal($numeroVert), $numeroVert, false) }}</a>
                </p>
            @endif
        </div>
    </main>

    <div class="bande-tricolore" aria-hidden="true"></div>
</body>
</html>
