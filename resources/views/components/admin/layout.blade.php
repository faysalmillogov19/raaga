@props(['titre', 'fil' => []])

@php
    $user = Auth::user();
    $role = $user->codeRole();
    $menu = \App\Support\MenuBackoffice::pour($user);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titre }} · {{ __('admin.nom') }}</title>
    <x-meta-icones />
    <link rel="preload" href="{{ asset('fonts/public-sans-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/public-sans-700.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="site-public bo font-public">
    <x-public.bande-tricolore />

    <div class="bo-cadre" x-data="menuBackoffice" :class="{ 'bo-replie': replie }">
        {{-- Barre latérale --}}
        <aside class="bo-lateral">
            <div class="bo-marque">
                <x-public.armoiries class="h-9 w-auto" />
                <span class="bo-marque-texte">{{ __('admin.nom') }}</span>
            </div>

            <nav aria-label="{{ __('admin.menu_principal') }}">
                @foreach ($menu as $groupe => $entrees)
                    <p class="bo-groupe">{{ __('admin.groupes.'.$groupe) }}</p>
                    <ul>
                        @foreach ($entrees as $entree)
                            @php($actif = request()->routeIs($entree['route'], $entree['route'].'.*'))
                            <li>
                                <a href="{{ route($entree['route']) }}" @class(['bo-lien', 'bo-lien-actif' => $actif])
                                   @if ($actif) aria-current="page" @endif title="{{ __('admin.menu.'.$entree['cle']) }}">
                                    <x-admin.icone :nom="$entree['icone']" />
                                    <span class="bo-lien-texte">{{ __('admin.menu.'.$entree['cle']) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </nav>

            <button type="button" class="bo-replier" @click="basculer()"
                    :aria-label="replie ? @js(__('admin.deplier')) : @js(__('admin.replier'))" aria-label="{{ __('admin.replier') }}">
                <x-admin.icone nom="repli" class="h-5 w-5 transition-transform" ::class="replie && 'rotate-180'" />
                <span class="bo-lien-texte">{{ __('admin.replier') }}</span>
            </button>
        </aside>

        <div class="bo-principal">
            {{-- Barre supérieure --}}
            <header class="bo-haut">
                <nav aria-label="{{ __('admin.fil') }}" class="bo-fil">
                    <ol>
                        <li><a href="{{ route('admin.tableau') }}">{{ __('admin.accueil') }}</a></li>
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
                    <a href="{{ route('admin.profil') }}" class="bo-nom underline-offset-2 hover:underline" title="{{ __('admin.profil.titre') }}">{{ $user->name }}</a>
                    <span class="bo-badge {{ $role->badgeClasse() }}">{{ $role->badge() }}</span>

                    <a href="{{ route('admin.profil') }}" class="bo-icone-bouton" aria-label="{{ __('admin.profil.titre') }}" title="{{ __('admin.profil.titre') }}">
                        <x-public.icone nom="utilisateur" />
                    </a>

                    <a href="{{ Gate::allows('observations.voir') ? route('admin.observations') : '#' }}" class="bo-icone-bouton"
                       aria-label="{{ __('admin.notifications') }} : {{ __('admin.aucune_notification') }}" title="{{ __('admin.notifications') }}">
                        <x-admin.icone nom="cloche" />
                    </a>

                    <form method="POST" action="{{ route('admin.deconnexion') }}">
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
