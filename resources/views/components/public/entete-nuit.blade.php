{{--
    En-tête du thème « nuit » (maquettes docs/maquettes) : bandeau blanc, armoiries seules (aucun logo, décision de la hiérarchie), menu principal en ligne
    (pictogrammes des deux démarches), numéro vert (grand écran), bouton pilule « Mon espace » (connecté : prénom et menu
    du compte). Sous 760 px : armoiries, menu dans un panneau ouvert par le bouton burger.
--}}
@php($entrees = \App\Support\MenuPublic::entreesNuit())
<header class="nuit-entete" x-data="{ menuOuvert: false }" @keydown.escape="menuOuvert = false">
    <div class="conteneur nuit-entete-corps">
        <a href="{{ route('accueil') }}" class="nuit-entete-logo"
           aria-label="{{ __('public.entete.accueil', ['nom' => plateforme('identite.nomPlateforme')]) }}">
            <x-public.armoiries class="nuit-entete-armoiries" />
        </a>

        <nav aria-label="{{ __('public.menu.libelle') }}" class="nuit-menu" :class="menuOuvert ? 'est-ouvert' : ''">
            <ul id="menu-principal" class="nuit-menu-liste">
                @foreach ($entrees as $entree)
                    <li>
                        <a href="{{ route($entree['route']) }}" @class(['nuit-menu-lien', 'est-actif' => $entree['actif']])
                           @if ($entree['actif']) aria-current="page" @endif>
                            @if (in_array($entree['cle'], ['investir', 'boutiques'], true))
                                <span class="menu-picto demarche-{{ $entree['cle'] }}" aria-hidden="true"><x-icon :name="\App\Support\Demarche::PICTOS[$entree['cle']]" class="h-4 w-4" /></span>
                            @endif
                            {{ __('public.menu.'.$entree['cle']) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @if ($numeroVert = plateforme('contacts.numeroVert'))
            <a href="tel:{{ $numeroVert }}" class="nuit-entete-telephone" title="{{ __('public.communs.numero_vert') }}">
                <x-public.icone nom="telephone" class="h-5 w-5" />
                <span class="sr-only">{{ __('public.communs.numero_vert') }} :</span>
                <span>{{ \App\Support\Telephone::formaterLocal($numeroVert) }}</span>
            </a>
        @endif

        @auth
            <div class="nuit-entete-compte" x-data="{ ouvert: false }" @click.outside="ouvert = false" @keydown.escape.window="ouvert = false">
                <button type="button" class="nuit-pilule nuit-pilule-plein" @click="ouvert = ! ouvert"
                        :aria-expanded="ouvert.toString()" aria-expanded="false" aria-controls="menu-compte">
                    {{ Str::of(Auth::user()->prenoms ?: Auth::user()->name)->before(' ') }}
                    <x-public.icone nom="chevron" class="h-4 w-4 rotate-90" />
                </button>
                <ul id="menu-compte" x-show="ouvert" x-cloak aria-label="{{ __('public.entete.menu_compte') }}" class="nuit-menu-compte">
                    <li><a href="{{ route('espace') }}">{{ __('public.entete.mon_espace') }}</a></li>
                    @if ($profil = Auth::user()->estBackoffice() ? route('admin.profil') : (Auth::user()->isCitoyen() ? route('espace.profil') : null))
                        <li><a href="{{ $profil }}">{{ __('public.entete.mon_profil') }}</a></li>
                    @endif
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">{{ __('public.entete.se_deconnecter') }}</button>
                        </form>
                    </li>
                </ul>
            </div>
        @else
            <a href="{{ route('connexion') }}" class="nuit-pilule nuit-pilule-plein nuit-entete-compte">{{ __('public.entete.mon_espace') }}</a>
        @endauth

        <button type="button" class="nuit-burger" aria-controls="menu-principal" :aria-expanded="menuOuvert.toString()" aria-expanded="false"
                @click="menuOuvert = ! menuOuvert">
            <span class="sr-only" x-text="menuOuvert ? @js(__('public.entete.fermer_menu')) : @js(__('public.entete.ouvrir_menu'))">{{ __('public.entete.ouvrir_menu') }}</span>
            <x-public.icone nom="menu" class="h-6 w-6" x-show="! menuOuvert" />
            <x-public.icone nom="fermer" class="h-6 w-6" x-show="menuOuvert" x-cloak />
        </button>
    </div>
</header>
