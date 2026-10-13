@php
    // Chaque entrée est active si la route courante correspond à l'un de ses motifs.
    $entrees = [
        ['cle' => 'accueil', 'route' => 'accueil', 'motifs' => ['accueil', 'actualites.*']],
        ['cle' => 'offre', 'route' => 'offre', 'motifs' => ['offre']],
        ['cle' => 'souscrire', 'route' => 'souscrire', 'motifs' => ['souscrire', 'inscription', 'inscription.*', 'paiement', 'souscription.*']],
        ['cle' => 'transparence', 'route' => 'transparence', 'motifs' => ['transparence']],
        ['cle' => 'boutiques', 'route' => 'boutiques', 'motifs' => ['boutiques', 'boutiques.*']],
        ['cle' => 'espace', 'route' => auth()->check() ? 'espace' : 'connexion', 'motifs' => ['espace', 'espace.*', 'connexion', 'connexion.*']],
        ['cle' => 'faq', 'route' => 'faq', 'motifs' => ['faq']],
    ];
    // Phase 1 (boutiques et cautions uniquement) : pas d'entrées vers les pages des actions.
    if (! \App\Support\Phase::actionsPubliques()) {
        $entrees = array_values(array_filter($entrees, fn ($e) => ! in_array($e['cle'], ['offre', 'souscrire', 'transparence'], true)));
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
