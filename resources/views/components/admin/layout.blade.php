@props(['titre', 'fil' => [], 'sousTitre' => null])

{{--
    Cadre unique des pages /admin : bandeau tricolore, barre latérale (groupes, liens, compteurs), barre du haut
    (menu, recherche globale, actions en attente, notifications, thème, profil), puis le contenu : fil d'Ariane,
    titre, sous-titre et actions (slot « actions »), cartes. Thème clair ou Forêt mémorisé pour chaque utilisateur.
--}}
@php
    use App\Support\MenuBackoffice;

    $user = Auth::user();
    $role = $user->codeRole();
    $menu = MenuBackoffice::pour($user);
    $badges = MenuBackoffice::badges($user);
    $c = __('admin.cadre');
    $theme = $user->theme_backoffice === 'foret' ? 'foret' : 'clair';
    $enAttente = count(\App\Support\ATraiter::pour($user));
    // Dix dernières notifications non lues et leur nombre total, en une seule requête (fenêtre calculée avant la limite).
    $notifications = $user->unreadNotifications()->latest()->limit(10)->select('notifications.*')->selectRaw('count(*) over () as total_non_lues')->get();
    $nonLues = (int) ($notifications->first()?->total_non_lues ?? 0);
    $initiales = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($m) => mb_strtoupper(mb_substr($m, 0, 1)))->implode('');
    // Groupe de la page courante, pour le fil d'Ariane.
    $groupeActif = collect($menu)->search(fn ($entrees) => collect($entrees)->contains(fn ($e) => MenuBackoffice::estActive($e)));
    // Version de recette : date du fichier statique (CSS, JS) modifié le plus récemment.
    $dates = array_map('filemtime', array_filter(array_map('public_path', ['css/app.css', 'css/admin.css', 'css/raaga.css', 'js/app.js']), 'is_file'));
    $version = ! app()->isProduction() && $dates !== [] ? date('d/m/Y', max($dates)) : null;
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
    <x-assets admin />
