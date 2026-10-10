@props(['titre', 'fil' => []])

{{-- Espace investisseurs institutionnels : identité visuelle du site (couleurs, police, bande tricolore), mise en page d'application. --}}
@php
    $user = Auth::user();
    $institution = $user->institution;
    $menu = [
        'tableau' => ['institutionnel.tableau', 'tableau'],
        'convention' => ['institutionnel.convention', 'conventions'],
        'appels' => ['institutionnel.appels', 'souscriptions'],
        'actions' => ['institutionnel.actions', 'registre'],
        'dividendes' => ['institutionnel.dividendes', 'dividendes'],
        'projet' => ['institutionnel.projet', 'simulations'],
        'assemblees' => ['institutionnel.assemblees', 'institutions'],
        'organisation' => ['institutionnel.organisation', 'kyc'],
        'contact' => ['institutionnel.contact', 'observations'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titre }} · {{ __('institutionnel.nom') }}</title>
    <x-meta-icones />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-public bo font-public">
    <x-public.bande-tricolore />

    <div class="bo-cadre" x-data="menuBackoffice" :class="{ 'bo-replie': replie }">
        <aside class="bo-lateral">
            <div class="bo-marque">
                <span class="inline-flex shrink-0 rounded-[6px] bg-white p-1"><x-logo variante="symbole" class="h-7" :alt="plateforme('identite.nomPlateforme')" /></span>
                <span class="bo-marque-texte">{{ $institution->nomCourt() }}</span>
            </div>

            <nav aria-label="{{ __('admin.menu_principal') }}">
                <p class="bo-groupe">{{ __('institutionnel.nom') }}</p>
                <ul>
                    @foreach ($menu as $cle => [$route, $icone])
                        @php($actif = request()->routeIs($route, $route.'.*'))
                        <li>
                            <a href="{{ route($route) }}" @class(['bo-lien', 'bo-lien-actif' => $actif])
                               @if ($actif) aria-current="page" @endif title="{{ __('institutionnel.menu.'.$cle) }}">
                                <x-admin.icone :nom="$icone" />
                                <span class="bo-lien-texte">{{ __('institutionnel.menu.'.$cle) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <button type="button" class="bo-replier" @click="basculer()"
                    :aria-label="replie ? @js(__('admin.deplier')) : @js(__('admin.replier'))" aria-label="{{ __('admin.replier') }}">
                <x-admin.icone nom="repli" class="h-5 w-5 transition-transform" ::class="replie && 'rotate-180'" />
                <span class="bo-lien-texte">{{ __('admin.replier') }}</span>
            </button>
        </aside>

        <div class="bo-principal">
            <header class="bo-haut">
                <nav aria-label="{{ __('admin.fil') }}" class="bo-fil">
                    <ol>
                        <li><a href="{{ route('institutionnel.tableau') }}">{{ $institution->nomCourt() }}</a></li>
                        @foreach ($fil as $element)
                            <li><span aria-hidden="true">›</span>
                                @if (! empty($element['url']) && ! $loop->last)
                                    <a href="{{ $element['url'] }}">{{ $element['libelle'] }}</a>
                                @else
                                    <span @if ($loop->last) aria-current="page" @endif>{{ $element['libelle'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>

                <div class="bo-compte">
                    <span class="bo-nom">{{ $user->name }}</span>
                    <span class="bo-badge bo-badge-regulateur">{{ mb_strtoupper(__('institutionnel.statuts_role.'.$user->codeRole()->value)) }}</span>
                    <form method="POST" action="{{ route('institutionnel.deconnexion') }}">
                        @csrf
                        <button type="submit" class="bo-icone-bouton" title="{{ __('admin.se_deconnecter') }}" aria-label="{{ __('admin.se_deconnecter') }}">
                            <x-admin.icone nom="sortie" />
                        </button>
                    </form>
                </div>
            </header>

            <main id="contenu" class="bo-contenu">
                <h1 class="bo-titre">{{ $titre }}</h1>

                @if (session('succes'))
                    <p class="bo-message" role="status">{{ session('succes') }}</p>
                @endif
                @if (session('erreur'))
                    <p class="bo-message bo-message-erreur" role="alert">{{ session('erreur') }}</p>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
