{{-- Tableau de bord des profils sans espace dédié (agent de guichet, profils historiques). --}}
<x-public.layout :titre="__('public.tableau.titre')">
    <div class="conteneur max-w-2xl py-16 text-center">
        <p class="text-sm font-bold uppercase tracking-wide text-vert-fonce">{{ $role->label() }}</p>
        @if ($role === \App\Enums\Role::AgentGuichet)
            <h1 class="mt-3">{{ __('public.tableau.guichet_titre') }}</h1>
            <p class="mt-4 text-lg leading-relaxed">{{ __('public.tableau.guichet_message') }}</p>
        @else
            <h1 class="mt-3">{{ __('public.tableau.titre') }}</h1>
            <p class="mt-4 text-lg leading-relaxed">{{ __('public.tableau.bienvenue', ['nom' => Auth::user()->prenoms ?: Auth::user()->name]) }}</p>
            <p class="mt-2 text-gris">{{ __('public.tableau.aucun_service') }}</p>
        @endif
        <div class="mt-8 flex justify-center">
            <a href="{{ route('accueil') }}" class="btn-secondaire">{{ __('public.erreurs.retour_accueil') }}</a>
        </div>
    </div>
</x-public.layout>
