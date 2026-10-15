@php
    // Chaque entrée est active si la route courante correspond à l'un de ses motifs.
    // Deux démarches distinctes : « Investir » (actions B) et « Boutiques ».
    $entrees = [
        ['cle' => 'accueil', 'route' => 'accueil', 'motifs' => ['accueil']],
        ['cle' => 'investir', 'route' => 'offre', 'motifs' => ['offre', 'transparence', 'actualites.*', 'souscrire', 'souscrire.*', 'souscription.*', 'paiement', 'paiement.*']],
        ['cle' => 'boutiques', 'route' => 'boutiques', 'motifs' => ['boutiques', 'boutiques.*']],
        ['cle' => 'espace', 'route' => auth()->check() ? 'espace' : 'connexion', 'motifs' => ['espace', 'espace.*', 'connexion', 'connexion.*']],
        ['cle' => 'faq', 'route' => 'faq', 'motifs' => ['faq']],
    ];
    // Présentation des actions non visible : seule la démarche « boutiques » est proposée.
    if (! \App\Support\Phase::presentationActions()) {
        $entrees = array_values(array_filter($entrees, fn ($e) => $e['cle'] !== 'investir'));
    }
@endphp

<nav aria-label="{{ __('public.menu.libelle') }}" class="bg-vert-fonce">
    <ul id="menu-principal"
        class="conteneur hidden !px-0 py-1 tab:flex tab:flex-wrap tab:!px-4 tab:py-0"
        :class="menuOuvert ? '!block' : ''">
        @foreach ($entrees as $entree)
            @php($actif = request()->routeIs(...$entree['motifs']))
            <li>
                <a href="{{ route($entree['route']) }}"
                   @if ($actif) aria-current="page" @endif
                   @class([
                       'flex min-h-[48px] items-center border-l-4 px-4 font-semibold text-white no-underline hover:bg-white/10',
                       'tab:border-l-0 tab:border-b-[3px] tab:px-4',
                       'border-jaune bg-white/10' => $actif,
                       'border-transparent' => ! $actif,
                   ])>
                    {{ __('public.menu.'.$entree['cle']) }}
                </a>
            </li>
        @endforeach
        @auth
            {{-- Mobile : la déconnexion n'est pas accessible ailleurs. --}}
            <li class="tab:hidden">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex min-h-[48px] w-full items-center border-l-4 border-transparent px-4 text-left font-semibold text-white hover:bg-white/10">
                        {{ __('public.entete.se_deconnecter') }}
                    </button>
                </form>
            </li>
        @endauth
    </ul>
</nav>