</head>
<body class="site-public bo adm theme-{{ $theme }}" data-layout="admin"
      x-data="cadreAdmin({ theme: @js($theme), urlTheme: @js(route('admin.preferences.theme')), urlLues: @js(route('admin.notifications.lues')) })"
      :class="{ 'theme-clair': theme !== 'foret', 'theme-foret': theme === 'foret' }">
    <div class="adm-bandeau" aria-hidden="true"></div>
    <a href="#contenu" class="sr-only focus:not-sr-only">{{ __('admin.cadre.aller_contenu') }}</a>

    <div class="adm-cadre" :class="{ 'adm-ferme': ! ouvert }">
        {{-- Barre latérale --}}
        <aside class="adm-lateral" id="menu-lateral" aria-label="{{ __('admin.menu_principal') }}">
            <a href="{{ route('admin.tableau') }}" class="adm-marque">
                <span class="adm-logo" aria-hidden="true">R</span>
                <span><span class="adm-marque-nom">{{ $c['nom'] }}</span><span class="adm-marque-sous">{{ $c['sous_titre'] }}</span></span>
            </a>

            <nav class="adm-nav" aria-label="{{ __('admin.menu_principal') }}">
                @foreach ($menu as $groupe => $entrees)
                    <p class="adm-groupe">{{ __('admin.groupes.'.$groupe) }}</p>
                    <ul>
                        @foreach (MenuBackoffice::regrouper($entrees, $user) as $element)
                            @if ($element['type'] === 'menu')
                                {{-- Menu dépliant (par exemple « Référentiels ») : ses sous-menus, ouvert sur l'un d'eux. --}}
                                <x-admin.menu-deroulant :cle="$element['cle']" :libelle="__('admin.menus_deroulants.'.$element['cle'])" :entrees="$element['entrees']"
                                                        :icone="MenuBackoffice::ICONES_MENUS[$element['cle']] ?? 'referentiels'"
                                                        :badges="$badges" :ouvert="MenuBackoffice::menuOuvert($user, $element['cle'], $element['entrees'])" />
                                @continue
                            @endif
                            @php
                                $entree = $element['entree'];
                                $actif = MenuBackoffice::estActive($entree);
                                $n = $badges[$entree['cle']] ?? 0;
                            @endphp
                            <li>
                                <a href="{{ MenuBackoffice::url($entree) }}" @class(['bo-lien', 'bo-lien-actif' => $actif]) @if ($actif) aria-current="page" @endif title="{{ __('admin.menu.'.$entree['cle']) }}">
                                    <x-icon :name="$entree['icone']" class="adm-icone-menu" />
                                    <span class="adm-libelle">{{ __('admin.menu.'.$entree['cle']) }}</span>
                                    @if ($n)
                                        <span class="adm-compteur">{{ $n }}<span class="sr-only"> {{ trans_choice('admin.a_traiter', $n, ['n' => $n]) }}</span></span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </nav>

            @if ($version)
                <p class="adm-version">{{ __('admin.cadre.version', ['date' => $version]) }}</p>
            @endif
        </aside>

        <div class="adm-principal">
            {{-- Barre du haut --}}
            <header class="adm-haut">
                <button type="button" class="adm-icone" @click="basculerMenu()" aria-controls="menu-lateral" :aria-expanded="ouvert ? 'true' : 'false'"
                        aria-label="{{ $c['menu'] }}" title="{{ $c['menu'] }}">
                    <x-icon name="menu" />
                </button>

                <form method="GET" action="{{ route('admin.recherche') }}" class="adm-recherche" role="search" x-data="rechercheGlobale"
                      @submit="memoriser()" @click.outside="ouverte = false" @keydown.escape="ouverte = false">
                    <label for="recherche-globale" class="sr-only">{{ $c['recherche'] }}</label>
                    <x-icon name="recherche" class="adm-recherche-icone" />
                    <input id="recherche-globale" name="q" type="search" x-ref="champ" value="{{ request()->routeIs('admin.recherche') ? request('q') : '' }}"
                           placeholder="{{ $c['recherche_aide'] }}" autocomplete="off" maxlength="100" @focus="ouverte = true">
                    <div class="adm-panneau" x-show="ouverte" x-cloak>
                        <p class="adm-panneau-titre">{{ $c['recherches_recentes'] }}</p>
                        <template x-for="terme in recentes" :key="terme">
                            <a class="adm-panneau-lien" :href="@js(route('admin.recherche')) + '?q=' + encodeURIComponent(terme)" x-text="terme"></a>
                        </template>
                        <p class="adm-panneau-vide" x-show="recentes.length === 0">{{ $c['aucune_recherche'] }}</p>
                    </div>
                </form>

                <div class="adm-outils">
                    @if ($enAttente > 0)
                        <a href="{{ route('admin.tableau') }}#a-traiter" class="adm-icone" title="{{ trans_choice('admin.cadre.actions_attente', $enAttente, ['n' => $enAttente]) }}">
                            <x-icon name="taches" />
                            <span class="adm-pastille adm-pastille-jaune" aria-hidden="true">{{ $enAttente }}</span>
                            <span class="sr-only">{{ trans_choice('admin.cadre.actions_attente', $enAttente, ['n' => $enAttente]) }}</span>
                        </a>
                    @endif

                    <div class="adm-deroulant" x-data="{ ouvert: false, nonLues: {{ $nonLues }} }" @click.outside="ouvert = false" @keydown.escape="ouvert = false">
                        <button type="button" class="adm-icone" @click="ouvert = ! ouvert" :aria-expanded="ouvert ? 'true' : 'false'" aria-haspopup="true"
                                title="{{ $c['notifications'] }}">
                            <x-icon name="cloche" />
                            @if ($nonLues)
                                <span class="adm-pastille adm-pastille-rouge" aria-hidden="true" x-show="nonLues > 0">{{ $nonLues }}</span>
                            @endif
                            <span class="sr-only">{{ $c['notifications'] }} : {{ trans_choice('admin.cadre.notifications_non_lues', $nonLues, ['n' => $nonLues]) }}</span>
                        </button>
                        <div class="adm-panneau" x-show="ouvert" x-cloak>
                            <p class="adm-panneau-titre">{{ $c['notifications'] }}</p>
                            @forelse ($notifications as $notification)
                                <a class="adm-panneau-lien" href="{{ $notification->data['url'] ?? '#' }}">{{ $notification->data['message'] ?? $notification->data['titre'] ?? '' }}</a>
                            @empty
                                <p class="adm-panneau-vide">{{ $c['aucune_notification'] }}</p>
                            @endforelse
                            @if ($nonLues)
                                <form method="POST" action="{{ route('admin.notifications.lues') }}" @submit.prevent="nonLues = 0; ouvert = false; marquerLues()">
                                    @csrf
                                    <button type="submit" class="adm-panneau-lien font-semibold">{{ $c['marquer_lu'] }}</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.preferences.theme') }}" @submit.prevent="basculerTheme()">
                        @csrf
                        <input type="hidden" name="theme" value="{{ $theme === 'foret' ? 'clair' : 'foret' }}" :value="theme === 'foret' ? 'clair' : 'foret'">
                        <button type="submit" class="adm-icone" :title="theme === 'foret' ? @js($c['theme_clair']) : @js($c['theme_foret'])"
                                title="{{ $theme === 'foret' ? $c['theme_clair'] : $c['theme_foret'] }}" :aria-pressed="theme === 'foret' ? 'true' : 'false'"
                                aria-pressed="{{ $theme === 'foret' ? 'true' : 'false' }}" aria-label="{{ $c['theme_foret'] }}">
                            <span x-show="theme !== 'foret'" @if ($theme === 'foret') x-cloak @endif><x-icon name="lune" /></span>
                            <span x-show="theme === 'foret'" @if ($theme !== 'foret') x-cloak @endif><x-icon name="soleil" /></span>
                        </button>
                    </form>

                    <div class="adm-deroulant" x-data="{ ouvert: false }" @click.outside="ouvert = false" @keydown.escape="ouvert = false">
                        <button type="button" class="adm-profil" @click="ouvert = ! ouvert" :aria-expanded="ouvert ? 'true' : 'false'" aria-haspopup="true"
                                aria-label="{{ $c['profil'] }} : {{ $user->name }}">
                            <span class="adm-avatar" aria-hidden="true">{{ $initiales }}</span>
                            <span class="adm-profil-texte"><span class="adm-profil-nom">{{ $user->name }}</span><span class="adm-profil-role">{{ $role->label() }}</span></span>
                        </button>
                        <div class="adm-panneau" x-show="ouvert" x-cloak>
                            <div class="adm-panneau-entete">
                                <span class="adm-profil-nom">{{ $user->name }}</span>
                                <span class="adm-profil-role">{{ $role->label() }}</span>
                            </div>
                            <a class="adm-panneau-lien" href="{{ route('admin.profil') }}">{{ $c['mon_profil'] }}</a>
                            <a class="adm-panneau-lien" href="{{ route('admin.profil') }}#preferences">{{ $c['preferences'] }}</a>
                            <form method="POST" action="{{ route('admin.deconnexion') }}">
                                @csrf
                                <button type="submit" class="adm-panneau-lien">{{ $c['deconnexion'] }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="contenu" class="adm-contenu" tabindex="-1">
                <nav aria-label="{{ __('admin.fil') }}" class="adm-fil">
                    <ol>
                        <li><a href="{{ route('admin.tableau') }}">{{ $c['racine'] }}</a></li>
                        @if ($groupeActif)
                            <li><span aria-hidden="true">›</span> <span>{{ __('admin.groupes.'.$groupeActif) }}</span></li>
                        @endif
                        @forelse ($fil as $element)
                            <li><span aria-hidden="true">›</span>
                                @if (! empty($element['url']) && ! $loop->last)
                                    <a href="{{ $element['url'] }}">{{ $element['libelle'] }}</a>
                                @else
                                    <span @if ($loop->last) aria-current="page" @endif>{{ $element['libelle'] }}</span>
                                @endif
                            </li>
                        @empty
                            <li><span aria-hidden="true">›</span> <span aria-current="page">{{ $titre }}</span></li>
                        @endforelse
                    </ol>
                </nav>

                <div class="adm-entete">
                    <div>
                        <h1 class="adm-titre bo-titre">{{ $titre }}</h1>
                        @if ($sousTitre)<p class="adm-sous-titre">{{ $sousTitre }}</p>@endif
                    </div>
                    @isset($actions)
                        <div class="adm-actions">{{ $actions }}</div>
                    @endisset
                </div>

                @if (session('succes'))
                    <x-admin.message type="ok">{{ session('succes') }}</x-admin.message>
                @endif
                @if (session('erreur'))
                    <x-admin.message type="erreur">{{ session('erreur') }}</x-admin.message>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
