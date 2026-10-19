@php($entrees = \App\Support\MenuPublic::entrees())

<nav aria-label="{{ __('public.menu.libelle') }}" class="bg-vert-fonce">
    <ul id="menu-principal"
        class="conteneur hidden !px-0 py-1 tab:flex tab:flex-wrap tab:!px-4 tab:py-0"
        :class="menuOuvert ? '!block' : ''">
        @foreach ($entrees as $entree)
            @php($actif = $entree['actif'])
            <li>
                <a href="{{ route($entree['route']) }}"
                   @if ($actif) aria-current="page" @endif
                   @class([
                       'flex min-h-[48px] items-center border-l-4 px-4 font-semibold text-white no-underline hover:bg-white/10',
                       'tab:border-l-0 tab:border-b-[3px] tab:px-4',
                       'border-jaune bg-white/10' => $actif,
                       'border-transparent' => ! $actif,
                   ])>
                    @if (in_array($entree['cle'], ['investir', 'boutiques'], true))
                        <span class="menu-picto demarche-{{ $entree['cle'] }}" aria-hidden="true"><x-icon :name="\App\Support\Demarche::PICTOS[$entree['cle']]" class="h-4 w-4" /></span>
                    @endif
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
